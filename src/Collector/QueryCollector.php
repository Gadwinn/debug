<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use G\Debug\AlertingCollectorInterface;
use G\Debug\Html;
use G\Debug\TimingCollectorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use Throwable;

/**
 * SQL queries of the request, received as PSR-3 log records.
 *
 * Give it as the logger of G\Orm\Driver\LoggingConnection (gadwinn/orm), or of
 * any query logger following the same contract: message = SQL, context keys
 * 'bindings' (array), 'duration' (milliseconds) and 'exception' (on failure).
 * Identical queries (same SQL and bindings) run more than once are flagged.
 */
final class QueryCollector implements LoggerInterface, TimingCollectorInterface, AlertingCollectorInterface
{
    use LoggerTrait;

    public const NAME = 'sql';

    public const LABEL = 'SQL';

    /**
     * @var list<array{sql: string, bindings: array<mixed>, duration: float, error: ?string}>
     */
    private array $queries = [];

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
        $this->queries = [];
    }

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $bindings = $context['bindings'] ?? [];
        $duration = $context['duration'] ?? 0.0;
        $exception = $context['exception'] ?? null;

        $this->queries[] = [
            'sql' => (string) $message,
            'bindings' => is_array($bindings) ? $bindings : [],
            'duration' => is_int($duration) || is_float($duration) ? (float) $duration : 0.0,
            'error' => $exception instanceof Throwable ? $exception->getMessage() : null,
        ];
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
    }

    /**
     * @return list<array{sql: string, bindings: array<mixed>, duration: float, error: ?string}>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getDuration(): ?float
    {
        return $this->queries === [] ? null : array_sum(array_column($this->queries, 'duration'));
    }

    public function hasAlert(): bool
    {
        foreach ($this->queries as $query) {
            if ($query['error'] !== null) {
                return true;
            }
        }

        return false;
    }

    public function getSummary(): string
    {
        $count = count($this->queries);

        if ($count === 0) {
            return '';
        }

        $label = $count === 1 ? ' query' : ' queries';

        return $count . $label . ' · ' . Html::milliseconds((float) $this->getDuration());
    }

    public function getPanel(): string
    {
        if ($this->queries === []) {
            return '';
        }

        $seen = [];
        foreach ($this->queries as $query) {
            $key = self::key($query);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }

        $rows = [];
        $classes = [];
        $duplicates = 0;
        foreach ($this->queries as $index => $query) {
            $times = $seen[self::key($query)];
            $note = $query['error'] ?? ($times > 1 ? 'Run ' . $times . ' times' : '');
            $rows[] = [$index + 1, $query['sql'], $query['bindings'], Html::milliseconds($query['duration']), $note];
            $classes[] = $query['error'] !== null ? 'gd-alert' : ($times > 1 ? 'gd-warn' : '');
            $duplicates += $times > 1 ? 1 : 0;
        }

        $intro = '<p>' . Html::escape(sprintf(
            '%d queries in %s, %d identical queries repeated.',
            count($this->queries),
            Html::milliseconds((float) $this->getDuration()),
            $duplicates,
        )) . '</p>';

        return $intro . Html::table(['#', 'SQL', 'Bindings', 'Duration', 'Note'], $rows, $classes);
    }

    /**
     * @param array{sql: string, bindings: array<mixed>, duration: float, error: ?string} $query
     */
    private static function key(array $query): string
    {
        return $query['sql'] . "\0" . Html::stringify($query['bindings']);
    }
}
