<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * The client was set up with something it cannot work with — a missing account
 * ID or API key, or an unusable base URI. Thrown before any request is made.
 */
class ConfigurationException extends BeaconException
{
}
