<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * Beacon rejected the payload itself — a bad drop-down value, a wrongly shaped
 * field, a record link pointing at something other than a record ID.
 *
 * This is raised for an HTTP 400, but also for a 5xx whose body carries an
 * `error.raw` detail: Beacon reports many validation failures with a 500 status
 * even though the request will never succeed as sent. Retrying those would just
 * duplicate records, so they are classified here and never retried.
 */
class ValidationException extends ApiException
{
}
