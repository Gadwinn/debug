<?php

declare(strict_types=1);

namespace G\Debug\Exception;

/**
 * Invalid configuration (duplicate or malformed collector name...).
 */
final class InvalidArgumentException extends \InvalidArgumentException implements DebugExceptionInterface
{
}
