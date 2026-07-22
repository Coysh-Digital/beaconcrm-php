<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Resource;

use CoyshDigital\Beacon\Http\Request;
use CoyshDigital\Beacon\Http\Transport;
use CoyshDigital\Beacon\Schema\EntityType;

/**
 * The account's schema.
 *
 * A single call returns every record type with each field's type, label,
 * drop-down options and cardinality — which is what makes it possible to work
 * against any Beacon account without hard-coding a thing.
 */
final class EntityTypes
{
    public const ENDPOINT = 'entity_types';

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Every record type in the account, sorted by label.
     *
     * @return list<EntityType>
     */
    public function all(): array
    {
        return EntityType::listFromResponse($this->transport->send(self::listRequest())->toArray());
    }

    /**
     * One record type by its key, or null if the account has no such type.
     */
    public function get(string $key): ?EntityType
    {
        foreach ($this->all() as $entityType) {
            if ($entityType->key === $key) {
                return $entityType;
            }
        }

        return null;
    }

    /**
     * The schema call, unsent — also the cheapest way to verify credentials.
     */
    public static function listRequest(): Request
    {
        return new Request('GET', self::ENDPOINT);
    }
}
