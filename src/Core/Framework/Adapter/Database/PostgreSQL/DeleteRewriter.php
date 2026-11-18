<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites the MySQL forms of DELETE that PostgreSQL does not accept.
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes).
 *
 *   DELETE FROM t WHERE w [ORDER BY o] LIMIT n  => DELETE FROM t WHERE ctid IN (SELECT ctid FROM t WHERE w [ORDER BY o] LIMIT n)
 *   DELETE a FROM t a LEFT JOIN b ON c WHERE w  => DELETE FROM t a WHERE a.ctid IN (SELECT a.ctid FROM t a LEFT JOIN b ON c WHERE w)
 *
 * Multi table deletes with more than one target are left untouched.
 *
 * @internal
 */
#[Package('framework')]
class DeleteRewriter
{
    private const IDENT = '(?:"[^"]+"|[A-Za-z0-9_]+)';

    public static function rewrite(string $sql): string
    {
        if (preg_match('/^(\s*)DELETE\s+(?!FROM\b)(' . self::IDENT . ')\s+FROM\s+(' . self::IDENT . ')(?:\s+(?:AS\s+)?(?!(?:LEFT|RIGHT|INNER|CROSS|JOIN|WHERE|ORDER|LIMIT)\b)(' . self::IDENT . '))?(\s.*)$/is', $sql, $m) === 1) {
            [, $lead, $target, $table, $alias, $rest] = $m + [4 => ''];
            $name = $alias !== '' ? $alias : $table;
            if (strcasecmp($target, $name) !== 0) {
                return $sql;
            }
            $from = $table . ($alias !== '' ? ' ' . $alias : '');
            $rest = rtrim(trim($rest), ';');

            return $lead . 'DELETE FROM ' . $from . ' WHERE ' . $name . '.ctid IN (SELECT ' . $name . '.ctid FROM ' . $from . ' ' . $rest . ')';
        }

        if (preg_match('/^(\s*)DELETE\s+FROM\s+(' . self::IDENT . '(?:\s+(?:AS\s+)?(?!(?:WHERE|ORDER|LIMIT)\b)' . self::IDENT . ')?)(\s.*)$/is', $sql, $m) === 1) {
            [, $lead, $from, $rest] = $m;
            $rest = rtrim(trim($rest), ';');
            if (UpdateRewriter::findWord($rest, 'LIMIT', 0) === null) {
                return $sql;
            }

            return $lead . 'DELETE FROM ' . $from . ' WHERE ctid IN (SELECT ctid FROM ' . $from . ' ' . $rest . ')';
        }

        return $sql;
    }
}
