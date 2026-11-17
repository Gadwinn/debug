<?php

declare(strict_types=1);

namespace G\Debug;

/**
 * A collector that can flag a problem (error log, failed query...): its tab
 * is highlighted in the bar.
 */
interface AlertingCollectorInterface extends CollectorInterface
{
    public function hasAlert(): bool;
}
