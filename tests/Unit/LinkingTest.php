<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Unit;

use CoyshDigital\Beacon\BeaconClient;
use CoyshDigital\Beacon\Config;
use CoyshDigital\Beacon\Http\RetryPolicy;
use CoyshDigital\Beacon\Payload\ValueShaper;
use CoyshDigital\Beacon\Resource\Entities;
use CoyshDigital\Beacon\Schema\Field;
use CoyshDigital\Beacon\Schema\EntityType;
use CoyshDigital\Beacon\Tests\Fixtures\Fixture;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Linking and lookup.
 *
 * The behaviour asserted here was established against a live Beacon account by
 * tools/relationship-lab: a link reads back as a list of bare integers, and a
 * write **replaces** that list rather than appending to it.
 */
#[CoversClass(Entities::class)]
#[CoversClass(ValueShaper::class)]
#[CoversClass(Field::class)]
final class LinkingTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    /**
     * @param list<Psr7Response> $queue
     */
    private function entities(array $queue, string $typeKey = 'supporter'): Entities
    {
        $this->sent = [];

        $stack = HandlerStack::create(new MockHandler($queue));

        $stack->push(fn(callable $handler): callable =>
            function (RequestInterface $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            });

        $client = new BeaconClient(
            new Config('12345', 'key', retryPolicy: RetryPolicy::none()),
            new Client(['handler' => $stack]),
        );

        return $client->entities($typeKey)->withSchema(Fixture::supporterType());
    }

    /**
     * A record response carrying one link field.
     *
     * @param list<int> $branchIds
     */
    private static function record(array $branchIds, string $fieldKey = 'c_visited_branches'): Psr7Response
    {
        return new Psr7Response(200, [], (string)json_encode([
            'entity' => ['id' => 7, $fieldKey => $branchIds],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(int $index): array
    {
        self::assertArrayHasKey($index, $this->sent);

        /** @var array<string, mixed> $body */
        $body = json_decode((string)$this->sent[$index]->getBody(), true);

        return $body;
    }

    // Reading a link back
    // =========================================================================

    public function testReferenceIdsNormalisesTheShapesBeaconReturns(): void
    {
        // What Beacon actually sends: a list of bare integers.
        self::assertSame([4, 5], ValueShaper::referenceIds([4, 5]));

        // Accepted for convenience, so a populated record can be handed over
        // without the caller checking which shape it got.
        self::assertSame([4, 5], ValueShaper::referenceIds([['id' => 4], ['id' => 5]]));
        self::assertSame([4], ValueShaper::referenceIds(4));
        self::assertSame([4], ValueShaper::referenceIds('4'));
    }

    public function testReferenceIdsDropsWhatCannotBeAnId(): void
    {
        self::assertSame([], ValueShaper::referenceIds(null));
        self::assertSame([], ValueShaper::referenceIds([]));
        self::assertSame([], ValueShaper::referenceIds(['not-an-id']));
        self::assertSame([], ValueShaper::referenceIds([['label' => 'no id here']]));
    }

    public function testReferenceIdsDeduplicates(): void
    {
        self::assertSame([4, 5], ValueShaper::referenceIds([4, 5, 4]));
    }

    public function testLinksReadsTheCurrentIds(): void
    {
        $entities = $this->entities([self::record([4, 5])]);

        self::assertSame([4, 5], $entities->links(7, 'c_visited_branches'));

        // Linked-record data is not needed to read IDs, and makes the response
        // substantially bigger.
        self::assertStringContainsString('populate=false', (string)$this->sent[0]->getUri());
    }

    // link()
    // =========================================================================

    public function testLinkSendsTheExistingIdsBackAlongsideTheNewOne(): void
    {
        $entities = $this->entities([
            self::record([4, 5]),
            new Psr7Response(200, [], '{"entity":{"id":7}}'),
        ]);

        $entities->link(7, 'c_visited_branches', 6);

        // The whole point: a write replaces the list, so 4 and 5 have to be
        // sent back or they are dropped.
        self::assertSame(['c_visited_branches' => [4, 5, 6]], $this->sentBody(1));
        self::assertSame('PATCH', $this->sent[1]->getMethod());
    }

    public function testLinkAcceptsSeveralIdsAndDeduplicatesAgainstWhatIsThere(): void
    {
        $entities = $this->entities([
            self::record([4]),
            new Psr7Response(200, [], '{"entity":{"id":7}}'),
        ]);

        $entities->link(7, 'c_visited_branches', [4, 5, 6]);

        self::assertSame(['c_visited_branches' => [4, 5, 6]], $this->sentBody(1));
    }

    public function testLinkSendsNothingWhenEveryIdIsAlreadyLinked(): void
    {
        $entities = $this->entities([self::record([4, 5])]);

        self::assertNull($entities->link(7, 'c_visited_branches', [5, 4]));
        self::assertCount(1, $this->sent, 'the read happened, the write should not have');
    }

    public function testLinkCastsNumericStringsToIntegers(): void
    {
        $entities = $this->entities([
            self::record([]),
            new Psr7Response(200, [], '{"entity":{"id":7}}'),
        ]);

        $entities->link(7, 'c_visited_branches', ['6']);

        // Beacon rejects a numeric string: `0 must be of integer type`.
        self::assertSame(['c_visited_branches' => [6]], $this->sentBody(1));
    }

    // unlink()
    // =========================================================================

    public function testUnlinkKeepsTheOtherLinks(): void
    {
        $entities = $this->entities([
            self::record([4, 5, 6]),
            new Psr7Response(200, [], '{"entity":{"id":7}}'),
        ]);

        $entities->unlink(7, 'c_visited_branches', 5);

        self::assertSame(['c_visited_branches' => [4, 6]], $this->sentBody(1));
    }

    public function testUnlinkCanEmptyTheField(): void
    {
        $entities = $this->entities([
            self::record([4]),
            new Psr7Response(200, [], '{"entity":{"id":7}}'),
        ]);

        $entities->unlink(7, 'c_visited_branches', 4);

        // EntityPayload drops [] as an empty value, so a payload-built body
        // could never remove the last link. This has to be a raw body.
        self::assertSame(['c_visited_branches' => []], $this->sentBody(1));
    }

    public function testUnlinkSendsNothingWhenTheIdWasNotLinked(): void
    {
        $entities = $this->entities([self::record([4, 5])]);

        self::assertNull($entities->unlink(7, 'c_visited_branches', 99));
        self::assertCount(1, $this->sent);
    }

    public function testSetLinksReplacesWithoutReadingFirst(): void
    {
        $entities = $this->entities([new Psr7Response(200, [], '{"entity":{"id":7}}')]);

        $entities->setLinks(7, 'c_visited_branches', [4, 5]);

        self::assertCount(1, $this->sent, 'replacing needs no read');
        self::assertSame(['c_visited_branches' => [4, 5]], $this->sentBody(0));
    }

    // findBy()
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $entities
     */
    private static function page(array $entities, ?int $total = null): Psr7Response
    {
        return new Psr7Response(200, [], (string)json_encode([
            'total' => $total ?? count($entities),
            'results' => array_map(static fn(array $entity): array => ['entity' => $entity], $entities),
        ]));
    }

    public function testFindByMatchesOnAPlainValue(): void
    {
        $entities = $this->entities([
            self::page([
                ['id' => 1, 'job_title' => 'Trustee'],
                ['id' => 2, 'job_title' => 'Volunteer'],
            ]),
        ]);

        self::assertSame(2, $entities->findBy('job_title', 'Volunteer')['id'] ?? null);
    }

    public function testFindByIsCaseInsensitive(): void
    {
        $entities = $this->entities([self::page([['id' => 1, 'job_title' => 'Trustee']])]);

        self::assertSame(1, $entities->findBy('job_title', 'trustee')['id'] ?? null);
    }

    public function testFindByComparesInsideAContactPoint(): void
    {
        $entities = $this->entities([
            self::page([
                ['id' => 1, 'emails' => [['email' => 'a@example.org', 'is_primary' => true]]],
                ['id' => 2, 'emails' => [['email' => 'b@example.org', 'is_primary' => true]]],
            ]),
        ]);

        // A stored email is an object in a list, never a bare string, so a
        // naive comparison would never match.
        self::assertSame(2, $entities->findBy('emails', 'b@example.org')['id'] ?? null);
    }

    public function testFindByReturnsNullOnAMiss(): void
    {
        $entities = $this->entities([self::page([['id' => 1, 'job_title' => 'Trustee']])]);

        self::assertNull($entities->findBy('job_title', 'Nobody'));
    }

    public function testFindByDoesNotSearchForAnEmptyValue(): void
    {
        $entities = $this->entities([]);

        self::assertNull($entities->findBy('job_title', ''));
        self::assertCount(0, $this->sent, 'an empty needle would match the first blank record');
    }

    public function testFindByStopsAtTheLimit(): void
    {
        $entities = $this->entities([
            self::page([['id' => 1, 'job_title' => 'A'], ['id' => 2, 'job_title' => 'B']], total: 500),
        ]);

        self::assertNull($entities->findBy('job_title', 'B', limit: 1));
    }

    // resolveId()
    // =========================================================================

    public function testResolveIdUpsertsAndReturnsTheId(): void
    {
        $entities = $this->entities([new Psr7Response(200, [], '{"entity":{"id":42}}')]);

        $id = $entities->resolveId('job_title', $entities->payload()->set('job_title', 'Trustee'));

        self::assertSame(42, $id);
        self::assertCount(1, $this->sent, 'find-or-create is one request, which is the point');
        self::assertSame('PUT', $this->sent[0]->getMethod());
        self::assertSame('job_title', $this->sentBody(0)['primary_field_key'] ?? null);
    }

    // Which type a link points at
    // =========================================================================

    public function testLinksToNamesTheTargetRecordTypes(): void
    {
        $type = Fixture::supporterType();

        $branch = $type->field('c_branch');
        $visited = $type->field('c_visited_branches');

        self::assertNotNull($branch);
        self::assertNotNull($visited);

        // Beacon gives numeric record type IDs, which are meaningless without
        // the account's id-to-key map.
        self::assertSame(['branch'], $branch->linksTo());
        self::assertSame([1002], $branch->linksToIds());

        self::assertSame(['branch', 'supporter'], $visited->linksTo());
    }

    public function testLinksToIsEmptyForFieldsThatAreNotLinks(): void
    {
        $field = Fixture::supporterType()->field('job_title');

        self::assertNotNull($field);
        self::assertFalse($field->isReference());
        self::assertSame([], $field->linksTo());
        self::assertSame([], $field->linksToIds());
    }

    public function testLinksToIsEmptyWithoutTheAccountsIdMap(): void
    {
        // fromArray() on its own has no way to resolve an ID to a key. The IDs
        // are still there.
        $field = Field::fromArray([
            'key' => 'c_branch',
            'type' => 'reference',
            'metadata' => ['entity_types' => [1002]],
        ]);

        self::assertNotNull($field);
        self::assertSame([], $field->linksTo());
        self::assertSame([1002], $field->linksToIds());
    }

    public function testRecordTypesCarryTheirNumericId(): void
    {
        $types = EntityType::listFromResponse(Fixture::json('entity_types'));

        self::assertSame([1002, 1001], array_map(static fn(EntityType $t): ?int => $t->id, $types));
    }
}
