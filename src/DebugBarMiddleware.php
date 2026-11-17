<?php

declare(strict_types=1);

namespace G\Debug;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Collects the data of the request and inserts the bar in HTML pages.
 *
 * Place it first in the stack, so that error pages produced by later
 * middlewares get the bar too. Every response receives a Server-Timing
 * header; the bar itself is only inserted in complete HTML pages (see
 * shouldInsert()). Exceptions thrown by the handler are not caught.
 *
 * Development only: the bar exposes internal data.
 */
final class DebugBarMiddleware implements MiddlewareInterface
{
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private const CLOSING_BODY = '</body>';

    private const STATUS_NO_CONTENT = 204;

    private const STATUS_SUCCESS_MIN = 200;

    private const STATUS_SUCCESS_MAX = 299;

    private const STATUS_CLIENT_ERROR_MIN = 400;

    public function __construct(
        private readonly DebugBar $bar,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RendererInterface $renderer = new HtmlRenderer(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        $this->bar->reset();

        $response = $handler->handle($request);

        $this->bar->collect($request, $response);
        $total = (hrtime(true) - $start) / self::NANOSECONDS_PER_MILLISECOND;

        $response = $response->withAddedHeader('Server-Timing', $this->serverTiming($total));

        return $this->shouldInsert($request, $response) ? $this->insert($response) : $response;
    }

    /**
     * Server-Timing value: the total, then one metric per timing collector.
     */
    private function serverTiming(float $total): string
    {
        $metrics = [self::metric('total', $total)];

        foreach ($this->bar->getCollectors() as $collector) {
            if ($collector instanceof TimingCollectorInterface) {
                $duration = $collector->getDuration();
                if ($duration !== null) {
                    $metrics[] = self::metric($collector->getName(), $duration);
                }
            }
        }

        return implode(', ', $metrics);
    }

    private static function metric(string $name, float $milliseconds): string
    {
        return sprintf('%s;dur=%.3F', $name, $milliseconds);
    }

    /**
     * The bar only goes into complete, uncompressed HTML pages that are not
     * redirects, empty responses or AJAX calls.
     */
    private function shouldInsert(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        $isSuccessOrError = ($status >= self::STATUS_SUCCESS_MIN && $status <= self::STATUS_SUCCESS_MAX)
            || $status >= self::STATUS_CLIENT_ERROR_MIN;

        return $isSuccessOrError
            && $status !== self::STATUS_NO_CONTENT
            && stripos($response->getHeaderLine('Content-Type'), 'text/html') === 0
            && !$response->hasHeader('Content-Encoding')
            && strcasecmp($request->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') !== 0
            && $response->getBody()->isSeekable()
            && $response->getBody()->isReadable();
    }

    private function insert(ResponseInterface $response): ResponseInterface
    {
        $body = (string) $response->getBody();
        $position = strripos($body, self::CLOSING_BODY);

        if ($position === false) {
            return $response;
        }

        $html = substr($body, 0, $position) . $this->renderer->render($this->bar) . substr($body, $position);
        $response = $response->withBody($this->streamFactory->createStream($html));

        if ($response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) strlen($html));
        }

        return $response;
    }
}
