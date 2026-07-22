<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Http;

use CoyshDigital\Beacon\Exception\ApiException;
use CoyshDigital\Beacon\Exception\AuthenticationException;
use CoyshDigital\Beacon\Exception\NotFoundException;
use CoyshDigital\Beacon\Exception\RateLimitException;
use CoyshDigital\Beacon\Exception\ServerException;
use CoyshDigital\Beacon\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Turns an unsuccessful Beacon response into the right typed exception, with
 * as much detail as Beacon actually gave us.
 *
 * Beacon reports failures as `{"error": {"code": …, "message": …, "raw": …}}`.
 * The `message` is frequently generic; `raw` is where the real cause lives.
 */
final class ErrorParser
{
    /**
     * Keys whose values must never appear in a logged payload.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = ['api_key', 'apikey', 'authorization', 'password', 'secret', 'token'];

    private const REDACTED = '[redacted]';

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function fromResponse(
        ResponseInterface $response,
        ?string $method = null,
        ?string $endpoint = null,
        ?array $payload = null,
        ?Throwable $previous = null,
    ): ApiException {
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        [
            'code' => $code,
            'message' => $message,
            'raw' => $raw,
            'hasErrorDetail' => $hasErrorDetail,
        ] = self::parseBody($body);

        $message ??= 'Beacon returned HTTP ' . $status . '.';
        $safePayload = self::redact($payload ?? []);

        if ($status === 429) {
            $retryAfter = $response->getHeaderLine('Retry-After');

            return new RateLimitException(
                $message,
                $status,
                $code,
                $raw,
                $method,
                $endpoint,
                $safePayload,
                $previous,
                is_numeric($retryAfter) ? (int)$retryAfter : null,
            );
        }

        $class = match (true) {
            $status === 401, $status === 403 => AuthenticationException::class,
            $status === 404 => NotFoundException::class,
            $status >= 400 && $status < 500 => ValidationException::class,
            // A 5xx carrying an `error.raw` detail is Beacon reporting a
            // validation failure with the wrong status code — the request will
            // never succeed as sent, and retrying a create would duplicate the
            // record. This must be Beacon's own detail, not a scrap of an HTML
            // error page from a proxy in front of it, which really is transient.
            $status >= 500 && $hasErrorDetail => ValidationException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };

        return new $class($message, $status, $code, $raw, $method, $endpoint, $safePayload, $previous);
    }

    /**
     * Pulls apart an error body.
     *
     * `hasErrorDetail` says whether `raw` came from Beacon's own `error.raw`
     * property rather than being a salvaged scrap of an unparseable body. The
     * difference decides whether a 5xx is a validation failure or a transient
     * one, so the two must not be conflated.
     *
     * @return array{code: string|null, message: string|null, raw: string|null, hasErrorDetail: bool}
     */
    public static function parseBody(string $body): array
    {
        $none = ['code' => null, 'message' => null, 'raw' => null, 'hasErrorDetail' => false];

        if (trim($body) === '') {
            return $none;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Not JSON — an HTML error page, or a proxy in front of Beacon.
            // Keep a trimmed copy as the detail rather than throwing it away.
            return [...$none, 'raw' => self::truncate($body)];
        }

        if (!is_array($decoded) || !is_array($decoded['error'] ?? null)) {
            return [...$none, 'raw' => self::truncate($body)];
        }

        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;
        $raw = self::stringifyRaw($error['raw'] ?? null);

        return [
            'code' => is_string($code) && $code !== '' ? $code : null,
            'message' => is_string($message) && $message !== '' ? $message : null,
            'raw' => $raw,
            'hasErrorDetail' => $raw !== null,
        ];
    }

    private static function stringifyRaw(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if (is_scalar($raw)) {
            return self::truncate((string)$raw);
        }

        return self::truncate((string)json_encode($raw));
    }

    /**
     * Replaces anything credential-shaped so a failed payload can be logged
     * safely.
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $redacted;
    }

    private static function truncate(string $value, int $length = 500): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . '…';
    }
}
