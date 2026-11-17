<?php

declare(strict_types=1);

namespace G\Debug;

/**
 * Turns the collected data into the HTML fragment inserted in the page.
 */
interface RendererInterface
{
    /**
     * Complete, self-contained HTML fragment of the bar.
     */
    public function render(DebugBar $bar): string;
}
