<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Gives information_schema.columns and information_schema.tables the shape they have on MySQL.
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator.
 *
 * - table_schema holds the database name on MySQL (the schema is always "public" here)
 * - columns gets EXTRA, COLUMN_TYPE, COLUMN_KEY and MySQL data type names
 * - tables gets ENGINE, TABLE_ROWS and TABLE_COLLATION
 *
 * @internal
 */
#[Package('framework')]
class InformationSchemaRewriter
{
    private const COLUMNS = <<<'SQL'
(SELECT c.table_catalog AS table_schema, c.table_name, c.column_name, c.ordinal_position, c.column_default, c.is_nullable,
    CASE c.udt_name
        WHEN 'uuid' THEN 'binary' WHEN 'bool' THEN 'tinyint' WHEN 'jsonb' THEN 'json' WHEN 'int2' THEN 'smallint' WHEN 'int4' THEN 'int'
        WHEN 'int8' THEN 'bigint' WHEN 'float8' THEN 'double' WHEN 'float4' THEN 'float' WHEN 'numeric' THEN 'decimal' WHEN 'bytea' THEN 'longblob'
        WHEN 'varchar' THEN 'varchar' WHEN 'bpchar' THEN 'char' WHEN 'text' THEN 'longtext' WHEN 'timestamp' THEN 'datetime' WHEN 'timestamptz' THEN 'datetime'
        ELSE c.udt_name
    END AS data_type,
    c.character_maximum_length, c.numeric_precision, c.numeric_scale, c.datetime_precision, c.collation_name,
    CASE c.udt_name
        WHEN 'uuid' THEN 'binary(16)' WHEN 'bool' THEN 'tinyint(1)' WHEN 'jsonb' THEN 'json' WHEN 'int2' THEN 'smallint' WHEN 'int4' THEN 'int'
        WHEN 'int8' THEN 'bigint' WHEN 'varchar' THEN 'varchar(' || c.character_maximum_length || ')' WHEN 'text' THEN 'longtext'
        WHEN 'timestamp' THEN 'datetime(' || c.datetime_precision || ')' WHEN 'numeric' THEN 'decimal(' || c.numeric_precision || ',' || c.numeric_scale || ')'
        ELSE c.udt_name
    END AS column_type,
    CASE WHEN c.is_generated = 'ALWAYS' THEN 'STORED GENERATED' WHEN c.is_identity = 'YES' THEN 'auto_increment' ELSE '' END AS extra,
    '' AS column_key,
    c.generation_expression
    FROM information_schema.columns c WHERE c.table_schema = current_schema())
SQL;

    private const TABLES = <<<'SQL'
(SELECT t.table_catalog AS table_schema, t.table_name, CASE t.table_type WHEN 'BASE TABLE' THEN 'BASE TABLE' ELSE t.table_type END AS table_type,
    'InnoDB' AS engine, 0 AS table_rows, 'utf8mb4_unicode_ci' AS table_collation
    FROM information_schema.tables t WHERE t.table_schema = current_schema())
SQL;

    public static function matches(string $sql): bool
    {
        // Doctrine's own PostgreSQL schema manager asks the real information_schema, it filters by table_catalog (MySQL has no such column)
        return preg_match('/\binformation_schema\s*\.\s*(?:columns|tables)\b/i', $sql) === 1 && stripos($sql, 'table_catalog') === false;
    }

    public static function rewrite(string $sql): string
    {
        return preg_replace_callback(
            '/\binformation_schema\s*\.\s*(columns|tables)\b(\s+(?:AS\s+)?(?!(?:WHERE|JOIN|LEFT|RIGHT|INNER|CROSS|ON|GROUP|ORDER|LIMIT|UNION|HAVING)\b)[A-Za-z_]\w*)?/i',
            static fn (array $m): string => (strtolower($m[1]) === 'columns' ? self::COLUMNS : self::TABLES) . (($m[2] ?? '') !== '' ? $m[2] : ' AS ' . strtolower($m[1])),
            $sql
        ) ?? $sql;
    }
}
