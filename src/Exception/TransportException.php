<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Exception;

/**
 * The request never produced an HTTP response — DNS failure, connection
 * timeout, TLS problem. Retried by the transport, since it is usually
 * transient.
 */
class TransportException extends BeaconException
{
}
