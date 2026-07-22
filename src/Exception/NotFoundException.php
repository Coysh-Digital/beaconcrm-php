<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * HTTP 404 — the account, record type key or record ID does not exist. Never
 * retried.
 */
class NotFoundException extends ApiException
{
}
