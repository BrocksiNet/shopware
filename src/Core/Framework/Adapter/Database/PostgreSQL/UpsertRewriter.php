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
 * - the conflict target is the primary key only, a duplicate in another unique index still raises an error
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
        $target = '(' . implode(', ', array_map(self::quote(...), $key)) . ')';

        if ($duplicatePosition !== false) {
            $assignments = substr($rest, $duplicatePosition + \strlen(self::ON_DUPLICATE));
            $assignments = (string) preg_replace('/\bVALUES\s*\(\s*("?[A-Za-z0-9_]+"?)\s*\)/i', 'EXCLUDED.$1', $assignments);

            return $insert . substr($rest, 0, $duplicatePosition) . 'ON CONFLICT ' . $target . ' DO UPDATE SET' . $assignments;
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

    private static function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }
}
