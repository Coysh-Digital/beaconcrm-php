<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Unit;

use CoyshDigital\Beacon\Schema\EntityType;
use CoyshDigital\Beacon\Schema\FieldType;
use CoyshDigital\Beacon\Tests\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityType::class)]
#[CoversClass(\CoyshDigital\Beacon\Schema\Field::class)]
#[CoversClass(FieldType::class)]
final class EntityTypeTest extends TestCase
{
    public function testParsesEveryRecordTypeSortedByLabel(): void
    {
        $types = EntityType::listFromResponse(Fixture::json('entity_types'));

        self::assertSame(['branch', 'supporter'], array_map(static fn($type) => $type->key, $types));
    }

    public function testSkipsFieldsWithNoKeyOrType(): void
    {
        $fields = Fixture::supporterType()->fields();

        self::assertArrayNotHasKey('', $fields);
        self::assertCount(24, $fields);
    }

    public function testFallsBackToTheKeyWhenNoLabelIsGiven(): void
    {
        $type = EntityType::fromArray(['key' => 'thing', 'fields' => [['key' => 'a', 'type' => 'string']]]);

        self::assertNotNull($type);
        self::assertSame('thing', $type->label);
        self::assertSame('a', $type->field('a')?->label);
    }

    public function testReadOnlySmartAndRollupFieldsAreNotWritable(): void
    {
        $type = Fixture::supporterType();

        self::assertFalse($type->field('reference_code')?->isWritable());
        self::assertFalse($type->field('lifetime_value')?->isWritable());
        self::assertFalse($type->field('engagement_score')?->isWritable());
        self::assertTrue($type->field('job_title')?->isWritable());
    }

    public function testFileAndUserFieldsAreNotMappable(): void
    {
        $type = Fixture::supporterType();

        self::assertFalse($type->field('attachments')?->isMappable());
        self::assertFalse($type->field('owner')?->isMappable());
    }

    public function testLocationFieldsAreMappable(): void
    {
        // Beacon takes an address as a list of objects, which the shaper builds
        // from one value, so a location is no less mappable than a person name.
        self::assertTrue(Fixture::supporterType()->field('address')?->isMappable());
    }

    public function testLocationFieldsOfferOneHandlePerPart(): void
    {
        $field = Fixture::supporterType()->field('address');

        self::assertNotNull($field);
        self::assertTrue($field->isLocation());
        self::assertContains('address:city', $field->partHandles());
        self::assertContains('address:postal_code', $field->partHandles());
        self::assertContains('address:address_line_one', $field->partHandles());

        // Beacon sets these itself; offering them for mapping would be a trap.
        self::assertNotContains('address:latitude', $field->partHandles());
        self::assertNotContains('address:contact_point_id', $field->partHandles());
    }

    public function testMappableFieldsExcludeUnwritableAndUnmappableOnes(): void
    {
        $mappable = array_keys(Fixture::supporterType()->mappableFields());

        self::assertContains('emails', $mappable);
        self::assertContains('c_tier', $mappable);
        self::assertContains('address', $mappable);
        self::assertNotContains('attachments', $mappable);
        self::assertNotContains('owner', $mappable);
        self::assertNotContains('reference_code', $mappable);
        self::assertNotContains('lifetime_value', $mappable);
        self::assertNotContains('engagement_score', $mappable);
    }

    public function testAnUnrecognisedFieldTypeStaysMappableAsText(): void
    {
        $field = Fixture::supporterType()->field('nickname');

        self::assertNotNull($field);
        self::assertNull($field->type);
        self::assertSame('unknown_future_type', $field->rawType);
        self::assertTrue($field->isMappable());
    }

    public function testExposesDropDownOptions(): void
    {
        $type = Fixture::supporterType();
        $singleSelect = $type->field('c_tier');
        $multiSelect = $type->field('c_channels');

        self::assertNotNull($singleSelect);
        self::assertNotNull($multiSelect);
        self::assertSame(['Bronze', 'Silver', 'Gold'], $singleSelect->options());
        self::assertSame([], $type->field('job_title')?->options());
        self::assertFalse($singleSelect->allowsMultiple());
        self::assertTrue($multiSelect->allowsMultiple());
    }

    public function testReadsDateTimeMetadata(): void
    {
        $type = Fixture::supporterType();

        self::assertFalse($type->field('joined_on')?->includesTime());
        self::assertTrue($type->field('last_seen_at')?->includesTime());
    }

    public function testAPartHandleResolvesToItsParentField(): void
    {
        $viaPart = Fixture::supporterType()->field('name:first');

        self::assertNotNull($viaPart);
        self::assertSame('name', $viaPart->key);
        self::assertSame(
            ['name:full', 'name:first', 'name:last', 'name:middle', 'name:prefix'],
            $viaPart->partHandles(),
        );
    }

    public function testUnknownFieldsAndTypesResolveToNull(): void
    {
        self::assertNull(Fixture::supporterType()->field('nope'));
        self::assertNull(EntityType::fromArray(['label' => 'No key']));
        self::assertSame([], EntityType::listFromResponse([]));
    }
}
