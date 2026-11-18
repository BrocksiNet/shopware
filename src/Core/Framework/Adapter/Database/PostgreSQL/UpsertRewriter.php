<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites the MySQL upsert statements to INSERT ... ON CONFLICT.
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes).
 *
 *   INSERT IGNORE INTO t ...                    => INSERT INTO t ... ON CONFLICT DO NOTHING
 *   INSERT INTO t ... ON DUPLICATE KEY UPDATE   => INSERT INTO t ... ON CONFLICT (primary key) DO UPDATE SET ...
 *   REPLACE INTO t (columns) ...                => INSERT INTO t (columns) ... ON CONFLICT (primary key) DO UPDATE SET all other columns
 *
 * Not the same as on MySQL:
 * - the conflict target is the primary key only (or the columns named in a leading conflict-target comment),
 *   a duplicate in another unique index still raises an error; INSERT IGNORE has no target and skips every unique index
 * - INSERT IGNORE only skips duplicates, MySQL also downgrades other errors (e.g. a missing foreign key) to warnings
 * - REPLACE keeps the columns that are not part of the statement, MySQL deletes the row and resets them to their default
 *
 * @internal
 */
#[Package('framework')]
class UpsertRewriter
{
    private const START = '/^((?:\s|--[^\n]*\n|\/\*.*?\*\/)*)(INSERT\s+IGNORE|REPLACE|INSERT)\s+INTO\s+"?([A-Za-z0-9_]+)"?/is';

    private const ON_DUPLICATE = 'ON DUPLICATE KEY UPDATE';

    /**
     * @param \Closure(string): list<string> $primaryKey returns the primary key columns of a table
     */
    public static function rewrite(string $sql, \Closure $primaryKey): string
    {
        if (preg_match(self::START, $sql, $match) !== 1) {
            return $sql;
        }

        [$head, $prefix, $verb, $table] = $match;
        $verb = strtoupper((string) preg_replace('/\s+/', ' ', $verb));
        $rest = rtrim(rtrim(substr($sql, \strlen($head))), ';');
        $duplicatePosition = strripos($rest, self::ON_DUPLICATE);

        if ($verb === 'INSERT' && $duplicatePosition === false) {
            return $sql;
        }

        $insert = $prefix . 'INSERT INTO ' . self::quote($table);

        if ($verb === 'INSERT IGNORE') {
            return $insert . $rest . ' ON CONFLICT DO NOTHING';
        }

        $key = $primaryKey($table);
        if ($key === []) {
            return $sql;
        }
        // an explicit target for a table with more than one unique key: /* conflict-target: col_a, col_b */
        if (preg_match('/\/\*\s*conflict-target:\s*([A-Za-z0-9_,\s]+?)\s*\*\//', $prefix, $targetMatch) === 1) {
            $key = array_map('trim', explode(',', $targetMatch[1]));
        }
        $target = '(' . implode(', ', array_map(self::quote(...), $key)) . ')';

        if ($duplicatePosition !== false) {
            $assignments = substr($rest, $duplicatePosition + \strlen(self::ON_DUPLICATE));
            $assignments = (string) preg_replace('/\bVALUES\s*\(\s*("?[A-Za-z0-9_]+"?)\s*\)/i', 'EXCLUDED.$1', $assignments);

            $columns = [];
            if (preg_match('/^\s*\(([^)]*)\)/', $rest, $columnMatch) === 1) {
                $columns = array_map(static fn (string $column): string => strtolower(trim($column, " \t\n\r\"")), explode(',', $columnMatch[1]));
            }

            return $insert . substr($rest, 0, $duplicatePosition) . 'ON CONFLICT ' . $target . ' DO UPDATE SET' . self::qualify($assignments, $table, $columns);
        }

        if (preg_match('/^\s*\(([^)]*)\)/', $rest, $columnMatch) !== 1) {
            return $sql;
        }

        $columns = array_map(static fn (string $column): string => trim($column, " \t\n\r\""), explode(',', $columnMatch[1]));
        $update = [];
        foreach (array_diff($columns, $key) as $column) {
            $update[] = self::quote($column) . ' = EXCLUDED.' . self::quote($column);
        }

        if ($update === []) {
            return $insert . $rest . ' ON CONFLICT ' . $target . ' DO NOTHING';
        }

        return $insert . $rest . ' ON CONFLICT ' . $target . ' DO UPDATE SET ' . implode(', ', $update);
    }

    /**
     * `count = count + 1` is ambiguous on PostgreSQL, the right side can mean the existing or the EXCLUDED row.
     * MySQL reads the existing row, so the columns on the right side are qualified with the table.
     * Quoted identifiers are always qualified, bare words only if they are one of the inserted columns.
     *
     * @param list<string> $columns lower cased
     */
    private static function qualify(string $assignments, string $table, array $columns): string
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $quote = null;
        $length = \strlen($assignments);
        for ($i = 0; $i < $length; ++$i) {
            $char = $assignments[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = substr($assignments, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($assignments, $start);

        foreach ($parts as $index => $part) {
            $equals = strpos($part, '=');
            if ($equals === false) {
                continue;
            }

            $right = preg_replace_callback(
                '/\'(?:[^\']|\'\')*\'|(?<![.\w"$:])("?)([A-Za-z_][A-Za-z0-9_]*)\1(?!\s*[.(]|[\w"])/',
                static function (array $match) use ($table, $columns): string {
                    if (!isset($match[2])) {
                        return $match[0];
                    }
                    if ($match[1] === '' && !\in_array(strtolower($match[2]), $columns, true)) {
                        return $match[0];
                    }

                    return self::quote($table) . '.' . self::quote($match[2]);
                },
                substr($part, $equals + 1)
            );

            $parts[$index] = substr($part, 0, $equals + 1) . $right;
        }

        return implode(',', $parts);
    }

    private static function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }
}
