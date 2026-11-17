<?php

declare(strict_types=1);

namespace G\Debug;

use UnitEnum;

/**
 * Pure helpers for collectors: escaping, value formatting and tables.
 *
 * Every method returning HTML escapes what it is given; values are turned
 * into short plain text first (objects by their class, never serialized),
 * and long values are truncated.
 */
final class Html
{
    /**
     * Longest text shown for a single value, in characters.
     */
    public const MAX_LENGTH = 500;

    /**
     * Deepest array level shown inline.
     */
    public const MAX_DEPTH = 3;

    private const ELLIPSIS = '…';

    private const TABLE_OPEN = '<table>';

    private const EMPTY_LIST = '<p class="gd-muted">None</p>';

    private const EMPTY_ARRAY = '[]';

    private const BYTES_PER_KILOBYTE = 1024;

    private const UNITS = ['B', 'KB', 'MB', 'GB'];

    /**
     * Escape text for HTML content and attribute values.
     */
    public static function escape(string $text): string
    {
        // A NUL byte (anonymous class names...) is replaced, so that it never reaches the page.
        return htmlspecialchars(str_replace("\0", "\u{FFFD}", $text), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Short plain-text representation of any value (not escaped).
     */
    public static function stringify(mixed $value): string
    {
        return self::truncate(self::describe($value, 0));
    }

    /**
     * Cut a text to MAX_LENGTH characters.
     */
    public static function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_LENGTH) . self::ELLIPSIS;
    }

    /**
     * Two-column table: one row per key.
     *
     * @param array<array-key, mixed> $rows
     */
    public static function keyValueTable(array $rows): string
    {
        if ($rows === []) {
            return self::empty();
        }

        $html = self::TABLE_OPEN;
        foreach ($rows as $key => $value) {
            $html .= '<tr><th>' . self::escape((string) $key) . '</th><td>' . self::escape(self::stringify($value))
                . '</td></tr>';
        }

        return $html . '</table>';
    }

    /**
     * Table with a header row; every cell is stringified and escaped.
     *
     * @param list<string>              $headers
     * @param list<array<array-key, mixed>> $rows
     * @param list<string>              $rowClasses Optional CSS class per row (same index as $rows).
     */
    public static function table(array $headers, array $rows, array $rowClasses = []): string
    {
        if ($rows === []) {
            return self::empty();
        }

        $html = self::TABLE_OPEN . '<tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . self::escape($header) . '</th>';
        }
        $html .= '</tr>';

        foreach ($rows as $index => $row) {
            $class = $rowClasses[$index] ?? '';
            $html .= $class !== '' ? '<tr class="' . self::escape($class) . '">' : '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . self::escape(self::stringify($cell)) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * Section title followed by already-built HTML.
     */
    public static function section(string $title, string $html): string
    {
        return '<h3>' . self::escape($title) . '</h3>' . $html;
    }

    /**
     * Placeholder shown for an empty list.
     */
    public static function empty(): string
    {
        return self::EMPTY_LIST;
    }

    /**
     * Milliseconds, e.g. "12.3 ms".
     */
    public static function milliseconds(float $milliseconds): string
    {
        return sprintf('%.1F ms', $milliseconds);
    }

    /**
     * Bytes in a readable unit, e.g. "2.0 MB".
     */
    public static function bytes(int $bytes): string
    {
        $size = (float) $bytes;
        $unit = 0;
        $last = count(self::UNITS) - 1;

        while ($size >= self::BYTES_PER_KILOBYTE && $unit < $last) {
            $size /= self::BYTES_PER_KILOBYTE;
            $unit++;
        }

        return $unit === 0 ? $bytes . ' B' : sprintf('%.1F %s', $size, self::UNITS[$unit]);
    }

    private static function describe(mixed $value, int $depth): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => var_export($value, true),
            is_string($value) => $depth === 0 ? $value : "'" . self::truncate($value) . "'",
            is_array($value) => self::describeArray($value, $depth),
            $value instanceof UnitEnum => get_debug_type($value) . '::' . $value->name,
            is_object($value) => get_debug_type($value),
            default => get_debug_type($value),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function describeArray(array $value, int $depth): string
    {
        if ($value === []) {
            return self::EMPTY_ARRAY;
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[' . count($value) . ' items]';
        }

        $parts = [];
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $text = self::describe($item, $depth + 1);
            $parts[] = $isList ? $text : $key . ': ' . $text;
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
