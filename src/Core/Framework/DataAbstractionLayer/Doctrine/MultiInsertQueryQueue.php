<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
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

    public function __construct(
        private readonly Connection $connection,
        int $chunkSize = 250,
        private readonly bool $ignoreErrors = false,
        private readonly bool $useReplace = false
    ) {
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
        $template = 'INSERT INTO %s (%s) VALUES %s';

        if ($this->ignoreErrors) {
            $template = 'INSERT IGNORE INTO %s (%s) VALUES %s';
        }

        // PostgreSQL has no REPLACE, the rows that collide with a unique key are deleted before a plain insert
        $deleteBeforeInsert = $this->useReplace && $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($this->useReplace && !$deleteBeforeInsert) {
            $template = 'REPLACE INTO %s (%s) VALUES %s';
        }

        foreach ($this->inserts as $table => $rows) {
            $columns = $this->prepareColumns($rows);
            $escapedColumns = implode(', ', array_map(EntityDefinitionQueryHelper::escape(...), $columns));
            $escapedTable = EntityDefinitionQueryHelper::escape($table);

            $onDuplicateKey = $this->prepareOnDuplicateKeyUpdatePart(
                array_intersect($this->updateFieldsOnDuplicateKey[$table] ?? [], $columns) // only fields that are in the columns can be updated
            );
            $tableTemplate = \sprintf('%s%s%s;', $this->conflictTargetHint($table), $template, $onDuplicateKey);

            $rowsChunks = array_chunk($rows, $this->chunkSize);
            foreach ($rowsChunks as $rowsChunk) {
                if ($deleteBeforeInsert) {
                    array_push($queries, ...$this->prepareDeletes($table, $columns, $rowsChunk));
                }

                $data = $this->prepareValues($columns, $rowsChunk);
                $queries[] = [
                    'query' => \sprintf($tableTemplate, $escapedTable, $escapedColumns, implode(', ', $data['placeholders'])),
                    'values' => $data['values'],
                    'types' => $data['types'],
                ];
            }
        }

        return $queries;
    }

    private function conflictTargetHint(string $table): string
    {
        if (!isset($this->conflictTargets[$table]) || !isset($this->updateFieldsOnDuplicateKey[$table])) {
            return '';
        }

        return '/* conflict-target: ' . implode(', ', $this->conflictTargets[$table]) . ' */ ';
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
                    EntityDefinitionQueryHelper::escape($table),
                    implode(', ', array_map(EntityDefinitionQueryHelper::escape(...), $key)),
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
     * @param array<string> $fieldsToUpdate
     */
    private function prepareOnDuplicateKeyUpdatePart(array $fieldsToUpdate): string
    {
        if ($fieldsToUpdate === []) {
            return '';
        }

        $updateParts = [];
        foreach ($fieldsToUpdate as $field) {
            // see https://stackoverflow.com/a/2714653/10064036
            $updateParts[] = \sprintf('%s = VALUES(%s)', EntityDefinitionQueryHelper::escape($field), EntityDefinitionQueryHelper::escape($field));
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
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
}
