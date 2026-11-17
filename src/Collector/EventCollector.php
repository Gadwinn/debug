<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use G\Debug\Html;
use G\Debug\TimingCollectorInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PSR-14 dispatcher decorator that counts the events dispatched during the
 * request and the time spent in their listeners.
 */
final class EventCollector implements EventDispatcherInterface, TimingCollectorInterface
{
    public const NAME = 'events';

    public const LABEL = 'Events';

    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    /**
     * Per event class: number of dispatches and cumulated duration (ms).
     *
     * @var array<string, array{count: int, duration: float}>
     */
    private array $events = [];

    public function __construct(private readonly EventDispatcherInterface $inner)
    {
    }

    public function dispatch(object $event): object
    {
        $start = hrtime(true);

        try {
            return $this->inner->dispatch($event);
        } finally {
            $entry = $this->events[get_debug_type($event)] ?? ['count' => 0, 'duration' => 0.0];
            $entry['count']++;
            $entry['duration'] += (hrtime(true) - $start) / self::NANOSECONDS_PER_MILLISECOND;
            $this->events[get_debug_type($event)] = $entry;
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
        $this->events = [];
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
    }

    public function getDuration(): ?float
    {
        return $this->events === [] ? null : array_sum(array_column($this->events, 'duration'));
    }

    public function getSummary(): string
    {
        $count = array_sum(array_column($this->events, 'count'));

        return $count === 0 ? '' : $count . ($count === 1 ? ' event' : ' events');
    }

    public function getPanel(): string
    {
        if ($this->events === []) {
            return '';
        }

        $rows = [];
        foreach ($this->events as $class => $entry) {
            $rows[] = [$class, $entry['count'], Html::milliseconds($entry['duration'])];
        }

        return Html::table(['Event', 'Count', 'Duration'], $rows);
    }
}
