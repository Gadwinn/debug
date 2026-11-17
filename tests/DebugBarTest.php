<?php

declare(strict_types=1);

namespace G\Debug\Tests;

use G\Debug\CollectorInterface;
use G\Debug\DebugBar;
use G\Debug\Exception\DebugExceptionInterface;
use G\Debug\Exception\InvalidArgumentException;
use G\Debug\HtmlRenderer;
use G\Debug\Tests\Fixtures\Response;
use G\Debug\Tests\Fixtures\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(DebugBar::class)]
#[CoversClass(HtmlRenderer::class)]
final class DebugBarTest extends TestCase
{
    private static function collector(string $name, string $summary = '', string $panel = ''): CollectorInterface
    {
        return new class ($name, $summary, $panel) implements CollectorInterface {
            public int $resets = 0;

            public ?ServerRequestInterface $request = null;

            public function __construct(
                private readonly string $name,
                private readonly string $summary,
                private readonly string $panel,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return '<Label ' . $this->name . '>';
            }

            public function reset(): void
            {
                $this->resets++;
                $this->request = null;
            }

            public function collect(ServerRequestInterface $request, ResponseInterface $response): void
            {
                $this->request = $request;
            }

            public function getSummary(): string
            {
                return $this->summary;
            }

            public function getPanel(): string
            {
                return $this->panel;
            }
        };
    }

    public function testCollectorsAreKeptInOrder(): void
    {
        $a = self::collector('a');
        $b = self::collector('b');

        self::assertSame([$a, $b], (new DebugBar($a))->addCollector($b)->getCollectors());
    }

    public function testDuplicateNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"a" is already registered');

        new DebugBar(self::collector('a'), self::collector('a'));
    }

    public function testMalformedNameIsRejected(): void
    {
        $this->expectException(DebugExceptionInterface::class);

        new DebugBar(self::collector('a b'));
    }

    public function testResetResetsEveryCollectorAndForgetsTheCapturedRequest(): void
    {
        $a = self::collector('a');
        $bar = new DebugBar($a);
        $bar->captureRequest(new ServerRequest());

        $bar->reset();

        self::assertSame(1, $a->resets);
        self::assertNull($bar->getCapturedRequest());
    }

    public function testCollectUsesTheCapturedRequest(): void
    {
        $a = self::collector('a');
        $bar = new DebugBar($a);
        $captured = new ServerRequest('POST');
        $bar->captureRequest($captured);

        $bar->collect(new ServerRequest(), new Response());

        self::assertSame($captured, $a->request);
    }

    public function testCollectUsesTheIncomingRequestWithoutCapture(): void
    {
        $a = self::collector('a');
        $incoming = new ServerRequest();

        (new DebugBar($a))->collect($incoming, new Response());

        self::assertSame($incoming, $a->request);
    }

    public function testRendererSkipsEmptyCollectorsAndEscapesLabelsAndSummaries(): void
    {
        $bar = new DebugBar(
            self::collector('empty'),
            self::collector('summary', '<script>alert(1)</script>'),
            self::collector('panel', '', '<p>Panel</p>'),
        );

        $html = (new HtmlRenderer())->render($bar);

        self::assertStringNotContainsString('gdebug-empty', $html);
        self::assertStringNotContainsString('Label empty', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;Label summary&gt;', $html);
        self::assertStringContainsString('<section class="gd-panel" id="gdebug-panel" hidden>', $html);
        self::assertStringContainsString('aria-controls="gdebug-panel"', $html);
        self::assertStringNotContainsString('aria-controls="gdebug-summary"', $html);
    }

    public function testRenderedFragmentIsSelfContained(): void
    {
        $html = (new HtmlRenderer())->render(new DebugBar());

        self::assertStringStartsWith('<div id="gdebug"', $html);
        self::assertStringEndsWith('</div>', $html);
        self::assertDoesNotMatchRegularExpression('/(src|href)=|@import|url\(|eval\(/', $html);
    }
}
