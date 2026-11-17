<?php

declare(strict_types=1);

namespace G\Debug\Tests\Fixtures;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handler running a closure; records the request it received.
 */
final class Handler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $handle
     */
    public function __construct(private readonly Closure $handle)
    {
    }

    public static function returning(ResponseInterface $response): self
    {
        return new self(static fn (): ResponseInterface => $response);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return ($this->handle)($request);
    }
}
