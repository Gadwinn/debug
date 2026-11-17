<?php

declare(strict_types=1);

namespace G\Debug\Tests\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Minimal PSR-7 server request used by the tests.
 */
final class ServerRequest implements ServerRequestInterface
{
    use MessageTrait;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed> */
    private array $query = [];

    /** @var array<string, string> */
    private array $cookies = [];

    /** @var array<mixed>|object|null */
    private array|object|null $parsedBody = null;

    private UriInterface $uri;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(private string $method = 'GET', string $uri = 'http://example.test/', array $headers = [])
    {
        $this->uri = new Uri($uri);
        $this->setHeaders($headers);
        $this->body = new Stream();
    }

    public function getRequestTarget(): string
    {
        return $this->uri->getPath();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        $new = clone $this;
        $new->method = $method;

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;

        return $new;
    }

    public function getServerParams(): array
    {
        return [];
    }

    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->cookies = $cookies;

        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        return $this;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): static
    {
        $new = clone $this;
        $new->parsedBody = $data;

        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, $value): static
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    public function withoutAttribute(string $name): static
    {
        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }
}
