<?php

declare(strict_types=1);

namespace G\Debug\Collector;

use G\Debug\AlertingCollectorInterface;
use G\Debug\Exception\InvalidLogLevelException;
use G\Debug\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 logger that records the messages of the current request.
 *
 * Use it as the logger of the application, decorating the real one: every
 * message is also forwarded, untouched, to the logger given to the
 * constructor.
 */
final class LogCollector implements LoggerInterface, AlertingCollectorInterface
{
    use LoggerTrait;

    public const NAME = 'logs';

    public const LABEL = 'Logs';

    /**
     * Severity of each level: the lower, the more severe (RFC 5424).
     */
    private const SEVERITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    /**
     * @var list<array{level: string, message: string, context: array<mixed>, time: float}>
     */
    private array $records = [];

    private int|float $start;

    public function __construct(private readonly ?LoggerInterface $inner = null)
    {
        $this->start = hrtime(true);
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
        $this->records = [];
        $this->start = hrtime(true);
    }

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     *
     * @throws InvalidLogLevelException When the level is not a PSR-3 level.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::SEVERITY[$level])) {
            throw new InvalidLogLevelException(sprintf(
                'Unknown log level "%s".',
                is_string($level) ? $level : get_debug_type($level),
            ));
        }

        $this->records[] = [
            'level' => $level,
            'message' => self::interpolate((string) $message, $context),
            'context' => $context,
            'time' => (hrtime(true) - $this->start) / self::NANOSECONDS_PER_MILLISECOND,
        ];

        $this->inner?->log($level, $message, $context);
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): void
    {
    }

    public function hasAlert(): bool
    {
        foreach ($this->records as $record) {
            if (self::SEVERITY[$record['level']] <= self::SEVERITY[LogLevel::ERROR]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>, time: float}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function getSummary(): string
    {
        $count = count($this->records);

        return $count === 0 ? '' : $count . ($count === 1 ? ' log' : ' logs');
    }

    public function getPanel(): string
    {
        if ($this->records === []) {
            return '';
        }

        $rows = [];
        $classes = [];
        foreach ($this->records as $record) {
            $rows[] = [Html::milliseconds($record['time']), $record['level'], $record['message'], $record['context']];
            $classes[] = self::SEVERITY[$record['level']] <= self::SEVERITY[LogLevel::ERROR] ? 'gd-alert' : '';
        }

        return Html::table(['Time', 'Level', 'Message', 'Context'], $rows, $classes);
    }

    /**
     * Replace the {placeholders} of the message with the context values (PSR-3 §1.2).
     *
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = Html::stringify($value);
            }
        }

        return strtr($message, $replace);
    }
}
