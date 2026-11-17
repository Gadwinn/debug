<?php

declare(strict_types=1);

namespace G\Debug\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Records the dispatched events and returns them unchanged.
 */
final class Dispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
