<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Http;

use CoyshDigital\Beacon\Config;
use CoyshDigital\Beacon\Exception\ApiException;
use CoyshDigital\Beacon\Exception\TransportException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Throwable;

/**
 * Sends requests to Beacon and turns what comes back into a Response or a
 * typed exception.
 *
 * Guzzle is configured not to throw on error statuses, because Beacon's error
 * bodies carry the detail worth reporting and ErrorParser needs to read them
 * before deciding what kind of failure this was.
 */
final class Transport
{
    private readonly ClientInterface $client;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => $config->accountUri(),
            'timeout' => $config->timeout,
            'headers' => $config->headers(),
            'http_errors' => false,
        ]);
    }

    /**
     * Sends a request, retrying transient failures per the configured policy.
     */
    public function send(Request $request): Response
    {
        $policy = $this->config->retryPolicy();
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attempt($request);
            } catch (Throwable $e) {
                if (!$policy->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                usleep($policy->delayFor($e, $attempt) * 1000);
            }
        }
    }

    private function attempt(Request $request): Response
    {
        $options = [];

        if ($request->body !== null) {
            $options[RequestOptions::JSON] = $request->body;
        }

        if ($request->query !== []) {
            $options[RequestOptions::QUERY] = $request->query;
        }

        try {
            $response = $this->client->request($request->method, $request->path, $options);
        } catch (BadResponseException $e) {
            // Only reachable if a caller supplied a client with http_errors on.
            throw ErrorParser::fromResponse($e->getResponse(), $request->method, $request->path, $request->body, $e);
        } catch (GuzzleException $e) {
            throw new TransportException(
                sprintf('Could not reach Beacon for %s %s: %s', $request->method, $request->path, $e->getMessage()),
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw ErrorParser::fromResponse($response, $request->method, $request->path, $request->body);
        }

        return new Response($status, $this->decode((string)$response->getBody(), $request, $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body, Request $request, int $status): array
    {
        // A 204, or any empty body, is a success with nothing to report.
        if (trim($body) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException(
                sprintf('Beacon returned a non-JSON body for %s %s.', $request->method, $request->path),
                $status,
                null,
                mb_substr(trim($body), 0, 500),
                $request->method,
                $request->path,
                ErrorParser::redact($request->body ?? []),
                $e,
            );
        }

        return is_array($decoded) ? $decoded : ['results' => $decoded];
    }
}
