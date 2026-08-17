<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Schema;

/**
 * One record type in a Beacon account — Person, Organisation, or any custom
 * type the account has created — together with its fields.
 *
 * The `key` is what goes in an entity URL: `entity/{key}`.
 */
final class EntityType
{
    /**
     * @param array<string, Field> $fields Keyed by field key.
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        private readonly array $fields,
        public readonly ?int $id = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data One entry from the `results` array of
     *                                   an `entity_types` response.
     * @param array<int, string> $typeKeysById Record type keys by numeric ID, so
     *                                         a record link can name the type it
     *                                         points at. Only
     *                                         {@see listFromResponse()} sees the
     *                                         whole account, so only it can
     *                                         supply this.
     */
    public static function fromArray(array $data, array $typeKeysById = []): ?self
    {
        $key = $data['key'] ?? null;

        if (!is_string($key) || $key === '') {
            return null;
        }

        $label = $data['label'] ?? null;
        $id = $data['id'] ?? null;
        $rawFields = $data['fields'] ?? [];
        $fields = [];

        if (is_array($rawFields)) {
            foreach ($rawFields as $rawField) {
                if (!is_array($rawField)) {
                    continue;
                }

                $field = Field::fromArray($rawField, $typeKeysById);

                if ($field !== null) {
                    $fields[$field->key] = $field;
                }
            }
        }

        return new self(
            key: $key,
            label: is_string($label) && $label !== '' ? $label : $key,
            fields: $fields,
            id: is_numeric($id) ? (int)$id : null,
        );
    }

    /**
     * Every record type in an account, parsed from a whole `entity_types`
     * response and sorted by label.
     *
     * Beacon returns them in an arbitrary order that puts custom types before
     * Person, which reads oddly in any list built from them.
     *
     * Parsed in two passes: a record link names its target types by numeric ID
     * rather than by key, so every ID in the account has to be known before any
     * field can say what it points at.
     *
     * @param array<string, mixed> $response
     * @return list<self>
     */
    public static function listFromResponse(array $response): array
    {
        $results = $response['results'] ?? [];

        if (!is_array($results)) {
            return [];
        }

        $typeKeysById = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $id = $result['id'] ?? null;
            $key = $result['key'] ?? null;

            if (is_numeric($id) && is_string($key) && $key !== '') {
                $typeKeysById[(int)$id] = $key;
            }
        }

        $types = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $type = self::fromArray($result, $typeKeysById);

            if ($type !== null) {
                $types[] = $type;
            }
        }

        usort($types, static fn(self $a, self $b): int => strcasecmp($a->label, $b->label));

        return $types;
    }

    /**
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $key): ?Field
    {
        // A part handle such as `name:first` addresses the field it belongs to.
        if (str_contains($key, Field::PART_SEPARATOR)) {
            [$key] = explode(Field::PART_SEPARATOR, $key, 2);
        }

        return $this->fields[$key] ?? null;
    }

    public function hasField(string $key): bool
    {
        return $this->field($key) !== null;
    }

    /**
     * Fields Beacon will accept a write to.
     *
     * @return array<string, Field>
     */
    public function writableFields(): array
    {
        return array_filter($this->fields, static fn(Field $field): bool => $field->isWritable());
    }

    /**
     * Fields that can be written from a single plain value — the set worth
     * offering in a field-mapping UI.
     *
     * @return array<string, Field>
     */
    public function mappableFields(): array
    {
        return array_filter($this->fields, static fn(Field $field): bool => $field->isMappable());
    }
}
