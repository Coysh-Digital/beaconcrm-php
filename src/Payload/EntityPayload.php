<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Payload;

use CoyshDigital\Beacon\Schema\EntityType;
use CoyshDigital\Beacon\Schema\Field;
use CoyshDigital\Beacon\Schema\FieldType;

/**
 * Builds an entity body from plain values, shaping each one against the
 * account's schema.
 *
 * Values are addressed by field key. Structured person names are addressed one
 * part at a time — `name:first`, `name:last` — and reassembled into a single
 * object on build, which lets a flat mapping UI drive a nested payload.
 *
 * ```php
 * $body = EntityPayload::for($personType)
 *     ->set('name:first', 'Alex')
 *     ->set('name:last', 'Rivera')
 *     ->set('emails', 'alex@example.org')
 *     ->build();
 * ```
 */
final class EntityPayload
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @param (callable(string): ?FieldType)|null $typeResolver
     */
    private function __construct(private $typeResolver)
    {
    }

    /**
     * Shapes values against a record type's schema. With no schema, values are
     * sent exactly as given.
     */
    public static function for(?EntityType $entityType = null): self
    {
        if ($entityType === null) {
            return new self(null);
        }

        return new self(static fn(string $key): ?FieldType => $entityType->field($key)?->type);
    }

    /**
     * Shapes values using a callback that maps a field key to its Beacon type.
     *
     * For hosts that keep their own flattened copy of the schema — a form
     * builder storing one mapping row per field, say — and would otherwise have
     * to re-fetch the whole thing to shape a payload correctly.
     *
     * ```php
     * EntityPayload::resolvedBy(
     *     fn(string $key): ?FieldType => FieldType::tryFromName($storedTypes[$key] ?? null),
     * );
     * ```
     *
     * @param callable(string): ?FieldType $typeResolver
     */
    public static function resolvedBy(callable $typeResolver): self
    {
        return new self($typeResolver);
    }

    /**
     * Sets one value. Empty values are dropped, so a blank optional field is
     * simply not sent rather than blanking what Beacon already holds.
     */
    public function set(string $handle, mixed $value): self
    {
        if (ValueShaper::isEmpty($value)) {
            return $this;
        }

        $this->values[$handle] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values): self
    {
        foreach ($values as $handle => $value) {
            $this->set($handle, $value);
        }

        return $this;
    }

    public function has(string $handle): bool
    {
        return array_key_exists($handle, $this->values);
    }

    /**
     * Whether a value has been set for a field, whether it was set whole or
     * through one of its parts. Useful for checking an upsert key is present.
     */
    public function hasField(string $key): bool
    {
        if ($this->has($key)) {
            return true;
        }

        $prefix = $key . Field::PART_SEPARATOR;

        foreach (array_keys($this->values) as $handle) {
            if (str_starts_with($handle, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * The entity body, ready to send.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $payload = [];
        $nameParts = [];

        foreach ($this->values as $handle => $value) {
            if (str_contains($handle, Field::PART_SEPARATOR)) {
                [$key, $part] = explode(Field::PART_SEPARATOR, $handle, 2);
                $nameParts[$key][$part] = $value;

                continue;
            }

            $payload[$handle] = ValueShaper::shapeForType($this->resolveType($handle), $value);
        }

        foreach ($nameParts as $key => $parts) {
            $payload[$key] = ValueShaper::assembleName($parts);
        }

        return $payload;
    }

    private function resolveType(string $key): ?FieldType
    {
        return $this->typeResolver === null ? null : ($this->typeResolver)($key);
    }
}
