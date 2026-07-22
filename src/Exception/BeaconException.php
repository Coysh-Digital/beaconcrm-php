<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by this library, so a consumer can
 * catch all Beacon problems with a single catch block.
 */
class BeaconException extends RuntimeException
{
}
