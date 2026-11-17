<?php

declare(strict_types=1);

namespace G\Debug;

use G\Debug\Exception\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Holds the collectors of the bar and the request they describe.
 *
 * All state lives in this object and its collectors and is cleared by reset()
 * at the start of each request: one instance can serve many requests.
 */
final class DebugBar
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]+$/D';

    /**
     * @var array<string, CollectorInterface>
     */
    private array $collectors = [];

    private ?ServerRequestInterface $capturedRequest = null;

    /**
     * @throws InvalidArgumentException When two collectors share a name or a name is malformed.
     */
    public function __construct(CollectorInterface ...$collectors)
    {
        foreach ($collectors as $collector) {
            $this->addCollector($collector);
        }
    }

    /**
     * @throws InvalidArgumentException When the name is already used or malformed.
     */
    public function addCollector(CollectorInterface $collector): static
    {
        $name = $collector->getName();

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid collector name "%s": use letters, digits, "-" and "_" only.',
                $name,
            ));
        }

        if (isset($this->collectors[$name])) {
            throw new InvalidArgumentException(sprintf('A collector named "%s" is already registered.', $name));
        }

        $this->collectors[$name] = $collector;

        return $this;
    }

    /**
     * @return list<CollectorInterface>
     */
    public function getCollectors(): array
    {
        return array_values($this->collectors);
    }

    /**
     * Start a new request: forget the captured request and reset every collector.
     */
    public function reset(): void
    {
        $this->capturedRequest = null;

        foreach ($this->collectors as $collector) {
            $collector->reset();
        }
    }

    /**
     * Remember the request as seen deep in the middleware stack (with the
     * attributes added by the router and other middlewares).
     */
    public function captureRequest(ServerRequestInterface $request): void
    {
        $this->capturedRequest = $request;
    }

    /**
     * The request given to captureRequest() during the current request, if any.
     */
    public function getCapturedRequest(): ?ServerRequestInterface
    {
        return $this->capturedRequest;
    }

    /**
     * Let every collector gather its data, using the captured request when
     * there is one.
     */
    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $request = $this->capturedRequest ?? $request;

        foreach ($this->collectors as $collector) {
            $collector->collect($request, $response);
        }
    }
}
