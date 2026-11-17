<?php

declare(strict_types=1);

namespace G\Debug;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gathers one kind of data during a request and renders it as a tab of the bar.
 *
 * A collector keeps its state for the current request only: reset() is called
 * at the start of every request, so one instance can serve many requests
 * (long-running workers).
 */
interface CollectorInterface
{
    /**
     * Unique identifier: letters, digits, "-" and "_" only (e.g. 'time').
     * Also used as the Server-Timing metric name.
     */
    public function getName(): string;

    /**
     * Title of the tab (e.g. 'Time').
     */
    public function getLabel(): string;

    /**
     * Forget everything collected so far; called at the start of each request.
     */
    public function reset(): void;

    /**
     * Gather the data available at the end of the request.
     */
    public function collect(ServerRequestInterface $request, ResponseInterface $response): void;

    /**
     * Short plain text shown in the bar ('12 ms', '3 queries'); '' shows nothing.
     */
    public function getSummary(): string;

    /**
     * HTML of the panel, already escaped; '' means no panel.
     */
    public function getPanel(): string;
}
