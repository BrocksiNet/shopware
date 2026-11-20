<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\Log\Package;

/**
 * @phpstan-type DataRow array{data: array<string, mixed>, types: array<string, ParameterType>|null}
 */
#[Package('framework')]
class MultiInsertQueryQueue
{
    /**
     * @var array<string, list<DataRow>>
     */
    private array $inserts = [];

    /**
     * @var array<string, list<string>>
     */
    private array $updateFieldsOnDuplicateKey = [];

    /**
     * @var array<string, list<string>>
     */
    private array $conflictTargets = [];

    /**
     * @var array<string, list<list<string>>>
     */
    private array $uniqueKeys = [];

    /**
     * @var int<1, max>
     */
    private readonly int $chunkSize;

    private ?SqlDialect $dialect;

    public function __construct(
        private readonly Connection $connection,
        int $chunkSize = 250,
        private readonly bool $ignoreErrors = false,
        private readonly bool $useReplace = false,
        ?SqlDialect $dialect = null
    ) {
        $this->dialect = $dialect;
        if ($chunkSize < 1) {
            throw DataAbstractionLayerException::invalidChunkSize($chunkSize);
        }
        $this->chunkSize = $chunkSize;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, ParameterType>|null $types
     */
    public function addInsert(string $table, array $data, ?array $types = null): void
    {
        $this->inserts[$table][] = [
            'data' => $data,
            'types' => $types,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, ParameterType>|null $types
     */
    public function addInserts(string $table, array $rows, ?array $types = null): void
    {
        foreach ($rows as $row) {
            $this->addInsert($table, $row, $types);
        }
    }

    public function execute(): void
    {
        if ($this->inserts === []) {
            return;
        }

        $queries = $this->prepareQueries();
        RetryableTransaction::retryable($this->connection, function () use ($queries): void {
            foreach ($queries as $query) {
                $this->connection->executeStatement($query['query'], $query['values'], $query['types']);
            }
        });
        unset($queries);

        $this->inserts = [];
    }

    /**
     * You can add fields which should be updated with the new values on duplicate keys
     */
    public function addUpdateFieldOnDuplicateKey(string $table, string $updateField): void
    {
        $this->updateFieldsOnDuplicateKey[$table][] = $updateField;
    }

    /**
     * MySQL reacts to a duplicate in any unique key, PostgreSQL needs one conflict target.
     * The columns are passed on as a comment (ignored by MySQL), the PostgreSQL translation uses them for `ON CONFLICT (...)`
     * instead of the primary key. Use it when the rows get a fresh primary key and collide on another unique key.
     *
     * @param list<string> $columns
     */
    public function setConflictTarget(string $table, array $columns): void
    {
        $this->conflictTargets[$table] = $columns;
    }

    /**
     * @return array<array{query: string, values: list<mixed>, types: list<ParameterType>}>
     */
    private function prepareQueries(): array
    {
        $queries = [];

        // PostgreSQL has no REPLACE, the rows that collide with a unique key are deleted before a plain insert
        $deleteBeforeInsert = $this->useReplace && $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        foreach ($this->inserts as $table => $rows) {
            $columns = $this->prepareColumns($rows);

            $updateColumns = array_values(array_intersect($this->updateFieldsOnDuplicateKey[$table] ?? [], $columns)); // only fields that are in the columns can be updated
            $conflictColumns = $updateColumns !== [] ? ($this->conflictTargets[$table] ?? []) : [];

            if ($updateColumns !== [] && !$this->useReplace) {
                $rows = $this->mergeCollidingRows($rows, $this->dialect()->upsertCollisionColumns($table, $conflictColumns), $updateColumns);
            }

            $rowsChunks = array_chunk($rows, $this->chunkSize);
            foreach ($rowsChunks as $rowsChunk) {
                if ($deleteBeforeInsert) {
                    array_push($queries, ...$this->prepareDeletes($table, $columns, $rowsChunk));
                }

                $data = $this->prepareValues($columns, $rowsChunk);
                $valuesSql = implode(', ', $data['placeholders']);

                if ($this->useReplace && !$deleteBeforeInsert) {
                    $query = \sprintf(
                        'REPLACE INTO %s (%s) VALUES %s',
                        $this->dialect()->quoteIdentifier($table),
                        implode(', ', array_map($this->dialect()->quoteIdentifier(...), $columns)),
                        $valuesSql
                    );
                } else {
                    $query = $this->dialect()->upsert($table, $columns, $valuesSql, $conflictColumns, $updateColumns, $this->ignoreErrors);
                }

                $queries[] = [
                    'query' => $query . ';',
                    'values' => $data['values'],
                    'types' => $data['types'],
                ];
            }
        }

        return $queries;
    }

    /**
     * Rows with the same key in one statement are merged, as MySQL applies them one after the other:
     * the first row is kept and the update columns take the values of the last row.
     *
     * @param list<DataRow> $rows
     * @param list<string> $keyColumns
     * @param list<string> $updateColumns
     *
     * @return list<DataRow>
     */
    private function mergeCollidingRows(array $rows, array $keyColumns, array $updateColumns): array
    {
        if ($keyColumns === []) {
            return $rows;
        }

        /** @var array<string, DataRow> $merged */
        $merged = [];
        /** @var list<DataRow> $unkeyed */
        $unkeyed = [];
        foreach ($rows as $row) {
            $key = [];
            foreach ($keyColumns as $column) {
                if (!isset($row['data'][$column])) {
                    // DEFAULT or NULL never collides
                    $unkeyed[] = $row;
                    continue 2;
                }
                $key[] = $row['data'][$column];
            }
            $hash = serialize($key);

            if (!isset($merged[$hash])) {
                $merged[$hash] = $row;
                continue;
            }

            $target = $merged[$hash];
            $targetTypes = $target['types'] ?? [];
            foreach ($updateColumns as $column) {
                if (!\array_key_exists($column, $row['data'])) {
                    continue;
                }

                $target['data'][$column] = $row['data'][$column];
                if (isset($row['types'][$column])) {
                    $targetTypes[$column] = $row['types'][$column];
                } else {
                    unset($targetTypes[$column]);
                }
            }
            $target['types'] = $target['types'] === null && $targetTypes === [] ? null : $targetTypes;
            $merged[$hash] = $target;
        }

        return [...array_values($merged), ...$unkeyed];
    }

    /**
     * One DELETE per unique key of the table that is completely part of the inserted columns.
     *
     * @param list<string> $columns
     * @param list<DataRow> $rows
     *
     * @return list<array{query: string, values: list<mixed>, types: list<ParameterType>}>
     */
    private function prepareDeletes(string $table, array $columns, array $rows): array
    {
        $queries = [];
        foreach ($this->getUniqueKeys($table) as $key) {
            if (array_diff($key, $columns) !== []) {
                continue;
            }

            $tuples = [];
            $values = [];
            $types = [];
            foreach ($rows as $row) {
                foreach ($key as $column) {
                    if (!isset($row['data'][$column])) {
                        // DEFAULT or NULL, the row can not be matched by this key
                        continue 2;
                    }
                }
                foreach ($key as $column) {
                    $values[] = $row['data'][$column];
                    $types[] = $row['types'][$column] ?? ParameterType::STRING;
                }
                $tuples[] = '(' . implode(',', array_fill(0, \count($key), '?')) . ')';
            }

            if ($tuples === []) {
                continue;
            }

            $queries[] = [
                'query' => \sprintf(
                    'DELETE FROM %s WHERE (%s) IN (%s);',
                    $this->dialect()->quoteIdentifier($table),
                    implode(', ', array_map($this->dialect()->quoteIdentifier(...), $key)),
                    implode(', ', $tuples)
                ),
                'values' => $values,
                'types' => $types,
            ];
        }

        return $queries;
    }

    /**
     * @return list<list<string>> the columns of the primary key and of every unique index
     */
    private function getUniqueKeys(string $table): array
    {
        if (isset($this->uniqueKeys[$table])) {
            return $this->uniqueKeys[$table];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.indexrelid AS index_id, a.attname AS column_name
             FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = to_regclass(:table) AND i.indisunique AND i.indpred IS NULL AND i.indexprs IS NULL',
            ['table' => '"' . $table . '"']
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row['index_id']][] = (string) $row['column_name'];
        }

        return $this->uniqueKeys[$table] = array_values($keys);
    }

    /**
     * @param list<DataRow> $rows
     *
     * @return list<string>
     */
    private function prepareColumns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['data']) as $column) {
                $columns[$column] = 1;
            }
        }

        return array_keys($columns);
    }

    /**
     * @param list<string> $columns
     * @param list<DataRow> $rows
     *
     * @return array{placeholders: list<string>, values: list<mixed>, types: list<ParameterType>}
     */
    private function prepareValues(array $columns, array $rows): array
    {
        $placeholders = [];
        $values = [];
        $types = [];

        foreach ($rows as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                if (!\array_key_exists($column, $row['data'])) { // to use default values if the column is not set
                    $rowPlaceholders[] = 'DEFAULT';
                    continue;
                }
                if ($row['data'][$column] === null) { // to insert nulls if the value is null
                    $rowPlaceholders[] = 'NULL';
                    continue;
                }

                $rowPlaceholders[] = '?';
                $values[] = $row['data'][$column];
                $types[] = $row['types'][$column] ?? ParameterType::STRING;
            }
            $placeholders[] = '(' . implode(',', $rowPlaceholders) . ')';
        }

        return [
            'placeholders' => $placeholders,
            'values' => $values,
            'types' => $types,
        ];
    }

    /**
     * Resolved on first use, the platform detection needs a database connection.
     */
    private function dialect(): SqlDialect
    {
        return $this->dialect ??= SqlDialectFactory::forConnection($this->connection);
    }
}
