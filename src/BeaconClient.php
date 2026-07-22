<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon;

use CoyshDigital\Beacon\Http\Request;
use CoyshDigital\Beacon\Http\Response;
use CoyshDigital\Beacon\Http\RetryPolicy;
use CoyshDigital\Beacon\Http\Transport;
use CoyshDigital\Beacon\Resource\Entities;
use CoyshDigital\Beacon\Resource\EntityTypes;
use CoyshDigital\Beacon\Resource\Exports;
use CoyshDigital\Beacon\Schema\EntityType;
use CoyshDigital\Beacon\Exception\BeaconException;
use GuzzleHttp\ClientInterface;

/**
 * The entry point for talking to one Beacon CRM account.
 *
 * ```php
 * $beacon = BeaconClient::make($accountId, $apiKey);
 *
 * $person = $beacon->entities('person');
 * $id = $person->create(
 *     $person->payload()
 *         ->set('name:first', 'Alex')
 *         ->set('name:last', 'Rivera')
 *         ->set('emails', 'alex@example.org')
 * )->entityId();
 * ```
 *
 * Nothing about any account's schema is hard-coded: record types and fields are
 * read from the account itself through {@see entityTypes()}.
 */
final class BeaconClient
{
    private readonly Transport $transport;

    private ?EntityTypes $entityTypes = null;

    private ?Exports $exports = null;

    /** @var array<string, EntityType> */
    private array $schemaCache = [];

    public function __construct(
        public readonly Config $config,
        ?ClientInterface $httpClient = null,
    ) {
        $this->transport = new Transport($config, $httpClient);
    }

    /**
     * The usual way to build a client.
     *
     * Read both values from the environment — a Beacon key is shown only once
     * and grants full access to the account, so it does not belong in code or
     * in version-controlled config.
     */
    public static function make(
        string $accountId,
        string $apiKey,
        ?RetryPolicy $retryPolicy = null,
        ?ClientInterface $httpClient = null,
    ): self {
        return new self(new Config($accountId, $apiKey, retryPolicy: $retryPolicy), $httpClient);
    }

    /**
     * The account's schema.
     */
    public function entityTypes(): EntityTypes
    {
        return $this->entityTypes ??= new EntityTypes($this->transport);
    }

    /**
     * Records of one record type, e.g. `person` or a custom type's key.
     *
     * Pass an EntityType, or call `withSchema()` afterwards, to have payloads
     * shaped against that type's fields.
     */
    public function entities(string|EntityType $type): Entities
    {
        $key = $type instanceof EntityType ? $type->key : $type;
        $entities = new Entities($this->transport, $key);

        return $type instanceof EntityType
            ? $entities->withSchema($type)
            : $entities->withSchema($this->schemaCache[$key] ?? null);
    }

    /**
     * Records of one record type, with that type's schema already loaded, so
     * values are shaped correctly without the caller fetching it first.
     *
     * The schema is fetched once per client and reused.
     */
    public function entitiesWithSchema(string $typeKey): Entities
    {
        if (!isset($this->schemaCache[$typeKey])) {
            $schema = $this->entityTypes()->get($typeKey);

            if ($schema === null) {
                throw new BeaconException(sprintf('This account has no record type with the key "%s".', $typeKey));
            }

            $this->schemaCache[$typeKey] = $schema;
        }

        return $this->entities($this->schemaCache[$typeKey]);
    }

    public function exports(): Exports
    {
        return $this->exports ??= new Exports($this->transport);
    }

    /**
     * Whether the credentials work.
     *
     * Reads the schema, which is the cheapest call that proves the account ID,
     * the key and the required headers are all right.
     */
    public function ping(): bool
    {
        try {
            $this->transport->send(EntityTypes::listRequest());
        } catch (BeaconException) {
            return false;
        }

        return true;
    }

    /**
     * The escape hatch: send anything, to any path under the account.
     *
     * Beacon generates its API documentation per account, so an account may
     * expose endpoints this library does not model. Paths are relative to
     * `…/v1/account/{accountId}/`.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        return $this->send(new Request($method, $path, $query, $body));
    }

    public function send(Request $request): Response
    {
        return $this->transport->send($request);
    }
}
