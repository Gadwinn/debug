# gadwinn/debug

> **Development only.** The bar shows internal data of your application (headers, attributes, SQL, logs...). Never enable it in production: it is never active by default, enable it explicitly, for instance only when `APP_ENV=dev`.

A lightweight debug bar for PSR-15 applications: time and memory, request and response, matched route, PSR-3 logs, PSR-14 events, SQL queries and dumps. Each panel is optional, and you can write your own.

- No file written, no storage, no extra route, no external asset: CSS and JS are inlined in the fragment.
- No global or static state: one instance can serve many requests (FrankenPHP worker mode, RoadRunner...).
- Every response gets a standard `Server-Timing` header, visible in the browser's developer tools, even for JSON APIs.
- Everything displayed is escaped, and sensitive values (authorization headers, cookies, passwords, tokens, keys) are masked.

## Installation

```bash
composer require gadwinn/debug
```

It is a regular dependency on purpose: the configuration decides whether the bar is active (for instance with `APP_ENV`), so production code never refers to a missing class.

Requires PHP 8.3+ and a PSR-7 / PSR-17 implementation (for instance `gadwinn/psr-http-message`). `psr/log` and `psr/event-dispatcher` are only needed by the collectors that use them.

## Usage with any PSR-15 stack

```php
use G\Debug\CaptureRequestMiddleware;
use G\Debug\Collector\RequestCollector;
use G\Debug\Collector\RouteCollector;
use G\Debug\Collector\TimeMemoryCollector;
use G\Debug\DebugBar;
use G\Debug\DebugBarMiddleware;

$bar = new DebugBar(
    new TimeMemoryCollector(),
    new RequestCollector(),
    new RouteCollector(),          // reads the 'route' request attribute
);

$middlewares = [
    new DebugBarMiddleware($bar, $streamFactory), // first: error pages get the bar too
    // ... your middlewares, then the router ...
    new CaptureRequestMiddleware($bar),           // last: see the attributes added by the others
];
```

`DebugBarMiddleware` resets the bar, calls the rest of the stack, collects, adds `Server-Timing`, and inserts the bar just before the last `</body>` of the page. The bar is only inserted when all of these hold:

- `Content-Type` is `text/html` and there is no `Content-Encoding`;
- the status is 2xx (except 204), 4xx or 5xx: never 1xx, 204, 304 or a redirect;
- the request has no `X-Requested-With: XMLHttpRequest` header;
- the body is seekable and contains `</body>` (case-insensitive).

`Content-Length` is recalculated when present. Exceptions thrown by the stack are not caught.

`CaptureRequestMiddleware` is optional. PSR-7 requests are immutable, so the first middleware never sees the attributes added by the next ones (route, client IP...). Placed last, it hands the final request to the bar; without it, panels show the incoming request and the route panel stays empty. Place it where the request still reaches it: after the middleware that matches the route, before the one that answers.

## Usage with `gadwinn/framework`

`config/services.php`:

```php
use G\Debug\Collector\{DumpCollector, LogCollector, QueryCollector, RequestCollector, RouteCollector, TimeMemoryCollector};
use G\Debug\{CaptureRequestMiddleware, DebugBar, DebugBarMiddleware};
use G\Router\Middleware\RouterMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

$dev = ($_ENV['APP_ENV'] ?? 'prod') === 'dev';

return [
    LogCollector::class => fn (ContainerInterface $c) => new LogCollector(new MyLogger()),
    // In dev, the application logs through the collector, which forwards to the real logger.
    LoggerInterface::class => fn (ContainerInterface $c) => $dev ? $c->get(LogCollector::class) : new MyLogger(),
    DebugBar::class => fn (ContainerInterface $c) => new DebugBar(
        new TimeMemoryCollector(),
        new RequestCollector(),
        new RouteCollector(RouterMiddleware::ROUTE_ATTRIBUTE_NAME),
        $c->get(LogCollector::class),
        $c->get(QueryCollector::class),
        $c->get(DumpCollector::class),
    ),
];
```

`config/middlewares.php`:

```php
$dev = ($_ENV['APP_ENV'] ?? 'prod') === 'dev';

return [
    ...($dev ? [DebugBarMiddleware::class] : []),
    // ... other middlewares, router ...
    ...($dev ? [CaptureRequestMiddleware::class] : []),
];
```

`DebugBarMiddleware` and `CaptureRequestMiddleware` are autowired: the container gives them the shared `DebugBar` and the `StreamFactoryInterface` of the application.

## Collectors

All in `G\Debug\Collector`. A collector whose summary and panel are both empty does not appear.

| Class | Shows | Notes |
|---|---|---|
| `TimeMemoryCollector` | duration, memory peak, PHP version, SAPI | summary `12.0 ms · 2.0 MB`; the memory peak is reset at the start of each request |
| `RequestCollector(array $extraSensitiveFields = [])` | method, URI, headers, query, parsed body, cookies, attributes, status and response headers | masks (`***`) `Authorization`, `Cookie`, `Set-Cookie`, `Proxy-Authorization`, every cookie value, and every field whose name contains `password`, `passwd`, `secret`, `token` or `key` (plus your own fragments) |
| `RouteCollector(string $attribute = 'route')` | name, method, path, target, parameters of the matched route | reads the route object through `getOption('name')`, `getMethod()`, `getPath()`, `getTarget()`, `getParams()` when they exist (no dependency on a router) |
| `LogCollector(?LoggerInterface $inner = null)` | PSR-3 messages: time, level, interpolated message, context | is a `LoggerInterface`; forwards every message, untouched, to `$inner`. Its tab is highlighted when a message is `error` or worse |
| `EventCollector(EventDispatcherInterface $inner)` | PSR-14 events: class, count, cumulated duration | is an `EventDispatcherInterface` decorating `$inner`; `events` metric in `Server-Timing` |
| `QueryCollector` | SQL queries: text, bindings, duration, total; flags identical queries run more than once and failed queries | is a `LoggerInterface` reading the query-log contract of `gadwinn/orm`; `sql` metric in `Server-Timing` |
| `DumpCollector(?Closure $renderer = null)` | values passed to `$dumps->dump($a, $b)`, with the caller's file and line | uses `gdump()` of `gadwinn/dump` when installed, otherwise an escaped `print_r()`; no global function: inject the collector where you need it |

Long values are truncated and objects are shown by their class, never serialized.

## SQL panel

`QueryCollector` is the logger to give to the query-logging connection of `gadwinn/orm`:

```php
use G\Debug\Collector\QueryCollector;
use G\Orm\Core\DbManager;
use G\Orm\Driver\LoggingConnection;
use G\Orm\Driver\Pdo\PdoDriver;
use G\Orm\Factory\OrmFactory;

$queries = new QueryCollector(); // the same instance as in the DebugBar
$connection = (new PdoDriver())->connect(OrmFactory::normalise($_ENV['DATABASE_URL']));
$orm = DbManager::create(new LoggingConnection($connection, $queries));
```

Any other query logger works if it follows the same contract: one PSR-3 record per query, the SQL as message, and the context keys `bindings` (array), `duration` (milliseconds) and `exception` (on failure).

## Writing a collector

Implement `G\Debug\CollectorInterface`:

```php
use G\Debug\CollectorInterface;
use G\Debug\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CacheCollector implements CollectorInterface
{
    private int $hits = 0;

    public function hit(): void { $this->hits++; }

    public function getName(): string { return 'cache'; }          // letters, digits, - and _
    public function getLabel(): string { return 'Cache'; }
    public function reset(): void { $this->hits = 0; }             // start of every request
    public function collect(ServerRequestInterface $request, ResponseInterface $response): void {}
    public function getSummary(): string { return $this->hits . ' hits'; }  // plain text, escaped by the renderer
    public function getPanel(): string                              // HTML: escape everything yourself
    {
        return Html::keyValueTable(['Hits' => $this->hits]);
    }
}
```

- Keep all state in the collector and clear it in `reset()`.
- `getPanel()` returns HTML: build it with the `G\Debug\Html` helpers (`escape()`, `keyValueTable()`, `table()`, `section()`), which escape what they are given.
- Implement `TimingCollectorInterface` (`getDuration(): ?float`, milliseconds) to add a `Server-Timing` metric, and `AlertingCollectorInterface` (`hasAlert(): bool`) to highlight the tab.
- Two collectors with the same name throw `G\Debug\Exception\InvalidArgumentException`.

To change the look of the bar entirely, implement `RendererInterface` and pass it as the third argument of `DebugBarMiddleware`.

## Content Security Policy

The fragment uses an inline `<style>` and an inline `<script>`. A page whose Content-Security-Policy forbids them will show the bar unstyled and without interaction.

## Quality

```bash
composer test-all   # security audit, phpcs (PSR-12), phpstan (max), phpunit, phpmnd
```

## License

MIT
