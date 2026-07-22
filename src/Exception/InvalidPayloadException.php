<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * A payload was rejected here rather than by Beacon — an empty entity body, or
 * an upsert whose lookup key is missing from the entity it is meant to match
 * on. Caught before the request goes out, so nothing is written.
 */
class InvalidPayloadException extends BeaconException
{
}
