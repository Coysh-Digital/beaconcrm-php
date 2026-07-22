<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Payload;

use CoyshDigital\Beacon\Schema\Field;
use CoyshDigital\Beacon\Schema\FieldType;

/**
 * Turns plain PHP values into the JSON shapes Beacon expects for each field
 * type.
 *
 * Beacon is fussy and, in one case, silently so: a bare number sent to a
 * currency field is accepted with a 200 and then stored as null, losing the
 * amount with no error anywhere. Every rule below exists because Beacon
 * rejected — or quietly discarded — the obvious alternative.
 */
final class ValueShaper
{
    /**
     * Shapes one value for one field.
     *
     * A null field (a key not present in the schema) passes its value through
     * untouched, so an account can be written to ahead of a schema refresh.
     */
    public static function shape(?Field $field, mixed $value): mixed
    {
        return self::shapeForType($field?->type, $value);
    }

    public static function shapeForType(?FieldType $type, mixed $value): mixed
    {
        return match ($type) {
            FieldType::Email => self::shapeEmails($value),
            FieldType::Phone => self::shapePhones($value),
            FieldType::Boolean => (bool)$value,
            // Currency is the one numeric type Beacon wants as an object. The
            // currency code is omitted deliberately: Beacon fills in the
            // account default. Amounts are major units — 25.5 means £25.50.
            FieldType::Currency => ['value' => (float)$value],
            // Number, percent and rating all reject the object form. `+ 0`
            // keeps whole numbers as ints and decimals as floats, respecting
            // each field's configured decimal places.
            FieldType::Number, FieldType::Percent, FieldType::Rating => self::shapeNumber($value),
            // Drop-downs are arrays in Beacon even when single-select.
            FieldType::Select => self::shapeSelect($value),
            // Record links are arrays of integer Beacon record IDs, even when
            // the field permits only one linked record.
            FieldType::Reference => self::shapeReference($value),
            FieldType::PersonName => self::assembleName(is_array($value) ? $value : ['full' => $value]),
            default => $value,
        };
    }

    /**
     * `[{"email": "…", "is_primary": true}]`, with the first entry primary.
     *
     * @return list<array{email: string, is_primary: bool}>
     */
    public static function shapeEmails(mixed $value): array
    {
        $emails = [];

        foreach (self::toList($value) as $index => $email) {
            if (is_array($email)) {
                // Already in Beacon's shape — respect it rather than rebuild it.
                if (isset($email['email'])) {
                    $emails[] = [
                        'email' => (string)$email['email'],
                        'is_primary' => (bool)($email['is_primary'] ?? $index === 0),
                    ];
                }

                continue;
            }

            $emails[] = [
                'email' => (string)$email,
                'is_primary' => $index === 0,
            ];
        }

        return $emails;
    }

    /**
     * `[{"number": "…", "is_primary": true}]`, with the first entry primary.
     *
     * Numbers stay strings so a leading `+` or `0` survives.
     *
     * @return list<array{number: string, is_primary: bool}>
     */
    public static function shapePhones(mixed $value): array
    {
        $phones = [];

        foreach (self::toList($value) as $index => $phone) {
            if (is_array($phone)) {
                if (isset($phone['number'])) {
                    $phones[] = [
                        'number' => (string)$phone['number'],
                        'is_primary' => (bool)($phone['is_primary'] ?? $index === 0),
                    ];
                }

                continue;
            }

            $phones[] = [
                'number' => (string)$phone,
                'is_primary' => $index === 0,
            ];
        }

        return $phones;
    }

    /**
     * Drop-down values as an array, blanks dropped.
     *
     * @return list<mixed>
     */
    public static function shapeSelect(mixed $value): array
    {
        return array_values(array_filter(
            self::toList($value),
            static fn(mixed $item): bool => $item !== null && $item !== '',
        ));
    }

    /**
     * Record links as an array of integer Beacon record IDs.
     *
     * @return list<int>
     */
    public static function shapeReference(mixed $value): array
    {
        return array_values(array_map(
            static fn(mixed $item): int => (int)$item,
            array_filter(self::toList($value)),
        ));
    }

    /**
     * A JSON number, keeping whole numbers as ints and decimals as floats. A
     * non-numeric value is passed through so Beacon can report it rather than
     * having it silently become 0.
     */
    public static function shapeNumber(mixed $value): mixed
    {
        return is_numeric($value) ? $value + 0 : $value;
    }

    /**
     * A person-name object with every part present.
     *
     * Beacon shows `full` throughout its UI, so it is derived from the parts
     * when only those were supplied — otherwise a record created from separate
     * first and last name fields shows up nameless.
     *
     * @param array<string, mixed> $parts
     * @return array<string, mixed>
     */
    public static function assembleName(array $parts): array
    {
        if (self::isEmpty($parts['full'] ?? null)) {
            $derived = trim(implode(' ', array_filter([
                $parts['first'] ?? null,
                $parts['middle'] ?? null,
                $parts['last'] ?? null,
            ], static fn(mixed $part): bool => !self::isEmpty($part))));

            if ($derived !== '') {
                $parts['full'] = $derived;
            }
        }

        return array_merge(array_fill_keys(Field::NAME_PARTS, null), $parts);
    }

    /**
     * Whether a value should be skipped rather than sent.
     *
     * Empty values are omitted so a blank optional form field cannot overwrite
     * data already held in Beacon. Note that `0` and `false` are real values,
     * not empty ones.
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @return list<mixed>
     */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return [$value];
    }
}
