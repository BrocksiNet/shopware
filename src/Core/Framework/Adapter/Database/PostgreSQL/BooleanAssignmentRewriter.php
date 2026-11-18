<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * MySQL has no boolean type, BOOL is TINYINT(1), so the core and every migration write 0 and 1 (or PHP false as '') to boolean columns.
 * PostgreSQL has no assignment cast from integer to boolean, this rewriter converts the values of boolean columns in writes:
 *
 *   INSERT INTO t (a, flag) VALUES (?, 1), (?, ?)  => INSERT INTO t (a, flag) VALUES (?, TRUE), (?, mysql_compat_to_bool(?))
 *   UPDATE t SET flag = 0 WHERE ...                => UPDATE t SET flag = FALSE WHERE ...
 *   ... ON CONFLICT (id) DO UPDATE SET flag = ?    => ... ON CONFLICT (id) DO UPDATE SET flag = mysql_compat_to_bool(?)
 *
 * mysql_compat_to_bool() is overloaded in mysql-compat.sql for boolean, integer, bigint, numeric and text.
 * Expects a statement in PostgreSQL form (after the translator and the other rewriters).
 *
 * @internal
 */
#[Package('framework')]
class BooleanAssignmentRewriter
{
    private const IDENT = '"(?:[^"]|"")+"|[A-Za-z0-9_]+';

    private const PREFIX = '\s*(?:\/\*.*?\*\/\s*)*';

    /**
     * @param \Closure(string): list<string> $booleanColumns names of the boolean columns of a table
     */
    public static function rewrite(string $sql, \Closure $booleanColumns): string
    {
        if (preg_match('/^(' . self::PREFIX . 'INSERT\s+INTO\s+(' . self::IDENT . ')\s*\(([^()]*)\)\s*VALUES\s*)(.*)\z/is', $sql, $m) === 1) {
            $booleans = $booleanColumns(self::unquote($m[2]));

            return $booleans === [] ? $sql : (self::rewriteInsert($m[1], $m[3], $m[4], $booleans) ?? $sql);
        }

        if (preg_match('/^(' . self::PREFIX . 'UPDATE\s+(?:ONLY\s+)?(' . self::IDENT . ')(?:\s+(?:AS\s+)?(?!SET\b)(?:' . self::IDENT . '))?\s+SET\s)(.*)\z/is', $sql, $m) === 1) {
            $booleans = $booleanColumns(self::unquote($m[2]));

            return $booleans === [] ? $sql : $m[1] . self::rewriteAssignmentClause($m[3], $booleans);
        }

        return $sql;
    }

    /**
     * @param list<string> $booleans
     */
    private static function rewriteInsert(string $head, string $columnList, string $rest, array $booleans): ?string
    {
        $columns = array_map(static fn (string $column): string => self::unquote(trim($column)), DdlRewriter::splitTopLevel($columnList));
        $positions = array_keys(array_intersect($columns, $booleans));
        if ($positions === []) {
            return null;
        }

        $end = self::firstWord($rest, ['ON', 'RETURNING']) ?? \strlen($rest);
        $rows = DdlRewriter::splitTopLevel(substr($rest, 0, $end));
        foreach ($rows as $index => $row) {
            if (preg_match('/^(\s*)\((.*)\)(\s*)\z/s', $row, $r) !== 1) {
                return null;
            }
            $values = DdlRewriter::splitTopLevel($r[2]);
            if (\count($values) !== \count($columns)) {
                return null;
            }
            foreach ($positions as $position) {
                $values[$position] = self::convert($values[$position]);
            }
            $rows[$index] = $r[1] . '(' . implode(',', $values) . ')' . $r[3];
        }

        $tail = substr($rest, $end);
        $doUpdate = preg_match('/^(ON\s+CONFLICT\b.*?\bDO\s+UPDATE\s+SET\s)(.*)\z/is', $tail, $t) === 1;

        return $head . implode(',', $rows) . ($doUpdate ? $t[1] . self::rewriteAssignmentClause($t[2], $booleans) : $tail);
    }

    /**
     * "a = x, b = y [FROM ...] [WHERE ...] [RETURNING ...]", only the assignments are rewritten
     *
     * @param list<string> $booleans
     */
    private static function rewriteAssignmentClause(string $clause, array $booleans): string
    {
        $end = self::firstWord($clause, ['FROM', 'WHERE', 'RETURNING']) ?? \strlen($clause);
        $assignments = DdlRewriter::splitTopLevel(substr($clause, 0, $end));
        foreach ($assignments as $index => $assignment) {
            if (preg_match('/^(\s*(?:(?:' . self::IDENT . ')\.)?(' . self::IDENT . ')\s*=)(.*)\z/s', $assignment, $a) !== 1) {
                continue;
            }
            if (\in_array(self::unquote($a[2]), $booleans, true)) {
                $assignments[$index] = $a[1] . self::convert($a[3]);
            }
        }

        return implode(',', $assignments) . substr($clause, $end);
    }

    private static function convert(string $value): string
    {
        $expression = trim($value);
        $before = substr($value, 0, \strlen($value) - \strlen(ltrim($value)));
        $after = substr($value, \strlen(rtrim($value)));

        $converted = match (true) {
            $expression === '0' => 'FALSE',
            $expression === '1' => 'TRUE',
            preg_match('/^(?:TRUE|FALSE|NULL|DEFAULT)\z/i', $expression) === 1, str_starts_with($expression, 'mysql_compat_to_bool(') => $expression,
            default => 'mysql_compat_to_bool(' . $expression . ')',
        };

        return $before . $converted . $after;
    }

    /**
     * @param list<string> $words
     */
    private static function firstWord(string $sql, array $words): ?int
    {
        $positions = array_filter(array_map(static fn (string $word): ?int => UpdateRewriter::findWord($sql, $word, 0), $words), static fn (?int $p): bool => $p !== null);

        return $positions === [] ? null : min($positions);
    }

    private static function unquote(string $identifier): string
    {
        return str_starts_with($identifier, '"') ? str_replace('""', '"', substr($identifier, 1, -1)) : $identifier;
    }
}
