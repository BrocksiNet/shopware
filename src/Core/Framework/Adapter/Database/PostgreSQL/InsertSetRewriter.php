<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites the MySQL assignment form of INSERT.
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes).
 *
 *   INSERT [IGNORE] | REPLACE INTO t SET a = x, b = y [ON DUPLICATE KEY UPDATE ...] => INSERT [IGNORE] INTO t (a, b) VALUES (x, y) [ON DUPLICATE KEY UPDATE ...]
 *
 * The result is handled by the UpsertRewriter afterwards.
 *
 * @internal
 */
#[Package('framework')]
class InsertSetRewriter
{
    public static function rewrite(string $sql): string
    {
        if (preg_match('/^(\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+(?:"(?:[^"]|"")+"|[A-Za-z0-9_]+))\s+SET\s+(.*)$/isD', $sql, $m) !== 1) {
            return $sql;
        }

        $rest = rtrim(trim($m[2]), ';');
        $suffix = '';
        $position = UpdateRewriter::findWord($rest, 'ON', 0);
        if ($position !== null && preg_match('/^ON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', substr($rest, $position)) === 1) {
            $suffix = ' ' . substr($rest, $position);
            $rest = substr($rest, 0, $position);
        }

        $columns = [];
        $values = [];
        foreach (DdlRewriter::splitTopLevel($rest) as $assignment) {
            if (preg_match('/^\s*("(?:[^"]|"")+"|[A-Za-z0-9_]+)\s*=\s*(.*)$/sD', $assignment, $a) !== 1) {
                return $sql;
            }
            $columns[] = $a[1];
            $values[] = trim($a[2]);
        }

        return $m[1] . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')' . $suffix;
    }
}
