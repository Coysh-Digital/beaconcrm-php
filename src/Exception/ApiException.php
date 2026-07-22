<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

use Throwable;

/**
 * A request reached Beacon and came back unsuccessful.
 *
 * Beacon reports failures as `{"error": {"code": …, "message": …, "raw": …}}`,
 * where `raw` — when present — carries the specific cause and `message` is
 * often generic. Both are exposed separately so callers can log the useful one.
 */
class ApiException extends BeaconException
{
    /**
     * @param array<string, mixed> $payload Request body, already redacted.
     */
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $raw = null,
        private readonly ?string $method = null,
        private readonly ?string $endpoint = null,
        private readonly array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * Beacon's own error code, such as `invalid_api_key` or
     * `rate_limit_exceeded`. Null when the body was not Beacon-shaped JSON.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The `error.raw` detail, which is where a validation failure actually
     * says what went wrong, e.g. `Validation error: "emails": 0`.
     */
    public function getRaw(): ?string
    {
        return $this->raw;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    /**
     * The request body that failed, with credentials already stripped. Safe to
     * log.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * A one-line summary carrying every piece of detail Beacon gave us, for
     * logging. Never includes credentials.
     */
    public function getSummary(): string
    {
        $parts = array_filter([
            $this->status !== null ? 'HTTP ' . $this->status : null,
            $this->errorCode,
            $this->getMessage(),
            $this->raw !== null ? 'Detail: ' . $this->raw : null,
        ]);

        return implode(' | ', $parts);
    }
}
