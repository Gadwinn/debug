<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use G\Debug\CollectorInterface;
use G\Debug\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Duration of the request, memory peak, PHP version and SAPI.
 *
 * The duration runs from reset() (start of the request) to collect(); the
 * memory peak is reset at the start of each request so that a long-running
 * worker reports the peak of the current request only.
 */
final class TimeMemoryCollector implements CollectorInterface
{
    public const NAME = 'time';

    public const LABEL = 'Time';

    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private int|float $start = 0;

    private ?float $duration = null;

    private int $memoryPeak = 0;

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
        memory_reset_peak_usage();
        $this->start = hrtime(true);
        $this->duration = null;
        $this->memoryPeak = 0;
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->duration = (hrtime(true) - $this->start) / self::NANOSECONDS_PER_MILLISECOND;
        $this->memoryPeak = memory_get_peak_usage();
    }

    public function getSummary(): string
    {
        if ($this->duration === null) {
            return '';
        }

        return Html::milliseconds($this->duration) . ' · ' . Html::bytes($this->memoryPeak);
    }

    public function getPanel(): string
    {
        if ($this->duration === null) {
            return '';
        }

        return Html::keyValueTable([
            'Duration' => Html::milliseconds($this->duration),
            'Memory peak' => Html::bytes($this->memoryPeak),
            'Memory limit' => (string) ini_get('memory_limit'),
            'PHP' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
        ]);
    }
}
