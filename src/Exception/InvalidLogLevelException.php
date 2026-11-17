<?php

declare(strict_types=1);

namespace G\Debug\Exception;

use Psr\Log\InvalidArgumentException;

/**
 * Unknown PSR-3 level given to LogCollector (requires psr/log).
 */
final class InvalidLogLevelException extends InvalidArgumentException implements DebugExceptionInterface
{
}
