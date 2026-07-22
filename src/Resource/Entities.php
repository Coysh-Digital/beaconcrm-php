<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Resource;

use CoyshDigital\Beacon\Exception\BeaconException;
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
 * Create, read, update, upsert and list have all been exercised against a live
 * Beacon account. Delete has not — see {@see delete()}. Beacon has no search or
 * filter endpoint that could be found; filter a list client-side, or use
 * {@see \CoyshDigital\Beacon\BeaconClient::request()} if your account exposes
 * something this library does not model.
 */
final class Entities
{
    private ?EntityType $schema = null;

    public function __construct(
        private readonly ?Transport $transport,
        public readonly string $typeKey,
    ) {
    }

    /**
     * A record type that can describe requests but not send them.
     *
     * For hosts that do their own HTTP — call the `…Request()` methods and hand
     * the result to whatever does the sending. Calling a sending method on one
     * of these throws.
     */
    public static function describe(string $typeKey, ?EntityType $schema = null): self
    {
        return (new self(null, $typeKey))->withSchema($schema);
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
        return $this->transport()->send($this->createRequest($entity));
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
        return $this->transport()->send($this->readRequest($id, $populate, $archived));
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
     * Updates one record, leaving any field not in the payload untouched.
     *
     * Note the verb: Beacon takes a PATCH here and answers `PUT` with a 404.
     *
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function update(int|string $id, array|EntityPayload $entity): Response
    {
        return $this->transport()->send($this->updateRequest($id, $entity));
    }

    /**
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function updateRequest(int|string $id, array|EntityPayload $entity): Request
    {
        return new Request('PATCH', $this->endpoint($id), body: $this->body($entity));
    }

    // Delete
    // =========================================================================

    /**
     * @experimental This is the one endpoint here that has not been confirmed
     *               against a live account, because confirming it means
     *               destroying a record. Beacon's own model is archiving rather
     *               than deletion, and the sibling endpoints did not all follow
     *               REST convention — update needed PATCH, not PUT — so do not
     *               assume this path is right. Try it on a throwaway record
     *               first.
     */
    public function delete(int|string $id): Response
    {
        return $this->transport()->send($this->deleteRequest($id));
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
        return $this->transport()->send($this->upsertRequest($primaryFieldKey, $entity));
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

    // List
    // =========================================================================

    /**
     * Lists records of this type, newest first.
     *
     * Note the endpoint: listing lives at the **plural** `entities/{type}`,
     * while every single-record operation is at `entity/{type}`. Beacon answers
     * a GET to `entity/{type}` with a permissions error rather than a list.
     *
     * The response carries `total` alongside the page of results, and each
     * result is an `{entity, references}` envelope — {@see Response::entities()}
     * unwraps them.
     *
     * Pass `populate: false` for large exports; linked-record data makes the
     * response substantially bigger.
     *
     * @param array<string, scalar|null> $query
     */
    public function list(
        int $page = 1,
        ?int $perPage = null,
        ?bool $populate = null,
        ?bool $archived = null,
        array $query = [],
    ): Response {
        return $this->transport()->send($this->listRequest($page, $perPage, $populate, $archived, $query));
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function listRequest(
        int $page = 1,
        ?int $perPage = null,
        ?bool $populate = null,
        ?bool $archived = null,
        array $query = [],
    ): Request {
        $params = ['page' => $page];

        // Beacon's own default page size is 200.
        if ($perPage !== null) {
            $params['per_page'] = $perPage;
        }

        return new Request(
            'GET',
            'entities/' . rawurlencode($this->typeKey),
            array_merge($params, self::flags($populate, $archived), $query),
        );
    }

    /**
     * Every record of this type, fetched a page at a time.
     *
     * Yields one record per iteration, so a large account can be walked without
     * holding it all in memory. Mind the rate limit — 300 requests a minute —
     * and prefer a large `$perPage` over a small one.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function each(int $perPage = 200, ?bool $populate = null, ?bool $archived = null): \Generator
    {
        $page = 1;
        $seen = 0;

        do {
            $response = $this->list($page, $perPage, $populate, $archived);
            $entities = $response->entities();

            foreach ($entities as $entity) {
                $seen++;

                yield $entity;
            }

            $page++;
        } while ($entities !== [] && $seen < $response->total());
    }

    // Internals
    // =========================================================================

    private function transport(): Transport
    {
        if ($this->transport === null) {
            throw new BeaconException(sprintf(
                'This "%s" resource can only describe requests, not send them. Use the …Request() methods, or build one from a BeaconClient.',
                $this->typeKey,
            ));
        }

        return $this->transport;
    }

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
