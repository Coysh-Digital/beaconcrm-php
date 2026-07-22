<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * A genuine 5xx from Beacon — one with no `error.raw` detail, meaning the
 * request may well succeed if sent again. Retried by the transport.
 */
class ServerException extends ApiException
{
}
