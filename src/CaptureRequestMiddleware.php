<?php

declare(strict_types=1);

namespace G\Debug;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional: place it last in the stack (after the router).
 *
 * PSR-7 requests are immutable, so DebugBarMiddleware, placed first, never
 * sees the attributes added by later middlewares (route, client IP...). This
 * middleware hands the request as it reached the end of the stack to the bar.
 */
final class CaptureRequestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly DebugBar $bar)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->bar->captureRequest($request);

        return $handler->handle($request);
    }
}
