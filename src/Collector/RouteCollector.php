<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use G\Debug\CollectorInterface;
use G\Debug\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The route matched for the request, read from a request attribute.
 *
 * The route object is read through its methods when they exist (getMethod,
 * getPath, getTarget, getParams, getOption('name')), so any router whose
 * route offers them works, gadwinn/router included, without depending on it.
 * Add CaptureRequestMiddleware after the router, otherwise the attribute is
 * not visible.
 */
final class RouteCollector implements CollectorInterface
{
    public const NAME = 'route';

    public const LABEL = 'Route';

    private const CALLABLE_PAIR_SIZE = 2;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $route = null;

    public function __construct(private readonly string $attribute = 'route')
    {
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
        $this->route = null;
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $route = $request->getAttribute($this->attribute);

        if (!is_object($route)) {
            $this->route = null;

            return;
        }

        $this->route = [
            'Name' => method_exists($route, 'getOption') ? $route->getOption('name') : null,
            'Method' => method_exists($route, 'getMethod') ? $route->getMethod() : null,
            'Path' => method_exists($route, 'getPath') ? $route->getPath() : null,
            'Target' => method_exists($route, 'getTarget') ? self::target($route->getTarget()) : null,
            'Parameters' => method_exists($route, 'getParams') ? $route->getParams() : null,
            'Class' => get_debug_type($route),
        ];
    }

    public function getSummary(): string
    {
        if ($this->route === null) {
            return '';
        }

        $name = $this->route['Name'];
        $path = $this->route['Path'];

        return is_string($name) && $name !== '' ? $name : (is_string($path) ? $path : 'matched');
    }

    public function getPanel(): string
    {
        return $this->route === null ? '' : Html::keyValueTable($this->route);
    }

    /**
     * Readable target: "Class::method" for [Class, method] pairs.
     */
    private static function target(mixed $target): mixed
    {
        $isPair = is_array($target) && array_is_list($target) && count($target) === self::CALLABLE_PAIR_SIZE;

        if ($isPair && is_string($target[1])) {
            $class = is_object($target[0]) ? get_debug_type($target[0]) : $target[0];

            return is_string($class) ? $class . '::' . $target[1] : $target;
        }

        return $target;
    }
}
