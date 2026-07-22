<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Fixtures;

use CoyshDigital\Beacon\Schema\EntityType;
use RuntimeException;

/**
 * Loads the sample schema shared by the tests.
 *
 * The schema is invented — no real account's field keys or drop-down values
 * appear anywhere in this repository.
 */
final class Fixture
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $path = __DIR__ . '/' . $name . '.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Missing fixture: ' . $path);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public static function supporterType(): EntityType
    {
        foreach (EntityType::listFromResponse(self::json('entity_types')) as $entityType) {
            if ($entityType->key === 'supporter') {
                return $entityType;
            }
        }

        throw new RuntimeException('The supporter record type is missing from the fixture.');
    }
}
