<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * The API key was rejected (HTTP 401 or 403, usually `invalid_api_key`).
 *
 * Beacon keys are shown once at creation and can be revoked from Settings →
 * API keys, so this usually means the key was revoked, mistyped, or belongs to
 * a different account than the one in the URL. Never retried.
 */
class AuthenticationException extends ApiException
{
}
