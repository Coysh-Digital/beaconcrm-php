<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Unit;

use CoyshDigital\Beacon\BeaconClient;
use CoyshDigital\Beacon\Config;
use CoyshDigital\Beacon\Exception\ConfigurationException;
use CoyshDigital\Beacon\Exception\InvalidPayloadException;
use CoyshDigital\Beacon\Http\Request;
use CoyshDigital\Beacon\Resource\Entities;
use CoyshDigital\Beacon\Resource\EntityTypes;
use CoyshDigital\Beacon\Resource\Exports;
use CoyshDigital\Beacon\Tests\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Entities::class)]
#[CoversClass(EntityTypes::class)]
#[CoversClass(Exports::class)]
#[CoversClass(Request::class)]
#[CoversClass(Config::class)]
final class RequestBuildingTest extends TestCase
{
    private function supporters(): Entities
    {
        return (new BeaconClient(new Config('12345', 'key')))->entities(Fixture::supporterType());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        self::assertNotNull($request->body);

        return $request->body;
    }

    public function testTheAccountUriCarriesTheAccountAndTheRequiredHeadersArePresent(): void
    {
        $config = new Config('12345', 'secret');

        self::assertSame('https://api.beaconcrm.org/v1/account/12345/', $config->accountUri());
        self::assertSame([
            'Content-Type' => 'application/json',
            'Beacon-Application' => 'developer_api',
            'Authorization' => 'Bearer secret',
        ], $config->headers());
    }

    public function testCredentialsAreRequired(): void
    {
        $this->expectException(ConfigurationException::class);

        new Config('', 'key');
    }

    public function testCreatePostsTheShapedEntityToTheRecordTypeEndpoint(): void
    {
        $supporters = $this->supporters();
        $request = $supporters->createRequest(
            $supporters->payload()->set('name:first', 'Alex')->set('emails', 'alex@example.org'),
        );

        $body = $this->body($request);

        self::assertSame('POST', $request->method);
        self::assertSame('entity/supporter', $request->path);
        self::assertSame('Alex', $body['name']['full']);
        self::assertSame([['email' => 'alex@example.org', 'is_primary' => true]], $body['emails']);
    }

    public function testUpsertWrapsTheEntityAndNamesTheLookupKey(): void
    {
        $supporters = $this->supporters();
        $request = $supporters->upsertRequest('emails', $supporters->payload()->set('emails', 'alex@example.org'));

        $body = $this->body($request);

        self::assertSame('PUT', $request->method);
        self::assertSame('entity/supporter/upsert', $request->path);
        self::assertSame('emails', $body['primary_field_key']);
        self::assertArrayHasKey('emails', $body['entity']);
    }

    public function testUpsertRefusesToSendWhenTheLookupKeyHasNoValue(): void
    {
        $supporters = $this->supporters();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Upsert key "emails"');

        $supporters->upsertRequest('emails', $supporters->payload()->set('job_title', 'Trustee'));
    }

    public function testAnEmptyPayloadIsRefused(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->supporters()->createRequest([]);
    }

    public function testReadBuildsPopulateAndArchivedFlags(): void
    {
        $request = $this->supporters()->readRequest(1988, populate: false, archived: true);

        self::assertSame('GET', $request->method);
        self::assertSame('entity/supporter/1988', $request->path);
        self::assertSame(['populate' => 'false', 'archived' => 'true'], $request->query);
        self::assertSame('entity/supporter/1988?populate=false&archived=true', $request->uri());
    }

    public function testFlagsAreOmittedWhenNotAskedFor(): void
    {
        $request = $this->supporters()->readRequest(1988);

        self::assertSame([], $request->query);
        self::assertSame('entity/supporter/1988', $request->uri());
    }

    public function testUpdateAndDeleteTargetTheRecord(): void
    {
        self::assertSame('PUT', $this->supporters()->updateRequest(7, ['job_title' => 'Chair'])->method);
        self::assertSame('entity/supporter/7', $this->supporters()->deleteRequest(7)->path);
        self::assertSame('DELETE', $this->supporters()->deleteRequest(7)->method);
    }

    public function testListAndSearchBuildTheirRequests(): void
    {
        $list = $this->supporters()->listRequest(['page' => 2], populate: false);

        self::assertSame('entity/supporter', $list->path);
        self::assertSame(['populate' => 'false', 'page' => 2], $list->query);

        $search = $this->supporters()->searchRequest(['operator' => 'and', 'conditions' => []]);

        self::assertSame('POST', $search->method);
        self::assertSame('entity/supporter/search', $search->path);
        self::assertSame(['operator' => 'and', 'conditions' => []], $this->body($search)['filter']);
    }

    public function testRecordTypeKeysAreUrlEncoded(): void
    {
        $entities = (new BeaconClient(new Config('12345', 'key')))->entities('odd key');

        self::assertSame('entity/odd%20key', $entities->createRequest(['a' => 'b'])->path);
    }

    public function testSchemaAndExportRequests(): void
    {
        self::assertSame('entity_types', EntityTypes::listRequest()->path);

        $trigger = Exports::triggerRequest('42');
        self::assertSame('POST', $trigger->method);
        self::assertSame('entity_export/trigger', $trigger->path);
        self::assertSame(['entity_export_template_id' => 42], $trigger->body);

        $status = Exports::statusRequest(7);
        self::assertSame('entity_exports', $status->path);
        self::assertSame(['entity_export_id' => '7'], $status->query);
    }
}
