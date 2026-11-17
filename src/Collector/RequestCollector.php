<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use G\Debug\CollectorInterface;
use G\Debug\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request and response: method, URI, headers, query, parsed body, cookies,
 * attributes, status.
 *
 * Sensitive values are masked: the headers of SENSITIVE_HEADERS, every cookie
 * value, and every field (header, query, body, attribute) whose name contains
 * one of the SENSITIVE_FIELDS fragments, case-insensitively.
 */
final class RequestCollector implements CollectorInterface
{
    public const NAME = 'request';

    public const LABEL = 'Request';

    public const MASK = '***';

    public const SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization'];

    public const SENSITIVE_FIELDS = ['password', 'passwd', 'secret', 'token', 'key'];

    /**
     * @var list<string>
     */
    private readonly array $sensitiveFields;

    /**
     * @var array{
     *     method: string, uri: string, status: int, reason: string,
     *     requestHeaders: array<string, string>, query: array<array-key, mixed>, body: mixed,
     *     cookies: array<array-key, string>, attributes: array<array-key, mixed>,
     *     responseHeaders: array<string, string>
     * }|null
     */
    private ?array $data = null;

    /**
     * @param list<string> $extraSensitiveFields Name fragments to mask in addition to SENSITIVE_FIELDS.
     */
    public function __construct(array $extraSensitiveFields = [])
    {
        $this->sensitiveFields = array_values(array_unique(array_map(
            strtolower(...),
            [...self::SENSITIVE_FIELDS, ...$extraSensitiveFields],
        )));
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
        $this->data = null;
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $uri = $request->getUri()->withQuery('')->withFragment('')->withUserInfo('');
        $body = $request->getParsedBody();

        $this->data = [
            'method' => $request->getMethod(),
            'uri' => (string) $uri,
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'requestHeaders' => $this->maskHeaders($request->getHeaders()),
            'query' => $this->mask($request->getQueryParams()),
            'body' => is_array($body) ? $this->mask($body) : $body,
            'cookies' => array_map(static fn (): string => self::MASK, $request->getCookieParams()),
            'attributes' => $this->mask($request->getAttributes()),
            'responseHeaders' => $this->maskHeaders($response->getHeaders()),
        ];
    }

    public function getSummary(): string
    {
        if ($this->data === null) {
            return '';
        }

        return $this->data['method'] . ' ' . $this->data['status'];
    }

    public function getPanel(): string
    {
        if ($this->data === null) {
            return '';
        }

        $data = $this->data;
        $body = $data['body'];

        return Html::section('Request', Html::keyValueTable([
            'Method' => $data['method'],
            'URI' => $data['uri'],
            'Status' => $data['status'] . ' ' . $data['reason'],
        ]))
            . Html::section('Request headers', Html::keyValueTable($data['requestHeaders']))
            . Html::section('Query', Html::keyValueTable($data['query']))
            . Html::section('Parsed body', is_array($body) ? Html::keyValueTable($body) : Html::keyValueTable(
                $body === null ? [] : ['Body' => $body],
            ))
            . Html::section('Cookies', Html::keyValueTable($data['cookies']))
            . Html::section('Attributes', Html::keyValueTable($data['attributes']))
            . Html::section('Response headers', Html::keyValueTable($data['responseHeaders']));
    }

    /**
     * @param array<array-key, array<string>> $headers
     *
     * @return array<string, string>
     */
    private function maskHeaders(array $headers): array
    {
        $masked = [];
        foreach ($headers as $name => $values) {
            $name = (string) $name;
            $sensitive = in_array(strtolower($name), self::SENSITIVE_HEADERS, true) || $this->isSensitive($name);
            $masked[$name] = $sensitive ? self::MASK : implode(', ', $values);
        }

        return $masked;
    }

    /**
     * Mask the sensitive keys, recursively.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $values[$key] = self::MASK;
            } elseif (is_array($value)) {
                $values[$key] = $this->mask($value);
            }
        }

        return $values;
    }

    private function isSensitive(string $name): bool
    {
        $name = strtolower($name);

        foreach ($this->sensitiveFields as $fragment) {
            if ($fragment !== '' && str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
