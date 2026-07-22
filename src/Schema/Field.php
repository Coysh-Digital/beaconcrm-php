<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Schema;

/**
 * One field on a Beacon record type, as described by the account's own schema.
 *
 * Nothing here is hard-coded per account: every property comes from the
 * `entity_types` response, so custom `c_*` fields are first-class.
 */
final class Field
{
    /**
     * The parts of a person-name object, in the order Beacon presents them.
     *
     * @var list<string>
     */
    public const NAME_PARTS = ['full', 'first', 'last', 'middle', 'prefix'];

    /**
     * Separator between a field key and a sub-part, used to address one piece
     * of a structured field with a single flat handle — `name:first`.
     */
    public const PART_SEPARATOR = ':';

    /**
     * @param array<string, mixed> $metadata Beacon's per-type extras, e.g. a
     *                                       drop-down's `options` or a date's
     *                                       `include_time`.
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?FieldType $type,
        public readonly string $rawType,
        public readonly bool $isReadOnly,
        public readonly bool $isSmartField,
        public readonly bool $isRollupField,
        public readonly array $metadata,
    ) {
    }

    /**
     * @param array<string, mixed> $data One entry from an entity type's
     *                                   `fields` array.
     */
    public static function fromArray(array $data): ?self
    {
        $key = $data['key'] ?? null;
        $rawType = $data['type'] ?? null;

        // A field with no key or type cannot be mapped or written, so it is
        // dropped rather than represented in a half-usable state.
        if (!is_string($key) || $key === '' || !is_string($rawType) || $rawType === '') {
            return null;
        }

        $label = $data['label'] ?? null;
        $metadata = $data['metadata'] ?? [];

        return new self(
            key: $key,
            label: is_string($label) && $label !== '' ? $label : $key,
            type: FieldType::tryFromName($rawType),
            rawType: $rawType,
            isReadOnly: (bool)($data['is_read_only'] ?? false),
            isSmartField: (bool)($data['is_smart_field'] ?? false),
            isRollupField: (bool)($data['is_rollup_field'] ?? false),
            metadata: is_array($metadata) ? $metadata : [],
        );
    }

    /**
     * Whether Beacon will accept a write to this field.
     *
     * Smart fields, rollups and auto-increments are computed by Beacon and
     * rejected on write, which covers a lot of useful-looking fields such as
     * totals, counts and percentages derived from other records.
     */
    public function isWritable(): bool
    {
        return !$this->isReadOnly && !$this->isSmartField && !$this->isRollupField;
    }

    /**
     * Whether this field can be written from a single plain value.
     *
     * False for file, user and location fields, which need more than a payload
     * value can carry. See FieldType::isWritableFromScalar().
     */
    public function isMappable(): bool
    {
        return $this->isWritable()
            && ($this->type?->isWritableFromScalar() ?? true);
    }

    /**
     * Whether the field holds more than one value, per its Beacon
     * configuration. Note that drop-downs and record links are sent as arrays
     * either way — see FieldType::isAlwaysArray().
     */
    public function allowsMultiple(): bool
    {
        return (bool)($this->metadata['allow_multiple'] ?? false);
    }

    /**
     * Whether a date field also carries a time component.
     */
    public function includesTime(): bool
    {
        return (bool)($this->metadata['include_time'] ?? false);
    }

    /**
     * The configured values of a drop-down field.
     *
     * Beacon rejects any value that is not configured, so offering these for
     * selection is far safer than letting a value be typed by hand.
     *
     * @return list<string>
     */
    public function options(): array
    {
        if ($this->type !== FieldType::Select) {
            return [];
        }

        $options = $this->metadata['options'] ?? [];

        if (!is_array($options)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $option): string => (string)$option, $options),
            static fn(string $option): bool => $option !== '',
        ));
    }

    /**
     * Whether this field is a structured person name, which is addressed one
     * part at a time — `name:first`, `name:last` and so on.
     */
    public function isPersonName(): bool
    {
        return $this->type === FieldType::PersonName;
    }

    /**
     * The flat handles addressing each part of a person-name field, so a
     * mapping UI can offer one row per part.
     *
     * @return list<string>
     */
    public function partHandles(): array
    {
        if (!$this->isPersonName()) {
            return [];
        }

        return array_map(
            fn(string $part): string => $this->key . self::PART_SEPARATOR . $part,
            self::NAME_PARTS,
        );
    }
}
