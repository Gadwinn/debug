<?php

declare(strict_types=1);

namespace G\Debug\Tests\Fixtures;

/**
 * Route object shaped like gadwinn/router's.
 */
final class Route
{
    /**
     * @param array<mixed>          $target
     * @param array<string, string> $params
     * @param array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $target,
        private readonly array $params = [],
        private readonly array $options = [],
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<mixed>
     */
    public function getTarget(): array
    {
        return $this->target;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getOption(string $option, mixed $default = null): mixed
    {
        return $this->options[$option] ?? $default;
    }
}
