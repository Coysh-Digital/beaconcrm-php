<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Schema;

/**
 * The field types Beacon reports in its `entity_types` schema.
 *
 * Beacon can add types at any time, and an unrecognised one is not an error —
 * `tryFromName()` returns null and the field is then treated as plain text,
 * which is how the API behaves for simple values anyway.
 */
enum FieldType: string
{
    case ShortText = 'string';
    case LongText = 'text';
    case Url = 'url';
    case PersonName = 'person_name';
    case Email = 'email';
    case Phone = 'phone';
    case Select = 'select';
    case Reference = 'reference';
    case Boolean = 'boolean';
    case Number = 'number';
    case Currency = 'currency';
    case Percent = 'percent';
    case Rating = 'rating';
    case Date = 'date';
    case File = 'file';
    case User = 'user';
    case Location = 'location';

    /**
     * Types that cannot be written from a single mapped value.
     *
     * `file` needs Beacon's signed-upload handshake and cannot be sent inside
     * an entity payload; `user` refers to Beacon user accounts rather than data.
     *
     * `location` used to be here. It is a structured object, but so is a person
     * name, and both are writable from one value once the shaper knows what to
     * build — see ValueShaper::shapeLocation().
     */
    public function isWritableFromScalar(): bool
    {
        return !in_array($this, [self::File, self::User], true);
    }

    /**
     * Whether Beacon represents this field as a JSON array, regardless of how
     * many values the field is configured to hold. Drop-downs, record links and
     * contact points are arrays even when single-valued — a bare object or
     * string is rejected.
     */
    public function isAlwaysArray(): bool
    {
        return in_array($this, [self::Select, self::Reference, self::Email, self::Phone, self::Location], true);
    }

    /**
     * Whether Beacon models this type as a contact point: a list of objects,
     * each flagged with `is_primary`. Emails, phones and addresses all are, and
     * all three behave identically on write.
     */
    public function isContactPoint(): bool
    {
        return in_array($this, [self::Email, self::Phone, self::Location], true);
    }

    /**
     * Whether values of this type are JSON numbers rather than strings.
     */
    public function isNumeric(): bool
    {
        return in_array($this, [self::Number, self::Currency, self::Percent, self::Rating], true);
    }

    public static function tryFromName(?string $type): ?self
    {
        return $type === null ? null : self::tryFrom($type);
    }
}
