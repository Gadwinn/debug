<?php

declare(strict_types=1);

namespace G\Debug;

/**
 * A collector that measures a duration, reported in the Server-Timing header
 * under the collector's name (e.g. "sql;dur=1.2").
 */
interface TimingCollectorInterface extends CollectorInterface
{
    /**
     * Duration in milliseconds, or null when nothing was measured.
     */
    public function getDuration(): ?float;
}
