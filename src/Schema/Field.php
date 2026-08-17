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
     * The writable parts of an address, in the order Beacon presents them.
     *
     * Beacon rejects any key outside this list — `postcode` instead of
     * `postal_code` is the easy mistake. It sets `latitude`, `longitude` and
     * `contact_point_id` itself, so they are absent here; they are accepted
     * back unchanged if a value read from Beacon is written straight back.
     *
     * @var list<string>
     */
    public const LOCATION_PARTS = [
        'address_line_one',
        'address_line_two',
        'address_line_three',
        'city',
        'region',
        'postal_code',
        'country',
        'country_code',
        'notes',
    ];

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
        /** @var list<string> */
        private readonly array $linksTo = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data One entry from an entity type's
     *                                   `fields` array.
     * @param array<int, string> $typeKeysById Record type keys by numeric ID.
     *                                         Beacon names a link's target types
     *                                         by ID, so without this map
     *                                         {@see linksTo()} has nothing to
     *                                         resolve them against and returns
     *                                         an empty list.
     */
    public static function fromArray(array $data, array $typeKeysById = []): ?self
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
        $metadata = is_array($metadata) ? $metadata : [];

        $linksTo = [];

        foreach (self::targetIdsIn($metadata) as $id) {
            if (isset($typeKeysById[$id])) {
                $linksTo[] = $typeKeysById[$id];
            }
        }

        return new self(
            key: $key,
            label: is_string($label) && $label !== '' ? $label : $key,
            type: FieldType::tryFromName($rawType),
            rawType: $rawType,
            isReadOnly: (bool)($data['is_read_only'] ?? false),
            isSmartField: (bool)($data['is_smart_field'] ?? false),
            isRollupField: (bool)($data['is_rollup_field'] ?? false),
            metadata: $metadata,
            linksTo: array_values(array_unique($linksTo)),
        );
    }

    /**
     * The numeric record type IDs a link field may point at.
     *
     * @param array<string, mixed> $metadata
     * @return list<int>
     */
    private static function targetIdsIn(array $metadata): array
    {
        $ids = $metadata['entity_types'] ?? [];

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map(
            static fn(mixed $id): int => (int)$id,
            array_filter($ids, static fn(mixed $id): bool => is_numeric($id)),
        ));
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
     * False for file and user fields, which need more than a payload value can
     * carry. See FieldType::isWritableFromScalar().
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
     * Whether this field links to other records.
     */
    public function isReference(): bool
    {
        return $this->type === FieldType::Reference;
    }

    /**
     * The keys of the record types this link may point at.
     *
     * Beacon enforces this: a link given the ID of a record of any other type
     * is rejected with `One of the referenced entities is not one of the
     * allowed types`, quoting the numeric IDs rather than the keys.
     *
     * Empty for a field that is not a link, and for one parsed without the
     * account's id-to-key map — see {@see fromArray()}. Use
     * {@see linksToIds()} when only the raw IDs are needed.
     *
     * @return list<string>
     */
    public function linksTo(): array
    {
        return $this->isReference() ? $this->linksTo : [];
    }

    /**
     * The numeric record type IDs this link may point at, as Beacon reports
     * them.
     *
     * @return list<int>
     */
    public function linksToIds(): array
    {
        return $this->isReference() ? self::targetIdsIn($this->metadata) : [];
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
     * Whether this field is an address, addressed either whole or one part at a
     * time — `address:city`, `address:postal_code` and so on.
     */
    public function isLocation(): bool
    {
        return $this->type === FieldType::Location;
    }

    /**
     * The parts this field is made of, if it is structured.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        return match ($this->type) {
            FieldType::PersonName => self::NAME_PARTS,
            FieldType::Location => self::LOCATION_PARTS,
            default => [],
        };
    }

    /**
     * The flat handles addressing each part of a structured field, so a mapping
     * UI can offer one row per part.
     *
     * Only the first address can be mapped this way. A record with several needs
     * the whole value set at once — see ValueShaper::shapeLocations().
     *
     * @return list<string>
     */
    public function partHandles(): array
    {
        return array_map(
            fn(string $part): string => $this->key . self::PART_SEPARATOR . $part,
            $this->parts(),
        );
    }
}
