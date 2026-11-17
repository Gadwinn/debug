<?php

declare(strict_types=1);

namespace G\Debug\Tests;

use G\Debug\Collector\DumpCollector;
use G\Debug\Collector\EventCollector;
use G\Debug\Collector\LogCollector;
use G\Debug\Collector\QueryCollector;
use G\Debug\Collector\RequestCollector;
use G\Debug\Collector\RouteCollector;
use G\Debug\Collector\TimeMemoryCollector;
use G\Debug\CollectorInterface;
use G\Debug\Exception\InvalidLogLevelException;
use G\Debug\Html;
use G\Debug\Tests\Fixtures\Dispatcher;
use G\Debug\Tests\Fixtures\MemoryLogger;
use G\Debug\Tests\Fixtures\Response;
use G\Debug\Tests\Fixtures\Route;
use G\Debug\Tests\Fixtures\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use stdClass;

#[CoversClass(TimeMemoryCollector::class)]
#[CoversClass(RequestCollector::class)]
#[CoversClass(RouteCollector::class)]
#[CoversClass(LogCollector::class)]
#[CoversClass(EventCollector::class)]
#[CoversClass(QueryCollector::class)]
#[CoversClass(DumpCollector::class)]
#[CoversClass(Html::class)]
final class CollectorTest extends TestCase
{
    private const XSS = '<script>alert(1)</script>';

    private static function collect(CollectorInterface $collector, ?ServerRequest $request = null): void
    {
        $collector->collect($request ?? new ServerRequest(), new Response(200, ['X-Test' => self::XSS]));
    }

    private static function assertEscaped(string $html): void
    {
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTimeMemory(): void
    {
        $collector = new TimeMemoryCollector();
        self::assertSame('', $collector->getSummary());

        $collector->reset();
        self::collect($collector);

        self::assertMatchesRegularExpression('/^\d+\.\d ms · [\d.]+ [KMG]?B$/u', $collector->getSummary());
        self::assertStringContainsString(PHP_VERSION, $collector->getPanel());

        $collector->reset();
        self::assertSame('', $collector->getPanel());
    }

    public function testRequestMasksSensitiveValues(): void
    {
        $request = (new ServerRequest('POST', 'http://user:pass@example.test/login?q=1&api_key=abc#top', [
            'Authorization' => 'Bearer s3cr3t',
            'Cookie' => 'session=s3cr3t',
            'X-Csrf-Token' => 's3cr3t',
            'Accept' => 'text/html',
        ]))
            ->withQueryParams(['q' => '1', 'api_key' => 's3cr3t'])
            ->withParsedBody(['login' => 'ann', 'Password' => 's3cr3t', 'nested' => ['client_secret' => 's3cr3t']])
            ->withCookieParams(['session' => 's3cr3t'])
            ->withAttribute('access_token', 's3cr3t')
            ->withAttribute('client_ip', '10.0.0.1');
        $collector = new RequestCollector(['login']);

        $collector->collect($request, new Response(200, ['Set-Cookie' => 'session=s3cr3t']));
        $panel = $collector->getPanel();

        self::assertSame('POST 200', $collector->getSummary());
        self::assertStringNotContainsString('s3cr3t', $panel);
        self::assertStringNotContainsString('pass@', $panel);
        self::assertStringNotContainsString('ann', $panel);
        self::assertStringContainsString('http://example.test/login', $panel);
        self::assertStringContainsString('10.0.0.1', $panel);
        self::assertStringContainsString('text/html', $panel);
        self::assertStringContainsString(RequestCollector::MASK, $panel);
    }

    public function testRequestEscapesEverything(): void
    {
        $request = (new ServerRequest('GET', 'http://example.test/', ['X-Evil' => self::XSS]))
            ->withQueryParams(['q' => self::XSS])
            ->withAttribute('a', self::XSS);
        $collector = new RequestCollector();

        self::collect($collector, $request);

        self::assertEscaped($collector->getPanel());
    }

    public function testRequestIsResetBetweenRequests(): void
    {
        $collector = new RequestCollector();
        self::collect($collector);
        $collector->reset();

        self::assertSame('', $collector->getSummary());
        self::assertSame('', $collector->getPanel());
    }

    public function testRoute(): void
    {
        $collector = new RouteCollector('matched');
        $route = new Route('GET', '/hello/{name}', [new stdClass(), 'show'], ['name' => self::XSS], ['name' => 'hello']);

        self::collect($collector, (new ServerRequest())->withAttribute('matched', $route));

        self::assertSame('hello', $collector->getSummary());
        self::assertStringContainsString('stdClass::show', $collector->getPanel());
        self::assertStringContainsString(Route::class, $collector->getPanel());
        self::assertEscaped($collector->getPanel());
    }

    public function testRouteWithoutAttributeShowsNothing(): void
    {
        $collector = new RouteCollector();
        self::collect($collector, (new ServerRequest())->withAttribute('route', 'not an object'));

        self::assertSame('', $collector->getSummary());
        self::assertSame('', $collector->getPanel());
    }

    public function testLogRecordsInterpolatesAndForwards(): void
    {
        $inner = new MemoryLogger();
        $collector = new LogCollector($inner);

        $collector->info('Hello {name}, {missing} {list}', ['name' => self::XSS, 'list' => [1]]);

        self::assertSame([['info', 'Hello {name}, {missing} {list}', ['name' => self::XSS, 'list' => [1]]]], $inner->records);
        self::assertSame('Hello ' . self::XSS . ', {missing} {list}', $collector->getRecords()[0]['message']);
        self::assertSame('1 log', $collector->getSummary());
        self::assertFalse($collector->hasAlert());
        self::assertEscaped($collector->getPanel());
    }

    public function testLogAlertsOnErrors(): void
    {
        $collector = new LogCollector();
        $collector->warning('w');
        self::assertFalse($collector->hasAlert());

        $collector->error('e');

        self::assertTrue($collector->hasAlert());
        self::assertSame('2 logs', $collector->getSummary());
        self::assertStringContainsString('class="gd-alert"', $collector->getPanel());

        $collector->reset();
        self::assertFalse($collector->hasAlert());
        self::assertSame('', $collector->getPanel());
    }

    public function testLogRejectsUnknownLevels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectException(InvalidLogLevelException::class);

        (new LogCollector())->log('loud', 'x');
    }

    public function testEventsAreForwardedAndCounted(): void
    {
        $inner = new Dispatcher();
        $collector = new EventCollector($inner);
        $event = new stdClass();

        self::assertSame($event, $collector->dispatch($event));
        $collector->dispatch(new stdClass());

        self::assertCount(2, $inner->events);
        self::assertSame('2 events', $collector->getSummary());
        self::assertIsFloat($collector->getDuration());
        self::assertStringContainsString('stdClass', $collector->getPanel());

        $collector->reset();
        self::assertNull($collector->getDuration());
        self::assertSame('', $collector->getSummary());
    }

    public function testEventPanelEscapesClassNames(): void
    {
        $collector = new EventCollector(new Dispatcher());
        $collector->dispatch(new class {
        });

        self::assertStringContainsString('class@anonymous', $collector->getPanel());
        self::assertStringNotContainsString("\0", $collector->getPanel());
    }

    public function testQueriesFollowTheOrmContract(): void
    {
        $collector = new QueryCollector();
        $collector->debug('SELECT * FROM t WHERE id = ?', ['bindings' => [1 => self::XSS], 'duration' => 2.0]);
        $collector->debug('SELECT * FROM t WHERE id = ?', ['bindings' => [1 => self::XSS], 'duration' => 1.0]);
        $collector->debug('SELECT * FROM t WHERE id = ?', ['bindings' => [1 => 'other'], 'duration' => 1]);

        self::assertSame(4.0, $collector->getDuration());
        self::assertSame('3 queries · 4.0 ms', $collector->getSummary());
        self::assertFalse($collector->hasAlert());
        self::assertSame(2, substr_count($collector->getPanel(), 'Run 2 times'));
        self::assertEscaped($collector->getPanel());
    }

    public function testFailedQueryRaisesAnAlert(): void
    {
        $collector = new QueryCollector();
        $collector->debug('INSERT', ['duration' => 0.5, 'exception' => new RuntimeException('constraint <b>')]);

        self::assertTrue($collector->hasAlert());
        self::assertStringContainsString('constraint &lt;b&gt;', $collector->getPanel());

        $collector->reset();
        self::assertNull($collector->getDuration());
        self::assertSame([], $collector->getQueries());
    }

    public function testDumpsKeepTheCallerAndEscapeByDefault(): void
    {
        $collector = new DumpCollector();
        $line = __LINE__ + 1;
        $collector->dump(self::XSS, ['a' => 1]);

        self::assertSame('2 dumps', $collector->getSummary());
        self::assertStringContainsString(__FILE__ . ':' . $line, html_entity_decode($collector->getPanel()));
        self::assertEscaped($collector->getPanel());

        $collector->reset();
        self::assertSame('', $collector->getPanel());
    }

    public function testDumpsUseTheGivenRenderer(): void
    {
        $collector = new DumpCollector(static fn (mixed $value): string => '<b>' . gettype($value) . '</b>');
        $collector->dump(1);

        self::assertStringContainsString('<b>integer</b>', $collector->getPanel());
    }

    public function testHtmlHelpers(): void
    {
        self::assertSame('null', Html::stringify(null));
        self::assertSame('false', Html::stringify(false));
        self::assertSame('1.5', Html::stringify(1.5));
        self::assertSame("[a: 'x', b: [1, 2], c: stdClass]", Html::stringify(['a' => 'x', 'b' => [1, 2], 'c' => new stdClass()]));
        self::assertSame('[[[[1 items]]]]', Html::stringify([[[[1]]]]));
        self::assertSame(Html::MAX_LENGTH + 1, mb_strlen(Html::stringify(str_repeat('é', Html::MAX_LENGTH + 10))));
        self::assertSame('512 B', Html::bytes(512));
        self::assertSame('2.0 MB', Html::bytes(2 * 1024 * 1024));
        self::assertSame('&lt;a href=&quot;x&quot;&gt;', Html::escape('<a href="x">'));
        self::assertSame('<p class="gd-muted">None</p>', Html::keyValueTable([]));
    }
}
