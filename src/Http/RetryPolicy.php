<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Http;

use CoyshDigital\Beacon\Exception\RateLimitException;
use CoyshDigital\Beacon\Exception\ServerException;
use CoyshDigital\Beacon\Exception\TransportException;
use Throwable;

/**
 * When and how long to wait before trying a failed request again.
 *
 * Beacon allows 300 requests a minute (60 for bulk operations) and answers a
 * breach with a 429, so backing off and retrying is the expected client
 * behaviour rather than an edge case. Only genuinely transient failures are
 * retried — see ErrorParser for why a 500 is not always one of them.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 500,
        public readonly int $maxDelayMs = 30_000,
        public readonly bool $jitter = true,
    ) {
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    public function shouldRetry(Throwable $exception, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        // A validation failure will fail identically however many times it is
        // sent, and retrying a create would duplicate the record.
        return $exception instanceof RateLimitException
            || $exception instanceof ServerException
            || $exception instanceof TransportException;
    }

    /**
     * Milliseconds to wait before attempt number `$attempt + 1`.
     *
     * Exponential backoff, with jitter so concurrent workers that hit the limit
     * together do not all wake up together and hit it again. Beacon's own
     * `Retry-After` header wins when it sends one.
     */
    public function delayFor(Throwable $exception, int $attempt): int
    {
        if ($exception instanceof RateLimitException && $exception->getRetryAfter() !== null) {
            return min($exception->getRetryAfter() * 1000, $this->maxDelayMs);
        }

        $delay = min($this->baseDelayMs * (2 ** ($attempt - 1)), $this->maxDelayMs);

        if ($this->jitter) {
            $delay = random_int((int)($delay / 2), (int)$delay);
        }

        return (int)$delay;
    }
}
