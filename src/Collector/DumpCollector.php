<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use Closure;
use G\Debug\CollectorInterface;
use G\Debug\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Values dumped during the request, shown in a panel instead of the page.
 *
 * Inject the collector where it is needed and call $dumps->dump($a, $b).
 * Each value is rendered when dump() is called (its state at that moment):
 * by the renderer given to the constructor, which must return safe HTML; by
 * default gdump() of gadwinn/dump when it is installed, otherwise an escaped
 * print_r()/var_export() in a <pre> block.
 */
final class DumpCollector implements CollectorInterface
{
    public const NAME = 'dumps';

    public const LABEL = 'Dumps';

    /**
     * @var (Closure(mixed): string)|null
     */
    private readonly ?Closure $renderer;

    /**
     * @var list<array{file: string, line: int, html: string}>
     */
    private array $dumps = [];

    /**
     * @param (Closure(mixed): string)|null $renderer Returns the HTML of one value (already escaped).
     */
    public function __construct(?Closure $renderer = null)
    {
        $this->renderer = $renderer;
    }

    /**
     * Record one or more values, with the file and line of the caller.
     */
    public function dump(mixed ...$values): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        foreach ($values as $value) {
            $this->dumps[] = [
                'file' => $caller['file'] ?? '',
                'line' => $caller['line'] ?? 0,
                'html' => $this->render($value),
            ];
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return self::LABEL;
    }

    public function reset(): void
    {
        $this->dumps = [];
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
    }

    public function getSummary(): string
    {
        $count = count($this->dumps);

        return $count === 0 ? '' : $count . ($count === 1 ? ' dump' : ' dumps');
    }

    public function getPanel(): string
    {
        $html = '';
        foreach ($this->dumps as $dump) {
            $html .= '<div class="gd-dump"><p class="gd-muted">' . Html::escape($dump['file'] . ':' . $dump['line'])
                . '</p>' . $dump['html'] . '</div>';
        }

        return $html;
    }

    private function render(mixed $value): string
    {
        if ($this->renderer !== null) {
            return ($this->renderer)($value);
        }

        // gdump() renders HTML (escaped) except on the command line, where it renders plain text.
        if (function_exists('gdump') && PHP_SAPI !== 'cli') {
            $html = gdump($value);

            return is_string($html) ? $html : '';
        }

        $text = is_array($value) || is_object($value) ? print_r($value, true) : var_export($value, true);

        return '<pre>' . Html::escape($text) . '</pre>';
    }
}
