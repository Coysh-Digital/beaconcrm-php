<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

use Throwable;

/**
 * HTTP 429 — Beacon's rate limit was hit: 300 requests per minute for standard
 * calls, 60 per minute for bulk operations.
 *
 * The transport already retries these according to its RetryPolicy; seeing this
 * exception means the retries were also exhausted.
 */
class RateLimitException extends ApiException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        ?string $raw = null,
        ?string $method = null,
        ?string $endpoint = null,
        array $payload = [],
        ?Throwable $previous = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $errorCode, $raw, $method, $endpoint, $payload, $previous);
    }

    /**
     * Seconds to wait before trying again, when Beacon sent a `Retry-After`
     * header.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
