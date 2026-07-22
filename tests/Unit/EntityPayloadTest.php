<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Unit;

use CoyshDigital\Beacon\Payload\EntityPayload;
use CoyshDigital\Beacon\Payload\ValueShaper;
use CoyshDigital\Beacon\Tests\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityPayload::class)]
#[CoversClass(ValueShaper::class)]
final class EntityPayloadTest extends TestCase
{
    private function payload(): EntityPayload
    {
        return EntityPayload::for(Fixture::supporterType());
    }

    public function testShapesEmailAndPhoneAsArraysOfObjects(): void
    {
        $built = $this->payload()
            ->set('emails', 'alex@example.org')
            ->set('phone_numbers', '+441234567890')
            ->build();

        self::assertSame([['email' => 'alex@example.org', 'is_primary' => true]], $built['emails']);
        self::assertSame([['number' => '+441234567890', 'is_primary' => true]], $built['phone_numbers']);
    }

    public function testOnlyTheFirstEmailIsPrimary(): void
    {
        $built = $this->payload()->set('emails', ['a@example.org', 'b@example.org'])->build();

        self::assertSame([
            ['email' => 'a@example.org', 'is_primary' => true],
            ['email' => 'b@example.org', 'is_primary' => false],
        ], $built['emails']);
    }

    public function testAlreadyShapedEmailsArePassedThrough(): void
    {
        $built = $this->payload()
            ->set('emails', [['email' => 'a@example.org', 'is_primary' => false]])
            ->build();

        self::assertSame([['email' => 'a@example.org', 'is_primary' => false]], $built['emails']);
    }

    public function testPhoneNumbersStayStringsSoALeadingPlusSurvives(): void
    {
        $built = $this->payload()->set('phone_numbers', '+441234567890')->build();

        self::assertIsString($built['phone_numbers'][0]['number']);
    }

    /**
     * A bare number sent to a currency field is accepted with a 200 and then
     * stored as null, so the object form is the only safe one.
     */
    public function testCurrencyIsSentAsAnObjectWithNoCurrencyCode(): void
    {
        $built = $this->payload()->set('monthly_gift', '25.50')->build();

        self::assertSame(['value' => 25.5], $built['monthly_gift']);
    }

    public function testNumbersKeepIntegersAsIntegersAndDecimalsAsFloats(): void
    {
        $built = $this->payload()
            ->set('visit_count', '12')
            ->set('completion', '72.5')
            ->set('satisfaction', 4)
            ->build();

        self::assertSame(12, $built['visit_count']);
        self::assertSame(72.5, $built['completion']);
        self::assertSame(4, $built['satisfaction']);
    }

    public function testANonNumericValueForANumberFieldIsPassedThroughRatherThanBecomingZero(): void
    {
        $built = $this->payload()->set('visit_count', 'lots')->build();

        self::assertSame('lots', $built['visit_count']);
    }

    public function testSingleSelectDropDownsAreStillSentAsArrays(): void
    {
        $built = $this->payload()->set('c_tier', 'Gold')->build();

        self::assertSame(['Gold'], $built['c_tier']);
    }

    public function testMultiSelectDropsBlankEntries(): void
    {
        $built = $this->payload()->set('c_channels', ['Email', '', null, 'SMS'])->build();

        self::assertSame(['Email', 'SMS'], $built['c_channels']);
    }

    public function testRecordLinksBecomeArraysOfIntegerIds(): void
    {
        $built = $this->payload()->set('c_branch', ['902', 731])->build();

        self::assertSame([902, 731], $built['c_branch']);
    }

    public function testCheckboxesBecomeJsonBooleans(): void
    {
        self::assertTrue($this->payload()->set('is_active', '1')->build()['is_active']);
        self::assertFalse($this->payload()->set('is_active', 0)->build()['is_active']);
    }

    public function testNamePartsAreAssembledIntoOneObjectWithFullDerived(): void
    {
        $built = $this->payload()
            ->set('name:first', 'Alex')
            ->set('name:last', 'Rivera')
            ->build();

        self::assertSame([
            'full' => 'Alex Rivera',
            'first' => 'Alex',
            'last' => 'Rivera',
            'middle' => null,
            'prefix' => null,
        ], $built['name']);
    }

    public function testAnExplicitFullNameIsNotOverwritten(): void
    {
        $built = $this->payload()
            ->set('name:full', 'Dr Alex Rivera')
            ->set('name:first', 'Alex')
            ->build();

        self::assertSame('Dr Alex Rivera', $built['name']['full']);
    }

    public function testEmptyValuesAreSkippedSoBlankFieldsCannotOverwriteBeaconData(): void
    {
        $payload = $this->payload()
            ->set('job_title', '')
            ->set('notes', null)
            ->set('c_channels', [])
            ->set('is_active', false)
            ->set('visit_count', 0);

        $built = $payload->build();

        self::assertArrayNotHasKey('job_title', $built);
        self::assertArrayNotHasKey('notes', $built);
        self::assertArrayNotHasKey('c_channels', $built);
        // Zero and false are real values, not empty ones.
        self::assertArrayHasKey('is_active', $built);
        self::assertArrayHasKey('visit_count', $built);
    }

    public function testAFieldMissingFromTheSchemaIsPassedThroughUnshaped(): void
    {
        $built = $this->payload()->set('not_in_schema', 'as-is')->build();

        self::assertSame('as-is', $built['not_in_schema']);
    }

    public function testWithoutASchemaValuesArePassedThrough(): void
    {
        $built = EntityPayload::for()->set('emails', 'alex@example.org')->build();

        self::assertSame('alex@example.org', $built['emails']);
    }

    public function testHasFieldSeesValuesSetThroughAPart(): void
    {
        $payload = $this->payload()->set('name:first', 'Alex');

        self::assertTrue($payload->hasField('name'));
        self::assertFalse($payload->has('name'));
        self::assertFalse($payload->hasField('emails'));
    }

    public function testSetManyAndIsEmpty(): void
    {
        self::assertTrue($this->payload()->isEmpty());
        self::assertFalse($this->payload()->setMany(['job_title' => 'Trustee'])->isEmpty());
    }
}
