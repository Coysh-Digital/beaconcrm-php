<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Resource;

use CoyshDigital\Beacon\Exception\InvalidPayloadException;
use CoyshDigital\Beacon\Http\Request;
use CoyshDigital\Beacon\Http\Response;
use CoyshDigital\Beacon\Http\Transport;
use CoyshDigital\Beacon\Payload\EntityPayload;
use CoyshDigital\Beacon\Schema\EntityType;

/**
 * Records of one Beacon record type.
 *
 * Every method that sends has a `…Request()` twin returning the unsent
 * {@see Request}, for hosts that need to do their own sending.
 *
 * Endpoint confidence: create, read and upsert are confirmed against Beacon's
 * account documentation. Update, delete, list and search follow REST convention
 * and Beacon's general shape but are not in any documentation available when
 * this was written — they are marked `@experimental` and may need adjusting
 * against your account's generated docs. {@see \CoyshDigital\Beacon\BeaconClient::request()}
 * is the escape hatch until then.
 */
final class Entities
{
    private ?EntityType $schema = null;

    public function __construct(
        private readonly Transport $transport,
        public readonly string $typeKey,
    ) {
    }

    /**
     * Attaches the record type's schema, so payloads built here are shaped
     * against it.
     */
    public function withSchema(?EntityType $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * A payload builder bound to this record type's schema, when one has been
     * attached.
     */
    public function payload(): EntityPayload
    {
        return EntityPayload::for($this->schema);
    }

    // Create
    // =========================================================================

    /**
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function create(array|EntityPayload $entity): Response
    {
        return $this->transport->send($this->createRequest($entity));
    }

    /**
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function createRequest(array|EntityPayload $entity): Request
    {
        return new Request('POST', $this->endpoint(), body: $this->body($entity));
    }

    // Read
    // =========================================================================

    public function read(int|string $id, ?bool $populate = null, ?bool $archived = null): Response
    {
        return $this->transport->send($this->readRequest($id, $populate, $archived));
    }

    /**
     * `populate` controls whether linked-record data is returned alongside the
     * record; pass false for large exports where only IDs are needed.
     */
    public function readRequest(int|string $id, ?bool $populate = null, ?bool $archived = null): Request
    {
        return new Request('GET', $this->endpoint($id), self::flags($populate, $archived));
    }

    // Update
    // =========================================================================

    /**
     * @experimental The update endpoint is inferred from REST convention, not
     *               from Beacon documentation. Verify against your account's
     *               generated docs before relying on it; upsert() is the
     *               documented way to change an existing record.
     *
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function update(int|string $id, array|EntityPayload $entity): Response
    {
        return $this->transport->send($this->updateRequest($id, $entity));
    }

    /**
     * @experimental See update().
     *
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function updateRequest(int|string $id, array|EntityPayload $entity): Request
    {
        return new Request('PUT', $this->endpoint($id), body: $this->body($entity));
    }

    // Delete
    // =========================================================================

    /**
     * @experimental The delete endpoint is inferred from REST convention, not
     *               from Beacon documentation. Beacon's own model is archiving
     *               rather than deletion, so check what this actually does on a
     *               test record before running it over real data.
     */
    public function delete(int|string $id): Response
    {
        return $this->transport->send($this->deleteRequest($id));
    }

    /**
     * @experimental See delete().
     */
    public function deleteRequest(int|string $id): Request
    {
        return new Request('DELETE', $this->endpoint($id));
    }

    // Upsert
    // =========================================================================

    /**
     * Creates a record, or updates the one whose `$primaryFieldKey` matches.
     *
     * The lookup field must be genuinely unique in the account — an email
     * address usually is, but is also mutable, so a stable legacy or external
     * ID is a better key for migrations and repeatable imports.
     *
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function upsert(string $primaryFieldKey, array|EntityPayload $entity): Response
    {
        return $this->transport->send($this->upsertRequest($primaryFieldKey, $entity));
    }

    /**
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function upsertRequest(string $primaryFieldKey, array|EntityPayload $entity): Request
    {
        $body = $this->body($entity);

        // Beacon matches on `primary_field_key`, so that key must also carry a
        // value inside the entity itself. Without it nothing can match and
        // every submission creates another record.
        if (!array_key_exists($primaryFieldKey, $body)) {
            throw new InvalidPayloadException(sprintf(
                'Upsert key "%s" has no value in the %s payload, so no record can be matched.',
                $primaryFieldKey,
                $this->typeKey,
            ));
        }

        return new Request('PUT', $this->endpoint() . '/upsert', body: [
            'primary_field_key' => $primaryFieldKey,
            'entity' => $body,
        ]);
    }

    // List and search
    // =========================================================================

    /**
     * @experimental The list endpoint and its pagination parameters are
     *               inferred; confirm the parameter names against your
     *               account's generated docs.
     *
     * @param array<string, scalar|null> $query
     */
    public function list(array $query = [], ?bool $populate = null, ?bool $archived = null): Response
    {
        return $this->transport->send($this->listRequest($query, $populate, $archived));
    }

    /**
     * @experimental See list().
     *
     * @param array<string, scalar|null> $query
     */
    public function listRequest(array $query = [], ?bool $populate = null, ?bool $archived = null): Request
    {
        return new Request('GET', $this->endpoint(), array_merge(self::flags($populate, $archived), $query));
    }

    /**
     * @experimental Beacon documents a filtering system but not its API request
     *               shape, so both the endpoint and the body are inferred.
     *               Verify before relying on it.
     *
     * @param array<string, mixed> $filter
     * @param array<string, scalar|null> $query
     */
    public function search(array $filter, array $query = [], ?bool $populate = null): Response
    {
        return $this->transport->send($this->searchRequest($filter, $query, $populate));
    }

    /**
     * @experimental See search().
     *
     * @param array<string, mixed> $filter
     * @param array<string, scalar|null> $query
     */
    public function searchRequest(array $filter, array $query = [], ?bool $populate = null): Request
    {
        return new Request(
            'POST',
            $this->endpoint() . '/search',
            array_merge(self::flags($populate, null), $query),
            ['filter' => $filter],
        );
    }

    // Internals
    // =========================================================================

    private function endpoint(int|string|null $id = null): string
    {
        $endpoint = 'entity/' . rawurlencode($this->typeKey);

        return $id === null ? $endpoint : $endpoint . '/' . rawurlencode((string)$id);
    }

    /**
     * @param array<string, mixed>|EntityPayload $entity
     * @return array<string, mixed>
     */
    private function body(array|EntityPayload $entity): array
    {
        $body = $entity instanceof EntityPayload ? $entity->build() : $entity;

        if ($body === []) {
            throw new InvalidPayloadException(sprintf('No values to send for record type "%s".', $this->typeKey));
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private static function flags(?bool $populate, ?bool $archived): array
    {
        $query = [];

        if ($populate !== null) {
            $query['populate'] = $populate ? 'true' : 'false';
        }

        if ($archived !== null) {
            $query['archived'] = $archived ? 'true' : 'false';
        }

        return $query;
    }
}
