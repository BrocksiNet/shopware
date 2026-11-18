<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites the MySQL forms of UPDATE that PostgreSQL does not accept.
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes).
 *
 *   UPDATE a SET a.x = 1                        => UPDATE a SET x = 1
 *   UPDATE a, b SET a.x = b.y WHERE ...         => UPDATE a SET x = b.y FROM b WHERE ...
 *   UPDATE a INNER JOIN b ON c SET ... WHERE w  => UPDATE a SET ... FROM b WHERE (c) AND (w)
 *
 * Outer joins have no direct counterpart and are left untouched.
 *
 * @internal
 */
#[Package('framework')]
class UpdateRewriter
{
    private const START = '/^((?:\s|--[^\n]*\n|\/\*.*?\*\/)*UPDATE\s)/is';

    public static function rewrite(string $sql): string
    {
        if (preg_match(self::START, $sql, $match) !== 1) {
            return $sql;
        }

        $prefix = $match[1];
        $body = substr($sql, \strlen($prefix));

        $set = self::findWord($body, 'SET', 0);
        if ($set === null) {
            return $sql;
        }
        $where = self::findWord($body, 'WHERE', $set + 3);

        $head = trim(substr($body, 0, $set));
        $assignments = $where === null ? substr($body, $set + 3) : substr($body, $set + 3, $where - $set - 3);
        $condition = $where === null ? null : substr($body, $where + 5);

        if (self::findWord($head, 'LEFT', 0) !== null || self::findWord($head, 'RIGHT', 0) !== null) {
            return $sql;
        }

        $from = [];
        $joinConditions = [];
        $tables = self::split($head, ',');
        $target = trim((string) array_shift($tables));
        foreach ($tables as $table) {
            $from[] = trim($table);
        }

        // target [INNER] JOIN b ON c [[INNER] JOIN d ON e]
        $join = self::findWord($target, 'JOIN', 0);
        if ($join !== null) {
            $joins = substr($target, $join + 4);
            $target = trim((string) preg_replace('/\s+INNER\s*$/i', '', substr($target, 0, $join)));

            while ($joins !== '') {
                $next = self::findWord($joins, 'JOIN', 0);
                $current = $next === null ? $joins : (string) preg_replace('/\s+INNER\s*$/i', '', substr($joins, 0, $next));
                $joins = $next === null ? '' : substr($joins, $next + 4);

                $on = self::findWord($current, 'ON', 0);
                if ($on === null) {
                    return $sql;
                }
                $from[] = trim(substr($current, 0, $on));
                $joinConditions[] = '(' . trim(substr($current, $on + 2)) . ')';
            }
        }

        $set = [];
        foreach (self::split($assignments, ',') as $assignment) {
            // the column on the left side must not be qualified
            $set[] = (string) preg_replace('/^(\s*)(?:(?:"[^"]+"|[A-Za-z0-9_]+)\s*\.\s*)+((?:"[^"]+"|[A-Za-z0-9_]+)\s*=)/', '$1$2', $assignment);
        }

        $result = $prefix . $target . ' SET' . implode(',', $set);
        if ($from !== []) {
            $result = rtrim($result) . ' FROM ' . implode(', ', $from) . ' ';
        }

        if ($joinConditions !== []) {
            $result .= 'WHERE ' . implode(' AND ', $joinConditions);
            if ($condition !== null) {
                $result .= ' AND (' . trim(rtrim(trim($condition), ';')) . ')';
            }

            return $result;
        }

        return $condition === null ? $result : $result . 'WHERE' . $condition;
    }

    /**
     * Position of a keyword outside of literals, quoted identifiers and parentheses.
     */
    public static function findWord(string $sql, string $word, int $offset): ?int
    {
        foreach (self::topLevel($sql) as $position => $char) {
            if ($position < $offset || !ctype_alpha($char)) {
                continue;
            }
            if ($position > 0 && (ctype_alnum($sql[$position - 1]) || \in_array($sql[$position - 1], ['_', '.', ':', '"'], true))) {
                continue;
            }
            $length = \strlen($word);
            if (strcasecmp(substr($sql, $position, $length), $word) !== 0) {
                continue;
            }
            $after = $sql[$position + $length] ?? ' ';
            if (!ctype_alnum($after) && $after !== '_') {
                return $position;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function split(string $sql, string $separator): array
    {
        $parts = [];
        $start = 0;
        foreach (self::topLevel($sql) as $position => $char) {
            if ($char === $separator) {
                $parts[] = substr($sql, $start, $position - $start);
                $start = $position + 1;
            }
        }
        $parts[] = substr($sql, $start);

        return $parts;
    }

    /**
     * Yields position => character for everything outside of literals, quoted identifiers and parentheses.
     *
     * @return \Generator<int, string>
     */
    private static function topLevel(string $sql): \Generator
    {
        $length = \strlen($sql);
        $depth = 0;
        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];
            if ($char === '\'' || $char === '"') {
                $escaped = $char === '\'' && $i > 0 && $sql[$i - 1] === 'E';
                for (++$i; $i < $length; ++$i) {
                    if ($escaped && $sql[$i] === '\\') {
                        ++$i;
                    } elseif ($sql[$i] === $char) {
                        if (($sql[$i + 1] ?? '') !== $char) {
                            break;
                        }
                        ++$i;
                    }
                }

                continue;
            }
            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($depth === 0) {
                yield $i => $char;
            }
        }
    }
}
