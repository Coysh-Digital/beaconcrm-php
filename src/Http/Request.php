<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Http;

/**
 * A described-but-unsent Beacon request.
 *
 * Every resource method that sends a request has a `…Request()` twin returning
 * one of these instead, so a host framework can do the sending itself. That
 * matters when the framework wraps HTTP calls in its own events, logging, proxy
 * settings or test mode — a Craft/Formie integration, for instance, hands this
 * to Formie's `deliverPayload()` and keeps all of that intact while still
 * getting the endpoint and body shaped correctly.
 */
final class Request
{
    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
    ) {
    }

    /**
     * The path with its query string appended, relative to the account base
     * URI — the form most HTTP clients want.
     */
    public function uri(): string
    {
        if ($this->query === []) {
            return $this->path;
        }

        return $this->path . '?' . http_build_query($this->query);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function withQuery(array $query): self
    {
        return new self($this->method, $this->path, array_merge($this->query, $query), $this->body);
    }
}
