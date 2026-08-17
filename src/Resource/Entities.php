<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Resource;

use CoyshDigital\Beacon\Exception\BeaconException;
use CoyshDigital\Beacon\Exception\InvalidPayloadException;
use CoyshDigital\Beacon\Http\Request;
use CoyshDigital\Beacon\Http\Response;
use CoyshDigital\Beacon\Http\Transport;
use CoyshDigital\Beacon\Payload\EntityPayload;
use CoyshDigital\Beacon\Payload\ValueShaper;
use CoyshDigital\Beacon\Schema\EntityType;
use CoyshDigital\Beacon\Schema\FieldType;

/**
 * Records of one Beacon record type.
 *
 * Every single-request method has a `…Request()` twin returning the unsent
 * {@see Request}, for hosts that need to do their own sending. The linking and
 * lookup helpers — {@see link()}, {@see unlink()}, {@see findBy()} — have no
 * twin, because each is several requests rather than one.
 *
 * Create, read, update, upsert and list have all been exercised against a live
 * Beacon account. Beacon has no search or filter endpoint that could be found:
 * filter a list client-side, or use
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

    // Linking
    // =========================================================================

    /**
     * The record IDs a link field currently holds.
     *
     * @return list<int>
     */
    public function links(int|string $id, string $fieldKey): array
    {
        return ValueShaper::referenceIds($this->read($id, populate: false)->entity()[$fieldKey] ?? null);
    }

    /**
     * Adds records to a link field, keeping the links already there.
     *
     * This is why the method exists. A write to a link field **replaces** the
     * whole list rather than appending to it, so the obvious
     * `update($id, ['admins' => [$personId]])` quietly drops every other admin.
     * Adding one safely means reading the list, merging, and sending it back
     * whole — which is what happens here.
     *
     * Returns null when every ID was already linked, so nothing was sent.
     *
     * @param int|list<int|string> $linkIds
     */
    public function link(int|string $id, string $fieldKey, int|array $linkIds): ?Response
    {
        $existing = $this->links($id, $fieldKey);
        $merged = $existing;

        foreach (ValueShaper::shapeReference($linkIds) as $linkId) {
            if (!in_array($linkId, $merged, true)) {
                $merged[] = $linkId;
            }
        }

        return $merged === $existing ? null : $this->replaceLinks($id, $fieldKey, $merged);
    }

    /**
     * Removes records from a link field, leaving the rest in place.
     *
     * Returns null when none of the IDs was linked, so nothing was sent.
     *
     * @param int|list<int|string> $linkIds
     */
    public function unlink(int|string $id, string $fieldKey, int|array $linkIds): ?Response
    {
        $existing = $this->links($id, $fieldKey);
        $remove = ValueShaper::shapeReference($linkIds);

        $remaining = array_values(array_filter(
            $existing,
            static fn(int $linkId): bool => !in_array($linkId, $remove, true),
        ));

        return $remaining === $existing ? null : $this->replaceLinks($id, $fieldKey, $remaining);
    }

    /**
     * Sets a link field to exactly these records, dropping any others.
     *
     * @param int|list<int|string> $linkIds
     */
    public function setLinks(int|string $id, string $fieldKey, int|array $linkIds): Response
    {
        return $this->replaceLinks($id, $fieldKey, ValueShaper::shapeReference($linkIds));
    }

    // Lookup
    // =========================================================================

    /**
     * The first record whose `$fieldKey` matches `$value`, or null.
     *
     * Beacon has no search endpoint, so this pages the whole record type and
     * compares client-side: one request per 200 records, against a rate limit
     * of 300 a minute. On a type holding 5,000 records a miss costs 25
     * requests. Prefer {@see resolveId()}, which asks Beacon to do the matching
     * in a single request.
     *
     * Comparison is case-insensitive for strings, since the point is usually to
     * match a value someone typed rather than one held internally. Contact-point fields are compared against
     * the value inside the object — a stored email is
     * `[{"email": "…"}]`, never a bare string.
     *
     * @param int|null $limit Stop after this many records. Null walks them all.
     * @return array<string, mixed>|null
     */
    public function findBy(string $fieldKey, mixed $value, ?int $limit = null): ?array
    {
        if (ValueShaper::isEmpty($value)) {
            return null;
        }

        $type = $this->schema?->field($fieldKey)?->type;
        $scanned = 0;

        foreach ($this->each(perPage: 200, populate: false) as $entity) {
            if (self::valueMatches($entity[$fieldKey] ?? null, $value, $type)) {
                return $entity;
            }

            if ($limit !== null && ++$scanned >= $limit) {
                break;
            }
        }

        return null;
    }

    /**
     * The ID of the record matching `$matchFieldKey`, creating it if there is
     * none.
     *
     * One request, and the cheap answer to "I have a church name and need a
     * record ID". It is an upsert, so the same rules apply: the match field has
     * to be genuinely unique, and has to carry a value in the payload.
     *
     * Note the cost of getting the match field wrong — every call creates
     * another record rather than matching. Use {@see findBy()} instead when
     * creating must not happen.
     *
     * @param array<string, mixed>|EntityPayload $entity
     */
    public function resolveId(string $matchFieldKey, array|EntityPayload $entity): ?int
    {
        return $this->upsert($matchFieldKey, $entity)->entityId();
    }

    // Internals
    // =========================================================================

    /**
     * Writes a link field's whole list.
     *
     * Sent as a raw body rather than through {@see EntityPayload}, which drops
     * an empty array as an empty value — so building the payload that way would
     * make it impossible to remove the last link.
     *
     * @param list<int> $linkIds
     */
    private function replaceLinks(int|string $id, string $fieldKey, array $linkIds): Response
    {
        return $this->transport()->send(new Request(
            'PATCH',
            $this->endpoint($id),
            body: [$fieldKey => $linkIds],
        ));
    }

    /**
     * Whether a stored value matches what was searched for.
     */
    private static function valueMatches(mixed $stored, mixed $value, ?FieldType $type): bool
    {
        foreach (self::comparableValues($stored, $type) as $candidate) {
            if (is_string($candidate) && is_string($value)) {
                if (strcasecmp($candidate, $value) === 0) {
                    return true;
                }

                continue;
            }

            // Loose on purpose: an ID read back as an int should match the
            // same ID given as a string.
            if ($candidate == $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The scalars worth comparing inside a stored value.
     *
     * Emails, phones and addresses are contact points — lists of objects — so
     * the comparable value is inside the object, under a key that differs per
     * type.
     *
     * @return list<mixed>
     */
    private static function comparableValues(mixed $stored, ?FieldType $type): array
    {
        if ($stored === null) {
            return [];
        }

        if (!is_array($stored)) {
            return [$stored];
        }

        $keys = match ($type) {
            FieldType::Email => ['email'],
            FieldType::Phone => ['number'],
            // Unknown type: try both, so a lookup still works when no schema is
            // attached.
            default => ['email', 'number', 'id'],
        };

        $values = [];

        foreach ($stored as $item) {
            if (!is_array($item)) {
                $values[] = $item;

                continue;
            }

            foreach ($keys as $key) {
                if (isset($item[$key])) {
                    $values[] = $item[$key];

                    break;
                }
            }
        }

        return $values;
    }

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
