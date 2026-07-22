<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon;

use CoyshDigital\Beacon\Exception\ConfigurationException;
use CoyshDigital\Beacon\Http\RetryPolicy;

/**
 * Everything the client needs to reach one Beacon account.
 *
 * The account ID is the number in your Beacon API URL:
 * `https://api.beaconcrm.org/v1/account/12345`. The API key is created under
 * Settings → API keys by an administrator and shown only once — keep it
 * server-side, in an environment variable or a secrets manager.
 */
final class Config
{
    public const DEFAULT_BASE_URI = 'https://api.beaconcrm.org/v1/';

    /**
     * Beacon requires this header on every request; without it the API answers
     * as though the key were invalid.
     */
    public const APPLICATION_HEADER = 'developer_api';

    public function __construct(
        public readonly string $accountId,
        public readonly string $apiKey,
        public readonly string $baseUri = self::DEFAULT_BASE_URI,
        public readonly float $timeout = 30.0,
        public readonly ?RetryPolicy $retryPolicy = null,
    ) {
        if (trim($accountId) === '') {
            throw new ConfigurationException('A Beacon account ID is required.');
        }

        if (trim($apiKey) === '') {
            throw new ConfigurationException('A Beacon API key is required.');
        }

        if (trim($baseUri) === '') {
            throw new ConfigurationException('A Beacon base URI is required.');
        }
    }

    /**
     * The base URI every request is resolved against, with the account already
     * in the path — so callers only ever write `entity_types` or
     * `entity/person`.
     */
    public function accountUri(): string
    {
        return rtrim($this->baseUri, '/') . '/account/' . rawurlencode($this->accountId) . '/';
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Beacon-Application' => self::APPLICATION_HEADER,
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy ?? new RetryPolicy();
    }
}
