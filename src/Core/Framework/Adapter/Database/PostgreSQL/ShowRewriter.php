<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites the MySQL SHOW statements the core uses to catalog queries.
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes).
 * The original WHERE / LIKE clause is kept and applied to a subquery that exposes the MySQL column names.
 *
 *   SHOW [FULL] COLUMNS FROM t [WHERE ...]  => columns Field, Type, Null, Key, Default, Extra
 *   SHOW INDEX|INDEXES|KEYS FROM t [WHERE ...] => columns Table, Non_unique, Key_name, Seq_in_index, Column_name,
 *                                                the index name without the "table." prefix DdlRewriter adds, PRIMARY for the primary key
 *   SHOW TABLES [LIKE 'x']                   => column Tables_in_<schema>
 *
 * @internal
 */
#[Package('framework')]
class ShowRewriter
{
    private const IDENT = '(?:"(?:[^"]|"")+"|[A-Za-z0-9_]+)';

    /**
     * The PostgreSQL type spelled like MySQL, callers feed it back into MODIFY / CHANGE statements.
     */
    private const MYSQL_TYPE = '(CASE format_type(a.atttypid, a.atttypmod) WHEN \'uuid\' THEN \'binary(16)\' WHEN \'boolean\' THEN \'tinyint(1)\' WHEN \'jsonb\' THEN \'json\''
        . ' WHEN \'text\' THEN \'longtext\' WHEN \'bytea\' THEN \'longblob\' WHEN \'integer\' THEN \'int(11)\' WHEN \'smallint\' THEN \'smallint(6)\' WHEN \'bigint\' THEN \'bigint(20)\''
        . ' WHEN \'double precision\' THEN \'double\' WHEN \'real\' THEN \'float\' WHEN \'date\' THEN \'date\''
        . ' ELSE regexp_replace(regexp_replace(regexp_replace(regexp_replace(format_type(a.atttypid, a.atttypmod), \'^character varying\', \'varchar\'), \'^character\', \'char\'), \'^timestamp(\\(\\d\\))?.*\', \'datetime\\1\'), \'^numeric\', \'decimal\') END)';

    private const COLUMN_NAMES = ['Field', 'Type', 'Null', 'Key', 'Default', 'Extra', 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Tables_in_db'];

    public static function matches(string $sql): bool
    {
        return preg_match('/^\s*SHOW\s+(?:FULL\s+)?(?:COLUMNS|FIELDS|INDEX|INDEXES|KEYS|TABLES)\b/i', $sql) === 1;
    }

    public static function rewrite(string $sql): string
    {
        $sql = rtrim(trim($sql), ';');

        if (preg_match('/^SHOW\s+(?:FULL\s+)?(?:COLUMNS|FIELDS)\s+(?:FROM|IN)\s+(' . self::IDENT . ')(?:\s+(?:FROM|IN)\s+' . self::IDENT . ')?(.*)$/isD', $sql, $m) === 1) {
            return 'SELECT * FROM (SELECT column_name::text AS "Field", ' . self::MYSQL_TYPE . ' AS "Type",'
                . ' is_nullable::text AS "Null", \'\'::text AS "Key", (CASE WHEN a.atttypid = \'boolean\'::regtype THEN (CASE c.column_default WHEN \'true\' THEN \'1\' WHEN \'false\' THEN \'0\' ELSE c.column_default END) ELSE c.column_default END)::text AS "Default",'
                . ' (CASE WHEN c.is_identity = \'YES\' OR c.column_default LIKE \'nextval(%\' THEN \'auto_increment\' ELSE \'\' END)::text AS "Extra"'
                . ' FROM information_schema.columns c JOIN pg_attribute a ON a.attrelid = to_regclass(quote_ident(c.table_name)) AND a.attname = c.column_name'
                . ' WHERE c.table_schema = current_schema() AND c.table_name = ' . self::literal($m[1])
                . ' ORDER BY c.ordinal_position) s' . self::where($m[2]);
        }

        if (preg_match('/^SHOW\s+(?:INDEX|INDEXES|KEYS)\s+(?:FROM|IN)\s+(' . self::IDENT . ')(?:\s+(?:FROM|IN)\s+' . self::IDENT . ')?(.*)$/isD', $sql, $m) === 1) {
            return 'SELECT * FROM (SELECT t.relname::text AS "Table", CASE WHEN i.indisunique THEN 0 ELSE 1 END AS "Non_unique",'
                . ' (CASE WHEN i.indisprimary THEN \'PRIMARY\' WHEN c.relname LIKE t.relname || \'.%\' THEN substr(c.relname, length(t.relname) + 2) ELSE c.relname::text END) AS "Key_name",'
                . ' k.n AS "Seq_in_index", a.attname::text AS "Column_name"'
                . ' FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid'
                . ' JOIN pg_namespace ns ON ns.oid = t.relnamespace AND ns.nspname = current_schema()'
                . ' CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, n)'
                . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum'
                . ' WHERE t.relname = ' . self::literal($m[1]) . ') s' . self::where($m[2]);
        }

        if (preg_match('/^SHOW\s+TABLES\b(.*)$/isD', $sql, $m) === 1) {
            $rest = trim($m[1]);
            $like = preg_match('/^LIKE\s+(.*)$/isD', $rest, $l) === 1 ? ' AND tablename LIKE ' . $l[1] : '';

            return 'SELECT tablename::text AS "Tables_in_db" FROM pg_tables WHERE schemaname = current_schema()' . $like;
        }

        return $sql;
    }

    private static function literal(string $identifier): string
    {
        $name = $identifier[0] === '"' ? str_replace('""', '"', substr($identifier, 1, -1)) : strtolower($identifier);

        return '\'' . str_replace('\'', '\'\'', $name) . '\'';
    }

    /**
     * Unquoted MySQL names like `Field` are lower cased by PostgreSQL, quote them so they match the exposed columns
     */
    private static function where(string $clause): string
    {
        $clause = trim($clause);
        if ($clause === '') {
            return '';
        }

        $names = implode('|', self::COLUMN_NAMES);

        return ' ' . (preg_replace_callback('/"(' . $names . ')"|(?<![\w"])(' . $names . ')(?![\w"])/i', static function (array $m): string {
            $word = $m[2] ?? $m[1];
            foreach (self::COLUMN_NAMES as $name) {
                if (strcasecmp($name, $word) === 0) {
                    return '"' . $name . '"';
                }
            }

            return $m[0];
        }, $clause) ?? $clause);
    }
}
