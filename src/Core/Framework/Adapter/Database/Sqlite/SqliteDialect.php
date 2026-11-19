<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Log\Package;

/**
 * Spike: the SQLite flavour of the dialect methods. Ids stay BLOB (16 raw bytes), JSON is the JSON1 extension built into SQLite.
 *
 * @internal
 */
#[Package('framework')]
class SqliteDialect implements SqlDialect
{
    /**
     * @var array<string, list<string>>
     */
    private array $primaryKeys = [];

    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    public function getName(): string
    {
        return self::SQLITE;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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

        $updates = array_map(fn (string $c) => \sprintf('%s = excluded.%s', $this->quoteIdentifier($c), $this->quoteIdentifier($c)), $updateColumns);

        return \sprintf(
            '%s ON CONFLICT (%s) DO UPDATE SET %s',
            $sql,
            implode(', ', array_map($this->quoteIdentifier(...), $conflictColumns)),
            implode(', ', $updates)
        );
    }

    public function jsonExtract(string $jsonColumnSql, string $path): string
    {
        return \sprintf('(%s -> %s)', $jsonColumnSql, $this->stringLiteral($path));
    }

    public function jsonExtractText(string $jsonColumnSql, string $path): string
    {
        return \sprintf('(%s ->> %s)', $jsonColumnSql, $this->stringLiteral($path));
    }

    public function jsonExtractNumber(string $jsonColumnSql, string $path): string
    {
        return $this->jsonExtractText($jsonColumnSql, $path);
    }

    public function jsonSet(string $jsonColumnSql, string $path, string $valueSql): string
    {
        return \sprintf('json_set(COALESCE(%s, \'{}\'), %s, %s)', $jsonColumnSql, $this->stringLiteral($path), $valueSql);
    }

    public function jsonValue(string $jsonColumnSql, string $path, string $type = self::JSON_TYPE_STRING): string
    {
        $text = $this->jsonExtractText($jsonColumnSql, $path);

        return match ($type) {
            self::JSON_TYPE_FLOAT => \sprintf('CAST(%s AS REAL)', $text),
            self::JSON_TYPE_BOOL => \sprintf('(CASE WHEN %s IS NULL THEN NULL WHEN %s IN (\'false\', \'0\') THEN 0 ELSE 1 END)', $text, $text),
            default => $text,
        };
    }

    public function jsonBind(string $placeholderSql, string $type): string
    {
        return match ($type) {
            self::JSON_BIND_INT => \sprintf('CAST(%s AS INTEGER)', $placeholderSql),
            self::JSON_BIND_FLOAT => \sprintf('CAST(%s AS REAL)', $placeholderSql),
            self::JSON_BIND_BOOL => \sprintf('json(%s)', $placeholderSql),
            self::JSON_BIND_OBJECT, self::JSON_BIND_ARRAY => \sprintf('json(%s)', $placeholderSql),
            default => $placeholderSql,
        };
    }

    public function idToText(string $columnSql): string
    {
        return \sprintf('lower(hex(%s))', $columnSql);
    }

    public function idParameterType(): ParameterType
    {
        return ParameterType::BINARY;
    }

    public function idListParameterType(): ArrayParameterType
    {
        return ArrayParameterType::BINARY;
    }

    public function groupConcat(string $expressionSql, string $separator = ',', ?string $orderBySql = null): string
    {
        // ORDER BY inside an aggregate needs SQLite 3.44
        return \sprintf(
            'group_concat(%s, %s%s)',
            $expressionSql,
            $this->stringLiteral($separator),
            $orderBySql === null ? '' : ' ORDER BY ' . $orderBySql
        );
    }

    public function caseInsensitive(string $columnSql): string
    {
        return \sprintf('lower(%s)', $columnSql);
    }

    public function subqueryFence(): string
    {
        return '';
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

        /** @var list<array{name: string, pk: int|string}> $info */
        $info = $this->connection->fetchAllAssociative('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        $info = array_filter($info, static fn (array $r) => (int) $r['pk'] > 0);
        usort($info, static fn (array $a, array $b) => (int) $a['pk'] <=> (int) $b['pk']);
        $columns = array_values(array_map(static fn (array $r) => (string) $r['name'], $info));

        if ($columns === []) {
            throw AdapterException::missingConflictTarget($table, 'the table has no primary key');
        }

        return $this->primaryKeys[$table] = $columns;
    }
}
