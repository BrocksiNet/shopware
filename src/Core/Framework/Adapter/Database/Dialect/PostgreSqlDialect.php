<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Log\Package;

/**
 * Ids are still bound as 16 raw bytes, the IdCodecMiddleware converts them for the uuid columns.
 * Ids are bound as hex strings (`IdBinding`), PostgreSQL accepts the 32 character form for uuid columns.
 *
 * @internal
 */
#[Package('framework')]
class PostgreSqlDialect implements SqlDialect
{
    /**
     * @var array<string, list<string>>
     */
    private array $primaryKeys = [];

    /**
     * @param bool $nativeIdentifiers true (default): identifiers are quoted with PostgreSQL double quotes, the translator
     *                                recognizes them by their position (see MySQLToPostgreSQLTranslator::isQuotedIdentifier()).
     *                                false: backticks, left to the translator.
     */
    public function __construct(
        private readonly ?Connection $connection = null,
        private readonly bool $nativeIdentifiers = true
    ) {
    }

    public function getName(): string
    {
        return self::POSTGRESQL;
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (!$this->nativeIdentifiers) {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function upsert(string $table, array $columns, string $valuesSql, array $conflictColumns = [], array $updateColumns = [], bool $ignoreConflicts = false): string
    {
        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            $valuesSql
        );

        if ($updateColumns === []) {
            return $ignoreConflicts ? $sql . ' ON CONFLICT DO NOTHING' : $sql;
        }

        if ($conflictColumns === []) {
            $conflictColumns = $this->getPrimaryKey($table);
        }

        $updates = [];
        foreach ($updateColumns as $column) {
            $updates[] = \sprintf('%s = EXCLUDED.%s', $this->quoteIdentifier($column), $this->quoteIdentifier($column));
        }

        return \sprintf(
            '%s ON CONFLICT (%s) DO UPDATE SET %s',
            $sql,
            implode(', ', array_map($this->quoteIdentifier(...), $conflictColumns)),
            implode(', ', $updates)
        );
    }

    public function jsonExtract(string $jsonColumnSql, string $path): string
    {
        return \sprintf('jsonb_path_query_first(%s, %s)', $jsonColumnSql, $this->stringLiteral($path) . '::jsonpath');
    }

    public function jsonExtractText(string $jsonColumnSql, string $path): string
    {
        // `->> 0` on a one element array unquotes a json string and returns NULL for a json null.
        // The operator `#>> '{}'` does the same, but the MySQL translator reads the `#` as the start of a comment.
        return \sprintf('(jsonb_build_array(%s) ->> 0)', $this->jsonExtract($jsonColumnSql, $path));
    }

    public function jsonExtractNumber(string $jsonColumnSql, string $path): string
    {
        // The cast is a no-op for jsonb columns and needed for json stored as text (`cheapest_price_accessor`), `jsonb_path_query_first` has no text overload.
        return '(' . $this->jsonExtractText('(' . $jsonColumnSql . ')::jsonb', $path) . '::numeric)';
    }

    public function jsonSet(string $jsonColumnSql, string $path, string $valueSql): string
    {
        return \sprintf(
            'jsonb_set(COALESCE(%s, \'{}\'::jsonb), %s, %s, true)',
            $jsonColumnSql,
            $this->stringLiteral($this->toTextArrayPath($path)) . '::text[]',
            $valueSql
        );
    }

    public function jsonValue(string $jsonColumnSql, string $path, string $type = self::JSON_TYPE_STRING): string
    {
        $text = $this->jsonExtractText($jsonColumnSql, $path);

        return match ($type) {
            self::JSON_TYPE_FLOAT => $text . '::numeric',
            self::JSON_TYPE_BOOL => \sprintf('(CASE WHEN %s IS NULL THEN NULL WHEN %s IN (\'false\', \'0\') THEN 0 ELSE 1 END)', $text, $text),
            self::JSON_TYPE_DATETIME => $text . '::timestamp(3)',
            self::JSON_TYPE_DATE => $text . '::date',
            default => $text,
        };
    }

    public function jsonBind(string $placeholderSql, string $type): string
    {
        return match ($type) {
            self::JSON_BIND_INT => \sprintf('to_jsonb(%s::bigint)', $placeholderSql),
            self::JSON_BIND_FLOAT => \sprintf('to_jsonb(%s::numeric)', $placeholderSql),
            self::JSON_BIND_BOOL => $placeholderSql . '::text::jsonb',
            self::JSON_BIND_OBJECT, self::JSON_BIND_ARRAY => $placeholderSql . '::text::jsonb',
            default => \sprintf('to_jsonb(%s::text)', $placeholderSql),
        };
    }

    public function idToText(string $columnSql): string
    {
        return \sprintf('REPLACE(%s::text, \'-\', \'\')', $columnSql);
    }

    public function idParameterType(): ParameterType
    {
        return ParameterType::STRING;
    }

    public function idListParameterType(): ArrayParameterType
    {
        return ArrayParameterType::STRING;
    }

    public function groupConcat(string $expressionSql, string $separator = ',', ?string $orderBySql = null): string
    {
        return \sprintf(
            'string_agg((%s)::text, %s%s)',
            $expressionSql,
            $this->stringLiteral($separator),
            $orderBySql === null ? '' : ' ORDER BY ' . $orderBySql
        );
    }

    public function caseInsensitive(string $columnSql): string
    {
        return \sprintf('lower(%s) COLLATE ucs_basic', $columnSql);
    }

    public function caseInsensitiveStringFilters(): bool
    {
        return true;
    }

    public function sortDirectionWithMySqlNulls(string $direction): string
    {
        return mb_strtoupper($direction) === 'DESC' ? 'DESC NULLS LAST' : 'ASC NULLS FIRST';
    }

    public function caseInsensitiveValue(string $value): string
    {
        return mb_strtolower($value);
    }

    public function decimalDivisionScale(string $expressionSql, int $scale): string
    {
        return \sprintf('CAST(%s AS NUMERIC(30, %d))', $expressionSql, $scale);
    }

    public function upsertCollisionColumns(string $table, array $conflictColumns): array
    {
        return $conflictColumns !== [] ? $conflictColumns : $this->getPrimaryKey($table);
    }

    public function castToFloat(string $numberSql): string
    {
        return \sprintf('CAST(%s AS double precision)', $numberSql);
    }

    public function rowNumberPerPartition(string $partitionSql): ?string
    {
        return \sprintf('ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s)', $partitionSql, $partitionSql);
    }

    public function subqueryFence(): string
    {
        return ' OFFSET 0';
    }

    /**
     * `$.a."b-c"` => `{a,"b-c"}`
     */
    private function toTextArrayPath(string $path): string
    {
        preg_match_all('/\.(?:"((?:[^"\\\\]|\\\\.)*)"|([^.\[\]"]+))/', $path, $matches, \PREG_SET_ORDER);

        $keys = [];
        foreach ($matches as $match) {
            $key = ($match[2] ?? '') !== '' ? $match[2] : stripcslashes($match[1] ?? '');
            $keys[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $key) . '"';
        }

        return '{' . implode(',', $keys) . '}';
    }

    private function stringLiteral(string $value): string
    {
        return '\'' . str_replace('\'', '\'\'', $value) . '\'';
    }

    /**
     * @return list<string>
     */
    private function getPrimaryKey(string $table): array
    {
        if (isset($this->primaryKeys[$table])) {
            return $this->primaryKeys[$table];
        }

        if ($this->connection === null) {
            throw AdapterException::missingConflictTarget($table, 'no connection to look up the primary key');
        }

        /** @var list<string> $columns */
        $columns = $this->connection->fetchFirstColumn(
            'SELECT a.attname FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = to_regclass(:table) AND i.indisprimary
             ORDER BY array_position(i.indkey::int2[], a.attnum)',
            ['table' => $this->quoteIdentifier($table)]
        );

        if ($columns === []) {
            throw AdapterException::missingConflictTarget($table, 'the table has no primary key');
        }

        return $this->primaryKeys[$table] = $columns;
    }
}
