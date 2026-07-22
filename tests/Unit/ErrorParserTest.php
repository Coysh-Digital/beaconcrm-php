<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Tests\Unit;

use CoyshDigital\Beacon\Exception\ApiException;
use CoyshDigital\Beacon\Exception\AuthenticationException;
use CoyshDigital\Beacon\Exception\NotFoundException;
use CoyshDigital\Beacon\Exception\RateLimitException;
use CoyshDigital\Beacon\Exception\ServerException;
use CoyshDigital\Beacon\Exception\ValidationException;
use CoyshDigital\Beacon\Http\ErrorParser;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorParser::class)]
#[CoversClass(ApiException::class)]
#[CoversClass(RateLimitException::class)]
final class ErrorParserTest extends TestCase
{
    /**
     * @param array<string, mixed> $error
     * @param array<string, string> $headers
     */
    private function response(int $status, array $error = [], array $headers = []): Psr7Response
    {
        return new Psr7Response($status, $headers, (string)json_encode(['error' => $error]));
    }

    public function testARevokedKeyIsAnAuthenticationFailure(): void
    {
        $e = ErrorParser::fromResponse($this->response(403, [
            'code' => 'invalid_api_key',
            'message' => 'Your API key is invalid.',
        ]));

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertSame('invalid_api_key', $e->getErrorCode());
        self::assertSame(403, $e->getStatus());
        self::assertSame('Your API key is invalid.', $e->getMessage());
    }

    public function testMissingRecordsAreNotFound(): void
    {
        self::assertInstanceOf(NotFoundException::class, ErrorParser::fromResponse($this->response(404)));
    }

    public function testABadRequestIsAValidationFailure(): void
    {
        self::assertInstanceOf(ValidationException::class, ErrorParser::fromResponse($this->response(400, [
            'code' => 'invalid_value',
            'message' => 'That option is not configured for this field.',
        ])));
    }

    /**
     * Beacon reports many validation failures with a 500 and the real cause in
     * `error.raw`. Retrying one would never succeed and, on create, would
     * duplicate the record.
     */
    public function testA500CarryingARawDetailIsAValidationFailureNotATransientOne(): void
    {
        $e = ErrorParser::fromResponse($this->response(500, [
            'code' => 'unknown_error',
            'message' => 'Oh shoot! An unknown error occurred.',
            'raw' => 'Validation error: "emails": 0',
        ]));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('Validation error: "emails": 0', $e->getRaw());
        self::assertStringContainsString('Validation error', $e->getSummary());
    }

    public function testABare500IsTransient(): void
    {
        self::assertInstanceOf(ServerException::class, ErrorParser::fromResponse($this->response(503)));
    }

    public function testRateLimitsCarryRetryAfter(): void
    {
        $e = ErrorParser::fromResponse($this->response(
            429,
            ['code' => 'rate_limit_exceeded', 'message' => 'You have exceeded the rate limit for API calls.'],
            ['Retry-After' => '20'],
        ));

        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(20, $e->getRetryAfter());
    }

    public function testRateLimitsWithoutRetryAfter(): void
    {
        $e = ErrorParser::fromResponse($this->response(429));

        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertNull($e->getRetryAfter());
    }

    public function testANonJsonBodyIsKeptAsTheDetail(): void
    {
        $e = ErrorParser::fromResponse(new Psr7Response(502, [], '<html><body>Bad gateway</body></html>'));

        self::assertInstanceOf(ServerException::class, $e);
        self::assertStringContainsString('Bad gateway', (string)$e->getRaw());
        self::assertStringContainsString('HTTP 502', $e->getSummary());
    }

    public function testAnEmptyBodyStillProducesAUsefulMessage(): void
    {
        $e = ErrorParser::fromResponse(new Psr7Response(500, [], ''));

        self::assertSame('Beacon returned HTTP 500.', $e->getMessage());
        self::assertNull($e->getRaw());
    }

    public function testAStructuredRawDetailIsEncoded(): void
    {
        $e = ErrorParser::fromResponse($this->response(500, ['raw' => ['emails' => ['is invalid']]]));

        self::assertSame('{"emails":["is invalid"]}', $e->getRaw());
    }

    public function testTheFailedPayloadIsKeptButCredentialsAreRedacted(): void
    {
        $e = ErrorParser::fromResponse(
            $this->response(400),
            'POST',
            'entity/supporter',
            ['job_title' => 'Trustee', 'nested' => ['api_key' => 'secret-value', 'Token' => 'another']],
        );

        self::assertSame('POST', $e->getMethod());
        self::assertSame('entity/supporter', $e->getEndpoint());
        self::assertSame([
            'job_title' => 'Trustee',
            'nested' => ['api_key' => '[redacted]', 'Token' => '[redacted]'],
        ], $e->getPayload());
    }

    public function testLongDetailsAreTruncated(): void
    {
        $e = ErrorParser::fromResponse($this->response(500, ['raw' => str_repeat('x', 900)]));

        self::assertSame(501, mb_strlen((string)$e->getRaw()));
    }
}
