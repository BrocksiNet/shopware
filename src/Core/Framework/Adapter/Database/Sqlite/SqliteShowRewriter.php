<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Shopware\Core\Framework\Log\Package;

/**
 * SHOW COLUMNS / INDEX / TABLES on top of the SQLite pragma table functions.
 *
 * @internal
 */
#[Package('framework')]
class SqliteShowRewriter
{
    private const IDENT = '(?:`[^`]+`|"[^"]+"|\w+)';

    public static function rewrite(string $sql): string
    {
        $sql = rtrim(trim($sql), ';');
        $where = static fn (string $rest): string => trim($rest) === '' ? '' : ' ' . trim($rest);

        if (preg_match('/^SHOW\s+(?:FULL\s+)?(?:COLUMNS|FIELDS)\s+(?:FROM|IN)\s+(' . self::IDENT . ')(.*)$/isD', $sql, $m)) {
            return 'SELECT * FROM (SELECT name AS "Field", type AS "Type", CASE WHEN "notnull" THEN \'NO\' ELSE \'YES\' END AS "Null",'
                . ' CASE WHEN pk > 0 THEN \'PRI\' ELSE \'\' END AS "Key", dflt_value AS "Default", \'\' AS "Extra"'
                . ' FROM pragma_table_xinfo(\'' . trim($m[1], '`"') . '\')) s' . $where($m[2]);
        }
        if (preg_match('/^SHOW\s+(?:INDEX|INDEXES|KEYS)\s+(?:FROM|IN)\s+(' . self::IDENT . ')(.*)$/isD', $sql, $m)) {
            $table = trim($m[1], '`"');

            return 'SELECT * FROM (SELECT \'' . $table . '\' AS "Table", CASE WHEN l."unique" THEN 0 ELSE 1 END AS "Non_unique",'
                . ' CASE WHEN l.origin = \'pk\' THEN \'PRIMARY\' WHEN l.name LIKE \'' . $table . '.%\' THEN substr(l.name, length(\'' . $table . '\') + 2) ELSE l.name END AS "Key_name",'
                . ' i.seqno + 1 AS "Seq_in_index", i.name AS "Column_name"'
                . ' FROM pragma_index_list(\'' . $table . '\') l, pragma_index_info(l.name) i) s' . $where($m[2]);
        }
        if (preg_match('/^SHOW\s+TABLES\b(.*)$/isD', $sql, $m)) {
            $like = preg_match('/^\s*LIKE\s+(.*)$/isD', $m[1], $l) ? ' AND name LIKE ' . $l[1] : '';

            return 'SELECT name AS "Tables_in_db" FROM sqlite_master WHERE type = \'table\'' . $like;
        }

        return $sql;
    }
}
