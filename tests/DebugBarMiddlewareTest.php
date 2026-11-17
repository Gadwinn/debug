<?php

declare(strict_types=1);

namespace G\Debug\Tests;

use G\Debug\CaptureRequestMiddleware;
use G\Debug\Collector\QueryCollector;
use G\Debug\Collector\RouteCollector;
use G\Debug\Collector\TimeMemoryCollector;
use G\Debug\DebugBar;
use G\Debug\DebugBarMiddleware;
use G\Debug\Tests\Fixtures\Handler;
use G\Debug\Tests\Fixtures\Response;
use G\Debug\Tests\Fixtures\Route;
use G\Debug\Tests\Fixtures\ServerRequest;
use G\Debug\Tests\Fixtures\Stream;
use G\Debug\Tests\Fixtures\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[CoversClass(DebugBarMiddleware::class)]
#[CoversClass(CaptureRequestMiddleware::class)]
final class DebugBarMiddlewareTest extends TestCase
{
    private DebugBar $bar;

    protected function setUp(): void
    {
        $this->bar = new DebugBar(new TimeMemoryCollector());
    }

    private function process(ResponseInterface $response, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $middleware = new DebugBarMiddleware($this->bar, new StreamFactory());

        return $middleware->process($request ?? new ServerRequest(), Handler::returning($response));
    }

    public function testBarIsInsertedBeforeTheClosingBodyTag(): void
    {
        $body = (string) $this->process(Response::html())->getBody();

        self::assertStringStartsWith('<html><body><p>Page</p><div id="gdebug"', $body);
        self::assertStringEndsWith('</div></body></html>', $body);
    }

    public function testBarIsInsertedBeforeTheLastClosingBodyTagWhateverItsCase(): void
    {
        $html = '<html><body><pre>&lt;/body&gt; </body> in a string</pre></BODY></html>';
        $body = (string) $this->process(Response::html($html))->getBody();

        self::assertSame(1, substr_count($body, 'id="gdebug"'));
        self::assertStringEndsWith('</div></BODY></html>', $body);
        self::assertStringContainsString('</body> in a string</pre><div id="gdebug"', $body);
    }

    public function testContentLengthIsRecalculatedWhenPresent(): void
    {
        $response = $this->process(Response::html(headers: ['Content-Length' => '38']));

        self::assertSame((string) strlen((string) $response->getBody()), $response->getHeaderLine('Content-Length'));
    }

    public function testContentLengthIsNotAddedWhenAbsent(): void
    {
        self::assertFalse($this->process(Response::html())->hasHeader('Content-Length'));
    }

    public function testNonHtmlResponseIsUntouched(): void
    {
        $response = $this->process(new Response(200, ['Content-Type' => 'application/json'], '{"a":"</body>"}'));

        self::assertSame('{"a":"</body>"}', (string) $response->getBody());
    }

    public function testResponseWithoutContentTypeIsUntouched(): void
    {
        $response = $this->process(new Response(200, [], '<body></body>'));

        self::assertSame('<body></body>', (string) $response->getBody());
    }

    public function testEncodedResponseIsUntouched(): void
    {
        $response = $this->process(Response::html(headers: ['Content-Encoding' => 'gzip']));

        self::assertStringNotContainsString('gdebug', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusWithoutBar(): iterable
    {
        yield 'informational' => [101];
        yield 'no content' => [204];
        yield 'not modified' => [304];
        yield 'found' => [302];
        yield 'permanent redirect' => [308];
    }

    #[DataProvider('statusWithoutBar')]
    public function testStatusWithoutBar(int $status): void
    {
        $response = $this->process(Response::html(status: $status));

        self::assertStringNotContainsString('gdebug', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusWithBar(): iterable
    {
        yield 'ok' => [200];
        yield 'not found' => [404];
        yield 'server error' => [500];
    }

    #[DataProvider('statusWithBar')]
    public function testStatusWithBar(int $status): void
    {
        $response = $this->process(Response::html(status: $status));

        self::assertStringContainsString('id="gdebug"', (string) $response->getBody());
    }

    public function testAjaxRequestGetsNoBar(): void
    {
        $request = new ServerRequest('GET', 'http://example.test/', ['X-Requested-With' => 'xmlhttprequest']);

        self::assertStringNotContainsString('gdebug', (string) $this->process(Response::html(), $request)->getBody());
    }

    public function testPageWithoutClosingBodyIsUntouched(): void
    {
        $response = $this->process(Response::html('<p>Fragment</p>'));

        self::assertSame('<p>Fragment</p>', (string) $response->getBody());
    }

    public function testNonSeekableBodyIsUntouched(): void
    {
        $response = $this->process(new Response(200, ['Content-Type' => 'text/html'], new Stream('<body></body>', false)));

        self::assertSame('<body></body>', (string) $response->getBody());
    }

    public function testServerTimingIsAddedToEveryResponse(): void
    {
        $response = $this->process(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        self::assertMatchesRegularExpression('/^total;dur=\d+\.\d{3}$/', $response->getHeaderLine('Server-Timing'));
    }

    public function testServerTimingKeepsExistingMetricsAndListsTimingCollectors(): void
    {
        $queries = new QueryCollector();
        $this->bar->addCollector($queries);
        $handler = new Handler(static function () use ($queries): ResponseInterface {
            $queries->debug('SELECT 1', ['bindings' => [], 'duration' => 1.5]);

            return new Response(200, ['Server-Timing' => 'app;dur=3']);
        });

        $response = (new DebugBarMiddleware($this->bar, new StreamFactory()))->process(new ServerRequest(), $handler);

        self::assertSame('app;dur=3', $response->getHeader('Server-Timing')[0]);
        self::assertMatchesRegularExpression(
            '/^total;dur=\d+\.\d{3}, sql;dur=1\.500$/',
            $response->getHeader('Server-Timing')[1],
        );
    }

    public function testExceptionOfTheHandlerIsNotCaught(): void
    {
        $handler = new Handler(static fn (): ResponseInterface => throw new RuntimeException('Boom'));

        $this->expectExceptionObject(new RuntimeException('Boom'));

        (new DebugBarMiddleware($this->bar, new StreamFactory()))->process(new ServerRequest(), $handler);
    }

    public function testCapturedRequestFillsTheRoutePanel(): void
    {
        $this->bar->addCollector(new RouteCollector());
        $capture = new CaptureRequestMiddleware($this->bar);
        $route = new Route('GET', '/hello/{name}', ['HelloController', 'show'], ['name' => 'Ann'], ['name' => 'hello']);
        $handler = new Handler(static fn (ServerRequestInterface $request): ResponseInterface => $capture->process(
            $request->withAttribute('route', $route),
            Handler::returning(Response::html()),
        ));

        $response = (new DebugBarMiddleware($this->bar, new StreamFactory()))->process(new ServerRequest(), $handler);
        $body = (string) $response->getBody();

        self::assertStringContainsString('HelloController::show', $body);
        self::assertStringContainsString('/hello/{name}', $body);
    }

    public function testCaptureMiddlewarePassesTheRequestOn(): void
    {
        $handler = Handler::returning(new Response());
        $request = new ServerRequest();

        (new CaptureRequestMiddleware($this->bar))->process($request, $handler);

        self::assertSame($request, $handler->request);
        self::assertSame($request, $this->bar->getCapturedRequest());
    }

    public function testSameInstanceServesSeveralRequestsWithoutLeaks(): void
    {
        $this->bar->addCollector(new RouteCollector());
        $queries = new QueryCollector();
        $this->bar->addCollector($queries);
        $middleware = new DebugBarMiddleware($this->bar, new StreamFactory());
        $capture = new CaptureRequestMiddleware($this->bar);

        $first = $middleware->process(new ServerRequest(), new Handler(
            static function (ServerRequestInterface $request) use ($capture, $queries): ResponseInterface {
                $queries->debug('SELECT first_request', ['duration' => 1.0]);

                return $capture->process(
                    $request->withAttribute('route', new Route('GET', '/first', ['A', 'a'])),
                    Handler::returning(Response::html()),
                );
            },
        ));
        $second = $middleware->process(new ServerRequest(), Handler::returning(Response::html()));

        self::assertStringContainsString('first_request', (string) $first->getBody());
        self::assertStringContainsString('/first', (string) $first->getBody());
        self::assertStringNotContainsString('first_request', (string) $second->getBody());
        self::assertStringNotContainsString('/first', (string) $second->getBody());
        self::assertNull($this->bar->getCapturedRequest());
        self::assertDoesNotMatchRegularExpression('/sql;dur/', $second->getHeaderLine('Server-Timing'));
    }
}
