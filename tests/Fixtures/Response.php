<?php

declare(strict_types=1);

namespace G\Debug\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
    use MessageTrait;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        string|StreamInterface $body = '',
    ) {
        $this->setHeaders($headers);
        $this->body = $body instanceof StreamInterface ? $body : new Stream($body);
    }

    /**
     * HTML page response.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function html(string $body = '<html><body><p>Page</p></body></html>', int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $body);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $new = clone $this;
        $new->status = $code;

        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->status === 200 ? 'OK' : '';
    }
}
