<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Unit;

use CoyshDigital\Beacon\BeaconClient;
use CoyshDigital\Beacon\Config;
use CoyshDigital\Beacon\Exception\ApiException;
use CoyshDigital\Beacon\Exception\AuthenticationException;
use CoyshDigital\Beacon\Exception\RateLimitException;
use CoyshDigital\Beacon\Exception\TransportException;
use CoyshDigital\Beacon\Exception\ValidationException;
use CoyshDigital\Beacon\Http\Response;
use CoyshDigital\Beacon\Http\RetryPolicy;
use CoyshDigital\Beacon\Http\Transport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(Transport::class)]
#[CoversClass(RetryPolicy::class)]
#[CoversClass(Response::class)]
#[CoversClass(BeaconClient::class)]
final class TransportTest extends TestCase
{
    /**
     * Every request that actually left the client, in order.
     *
     * @var list<RequestInterface>
     */
    private array $sent = [];

    private function sentRequest(int $index): RequestInterface
    {
        self::assertArrayHasKey($index, $this->sent);

        return $this->sent[$index];
    }

    /**
     * @param list<Psr7Response|ConnectException> $queue
     */
    private function client(array $queue, ?RetryPolicy $retryPolicy = null): BeaconClient
    {
        $this->sent = [];

        $stack = HandlerStack::create(new MockHandler($queue));

        // Records each request as it goes out, so the tests can assert on how
        // many attempts were made as well as on what was sent.
        $stack->push(fn(callable $handler): callable =>
            function (RequestInterface $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            });

        $config = new Config('12345', 'secret', retryPolicy: $retryPolicy ?? RetryPolicy::none());

        return new BeaconClient($config, new Client([
            'base_uri' => $config->accountUri(),
            'headers' => $config->headers(),
            'http_errors' => false,
            'handler' => $stack,
        ]));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function json(int $status, array $body, array $headers = []): Psr7Response
    {
        return new Psr7Response($status, $headers, (string)json_encode($body));
    }

    public function testASuccessfulWriteExposesTheEnvelope(): void
    {
        $client = $this->client([$this->json(200, [
            'entity' => ['id' => 4100, 'created_at' => '2026-07-21T13:55:26.478Z'],
            'references' => [['id' => 9]],
        ])]);

        $response = $client->entities('supporter')->create(['job_title' => 'Trustee']);

        $entity = $response->entity();

        self::assertNotNull($entity);
        self::assertSame(4100, $response->entityId());
        self::assertSame('2026-07-21T13:55:26.478Z', $entity['created_at']);
        self::assertSame([['id' => 9]], $response->references());
    }

    public function testTheRequestCarriesTheAccountPathHeadersAndJsonBody(): void
    {
        $client = $this->client([$this->json(200, ['entity' => ['id' => 1]])]);
        $client->entities('supporter')->create(['job_title' => 'Trustee']);

        $request = $this->sentRequest(0);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/account/12345/entity/supporter', $request->getUri()->getPath());
        self::assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        self::assertSame('developer_api', $request->getHeaderLine('Beacon-Application'));
        self::assertSame('{"job_title":"Trustee"}', (string)$request->getBody());
    }

    public function testQueryParametersAreSent(): void
    {
        $client = $this->client([$this->json(200, ['entity' => ['id' => 1]])]);
        $client->entities('supporter')->read(1988, populate: false);

        self::assertSame('populate=false', $this->sentRequest(0)->getUri()->getQuery());
    }

    public function testTheSchemaIsParsedIntoRecordTypes(): void
    {
        $client = $this->client([$this->json(200, \CoyshDigital\Beacon\Tests\Fixtures\Fixture::json('entity_types'))]);

        $types = $client->entityTypes()->all();

        self::assertCount(2, $types);
        self::assertSame('supporter', $types[1]->key);
    }

    /**
     * A list response wraps each record in its own {entity, references}
     * envelope and reports the full match count alongside the page.
     */
    public function testListUnwrapsItsEnvelopesAndReportsTheTotal(): void
    {
        $client = $this->client([$this->json(200, [
            'total' => 39713,
            'data_source' => 1,
            'results' => [
                ['entity' => ['id' => 1, 'job_title' => 'Trustee'], 'references' => []],
                ['entity' => ['id' => 2], 'references' => []],
            ],
        ])]);

        $response = $client->entities('supporter')->list(page: 2, perPage: 2);

        self::assertSame(39713, $response->total());
        self::assertSame([['id' => 1, 'job_title' => 'Trustee'], ['id' => 2]], $response->entities());
        self::assertCount(2, $response->results());
        self::assertSame('/v1/account/12345/entities/supporter', $this->sentRequest(0)->getUri()->getPath());
        self::assertSame('page=2&per_page=2', $this->sentRequest(0)->getUri()->getQuery());
    }

    public function testEachWalksEveryPageUntilTheTotalIsReached(): void
    {
        $page = static fn(array $ids) => [
            'total' => 5,
            'results' => array_map(static fn(int $id) => ['entity' => ['id' => $id]], $ids),
        ];

        $client = $this->client([
            $this->json(200, $page([1, 2])),
            $this->json(200, $page([3, 4])),
            $this->json(200, $page([5])),
        ], new RetryPolicy(maxAttempts: 1));

        $ids = [];

        foreach ($client->entities('supporter')->each(perPage: 2) as $entity) {
            $ids[] = $entity['id'];
        }

        self::assertSame([1, 2, 3, 4, 5], $ids);
        self::assertCount(3, $this->sent);
    }

    public function testEachStopsWhenAPageComesBackEmpty(): void
    {
        $client = $this->client([
            $this->json(200, ['total' => 99, 'results' => [['entity' => ['id' => 1]]]]),
            $this->json(200, ['total' => 99, 'results' => []]),
        ], new RetryPolicy(maxAttempts: 1));

        $ids = iterator_to_array($client->entities('supporter')->each(perPage: 1), false);

        self::assertSame([['id' => 1]], $ids);
        self::assertCount(2, $this->sent);
    }

    public function testPingReportsWorkingCredentials(): void
    {
        self::assertTrue($this->client([$this->json(200, ['results' => []])])->ping());
    }

    public function testPingReportsARevokedKeyRatherThanThrowing(): void
    {
        $client = $this->client([$this->json(403, [
            'error' => ['code' => 'invalid_api_key', 'message' => 'Your API key is invalid.'],
        ])]);

        self::assertFalse($client->ping());
    }

    public function testARevokedKeyThrowsOnAWrite(): void
    {
        $client = $this->client([$this->json(403, ['error' => ['code' => 'invalid_api_key']])]);

        $this->expectException(AuthenticationException::class);

        $client->entities('supporter')->create(['job_title' => 'Trustee']);
    }

    public function testRateLimitsAreRetriedAndThenSucceed(): void
    {
        $client = $this->client([
            $this->json(429, ['error' => ['code' => 'rate_limit_exceeded']]),
            $this->json(200, ['entity' => ['id' => 5]]),
        ], new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, jitter: false));

        self::assertSame(5, $client->entities('supporter')->create(['a' => 'b'])->entityId());
        self::assertCount(2, $this->sent);
    }

    public function testRetriesAreGivenUpOnEventually(): void
    {
        $client = $this->client(
            array_fill(0, 3, $this->json(429, ['error' => ['code' => 'rate_limit_exceeded']])),
            new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, jitter: false),
        );

        $this->expectException(RateLimitException::class);

        try {
            $client->entities('supporter')->create(['a' => 'b']);
        } finally {
            self::assertCount(3, $this->sent);
        }
    }

    /**
     * The behaviour that matters most: a validation failure wearing a 500 must
     * not be retried, or a create would write the record more than once.
     */
    public function testAValidationFailureDressedAsA500IsNotRetried(): void
    {
        $client = $this->client([
            $this->json(500, ['error' => [
                'code' => 'unknown_error',
                'message' => 'Oh shoot! An unknown error occurred.',
                'raw' => 'Validation error: "emails": 0',
            ]]),
            $this->json(200, ['entity' => ['id' => 5]]),
        ], new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, jitter: false));

        try {
            $client->entities('supporter')->create(['a' => 'b']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('Validation error: "emails": 0', $e->getRaw());
        }

        self::assertCount(1, $this->sent);
    }

    public function testABare500IsRetried(): void
    {
        $client = $this->client([
            new Psr7Response(503),
            $this->json(200, ['entity' => ['id' => 5]]),
        ], new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, jitter: false));

        self::assertSame(5, $client->entities('supporter')->create(['a' => 'b'])->entityId());
        self::assertCount(2, $this->sent);
    }

    public function testAConnectionFailureIsRetriedThenReportedAsTransport(): void
    {
        $client = $this->client(
            array_fill(0, 2, new ConnectException('Timed out', new Psr7Request('GET', 'entity_types'))),
            new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, jitter: false),
        );

        $this->expectException(TransportException::class);

        $client->entityTypes()->all();
    }

    public function testANonJsonSuccessBodyIsReported(): void
    {
        $client = $this->client([new Psr7Response(200, [], '<html>not json</html>')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('non-JSON body');

        $client->entities('supporter')->create(['a' => 'b']);
    }

    public function testAnEmptyBodyIsASuccessWithNothingToReport(): void
    {
        $client = $this->client([new Psr7Response(204)]);

        $response = $client->entities('supporter')->delete(7);

        self::assertSame(204, $response->status);
        self::assertNull($response->entityId());
        self::assertSame([], $response->toArray());
    }

    public function testTheEscapeHatchSendsArbitraryRequests(): void
    {
        $client = $this->client([$this->json(200, ['results' => [1, 2]])]);

        $response = $client->request('GET', 'something_custom', ['page' => 2]);

        self::assertSame([1, 2], $response->results());
        self::assertSame('/v1/account/12345/something_custom', $this->sentRequest(0)->getUri()->getPath());
        self::assertSame('page=2', $this->sentRequest(0)->getUri()->getQuery());
    }

    public function testRetryDelaysHonourRetryAfterAndBackOffExponentially(): void
    {
        $policy = new RetryPolicy(maxAttempts: 5, baseDelayMs: 100, jitter: false);
        $rateLimited = new RateLimitException('Slow down', 429, retryAfter: 20);

        self::assertSame(20_000, $policy->delayFor($rateLimited, 1));
        self::assertSame(100, $policy->delayFor(new \CoyshDigital\Beacon\Exception\ServerException('x'), 1));
        self::assertSame(200, $policy->delayFor(new \CoyshDigital\Beacon\Exception\ServerException('x'), 2));
        self::assertSame(400, $policy->delayFor(new \CoyshDigital\Beacon\Exception\ServerException('x'), 3));
    }

    public function testRetryAfterIsCappedAtTheMaximumDelay(): void
    {
        $policy = new RetryPolicy(maxDelayMs: 5_000);

        self::assertSame(5_000, $policy->delayFor(new RateLimitException('Slow down', 429, retryAfter: 600), 1));
    }
}
