<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\AggregationAware;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Aggregation;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Max;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Stats;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\EqualsAny;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\Schema\ResolvedField;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * Read store on a SQL database with a JSON column. One table per generation (`<prefix>_<generation>`), one keyword table
 * per generation (`<prefix>_<generation>_kw`) and one meta table (`<prefix>_meta`) with the live pointer.
 * The queries are built by the {@see SqlQueryCompiler} for the {@see JsonDialect} of the engine.
 *
 * @internal
 */
#[Package('framework')]
final class SqlStorage implements Storage, FilterAware, SearchAware, AggregationAware
{
    private readonly SqlQueryCompiler $compiler;

    private ?\Collator $collator = null;

    public function __construct(
        private readonly JsonDialect $dialect,
        private readonly Connection $connection,
        private readonly FieldResolver $resolver = new FieldResolver(),
        private readonly string $prefix = 'rs',
    ) {
        $this->compiler = new SqlQueryCompiler($dialect, $resolver);
    }

    public function setup(string $generation): void
    {
        $this->prepare();
        $this->assertName($generation);
        $this->createMeta();
        $this->dialect->setupDatabase($this->connection);

        $this->dropTables($generation);
        foreach ([...$this->dialect->createDocumentTable($this->table($generation)), ...$this->dialect->createKeywordTable($this->keywordTable($generation))] as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->setMeta('gen:' . $generation, '1');
    }

    public function swap(string $generation): void
    {
        $this->prepare();
        $this->assertName($generation);
        if (!\in_array($generation, $this->generations(), true)) {
            throw ReadStoreException::unknownGeneration($generation);
        }

        $old = $this->liveGeneration();
        $this->setMeta('live', $generation);
        if ($old !== null && $old !== $generation) {
            $this->dropTables($old);
            $this->connection->executeStatement('DELETE FROM ' . $this->dialect->quote($this->metaTable()) . ' WHERE name = :n', ['n' => 'gen:' . $old]);
        }
    }

    public function liveGeneration(): ?string
    {
        $this->prepare();

        return $this->meta('live');
    }

    public function store(array $documents, ?string $generation = null): void
    {
        $this->prepare();
        foreach ($this->targets($generation) as $target) {
            $this->connection->transactional(function () use ($documents, $target): void {
                foreach (array_chunk($documents, 200) as $chunk) {
                    $this->storeChunk($chunk, $target);
                }
            });
        }
    }

    public function remove(array $ids): void
    {
        $this->prepare();
        foreach ($this->targets(null) as $target) {
            foreach (array_chunk(array_values($ids), 500) as $chunk) {
                $params = [];
                foreach ($chunk as $id) {
                    $params[] = strtolower((string) $id);
                }
                $marks = implode(', ', array_fill(0, \count($params), '?'));
                $this->connection->executeStatement('DELETE FROM ' . $this->dialect->quote($this->table($target)) . ' WHERE id IN (' . $marks . ')', $params);
                $this->connection->executeStatement('DELETE FROM ' . $this->dialect->quote($this->keywordTable($target)) . ' WHERE product_id IN (' . $marks . ')', $params);
            }
        }
    }

    public function get(string $id): ?array
    {
        return $this->mget([$id])[$id] ?? null;
    }

    public function mget(array $ids): array
    {
        $this->prepare();
        $live = $this->liveGeneration();
        if ($live === null || $ids === []) {
            return [];
        }

        $documents = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $params = array_map(static fn (string $id): string => strtolower($id), $chunk);
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT id, doc FROM ' . $this->dialect->quote($this->table($live)) . ' WHERE id IN (' . implode(', ', array_fill(0, \count($params), '?')) . ')',
                $params
            );
            foreach ($chunk as $id) {
                if (isset($rows[strtolower($id)])) {
                    $documents[$id] = json_decode((string) $rows[strtolower($id)], true, 512, \JSON_THROW_ON_ERROR);
                }
            }
        }

        return $documents;
    }

    public function clear(): void
    {
        $this->prepare();
        $live = $this->liveGeneration();
        if ($live === null) {
            return;
        }

        $this->connection->executeStatement('DELETE FROM ' . $this->dialect->quote($this->table($live)));
        $this->connection->executeStatement('DELETE FROM ' . $this->dialect->quote($this->keywordTable($live)));
    }

    public function destroy(): void
    {
        $this->prepare();
        foreach ($this->generations() as $generation) {
            $this->dropTables($generation);
        }
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->dialect->quote($this->metaTable()));
    }

    public function filter(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search !== null) {
            throw NotSupportedByEngine::feature($this->dialect->name(), 'a search term in filter(), use search()');
        }

        return $this->query($criteria, $context);
    }

    public function search(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search === null) {
            throw ReadStoreException::invalidCriteria('search() needs a search term');
        }

        return $this->query($criteria, $context);
    }

    public function aggregate(StoreCriteria $criteria, StoreContext $context): array
    {
        $scope = $this->scope();

        return $this->aggregations($criteria, $context, $scope);
    }

    private function query(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        $this->prepare();
        $scope = $this->scope();
        $aggregations = $this->aggregations($criteria, $context, $scope);

        $limit = $criteria->limit;
        $total = null;
        if ($criteria->total === Total::EXACT) {
            $total = $this->total($criteria, $context, $scope);
        }
        if ($limit === 0) {
            return new StoreResult([], $total, $aggregations, []);
        }

        $binder = new Binder();
        $where = $this->where($criteria, $context, $binder, $scope, true);
        $score = $criteria->search !== null ? $this->compiler->score($criteria->search, $binder, $scope) : '0';
        $keys = $this->compiler->sortKeys($criteria->sortings, $context, $binder, $scope, 'sc');
        $base = $this->baseOrder($criteria, $scope, $binder);

        if ($criteria->groupField !== null) {
            $sql = $this->collapseSql($criteria, $context, $binder, $scope, $where, $score, $keys, $base);
        } else {
            $order = [];
            foreach ($keys as $key) {
                $order[] = $this->compiler->orderTerm($key, $key->expression);
            }
            $order = $keys === [] ? [$base] : [...$order, $scope->alias . '.id'];
            $sql = 'SELECT ' . $scope->alias . '.id AS id, ' . $score . ' AS sc FROM ' . $this->dialect->quote($scope->documents) . ' ' . $scope->alias
                . ' WHERE ' . $where . ' ORDER BY ' . implode(', ', $order) . $this->dialect->limit($limit, $criteria->offset);
        }

        $rows = $this->connection->fetchAllAssociative($sql, $binder->params(), $binder->types());
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        $documents = $this->fetchDocuments($scope, $ids);

        $scores = [];
        if ($criteria->search !== null) {
            foreach ($rows as $row) {
                if (isset($documents[$row['id']])) {
                    $scores[(string) $documents[$row['id']]['id']] = (float) $row['sc'];
                }
            }
        }

        return new StoreResult(array_values($documents), $total, $aggregations, $scores);
    }

    /**
     * @param list<SqlSortKey> $keys
     */
    private function collapseSql(StoreCriteria $criteria, StoreContext $context, Binder $binder, SqlScope $scope, string $where, string $score, array $keys, string $base): string
    {
        $group = $this->compiler->groupKey((string) $criteria->groupField, $context, $binder, $scope);
        $columns = [$scope->alias . '.id AS id', $group . ' AS g', $score . ' AS sc', $base . ' AS bo'];
        $names = [];
        foreach ($keys as $index => $key) {
            if ($key->expression === 'sc') {
                $names[$index] = 'sc';
                continue;
            }
            $names[$index] = 'k' . $index;
            $columns[] = $key->expression . ' AS k' . $index;
        }
        $hits = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $this->dialect->quote($scope->documents) . ' ' . $scope->alias . ' WHERE ' . $where;

        $windowOrder = [];
        $aggregates = [];
        $outer = [];
        foreach ($keys as $index => $key) {
            $windowOrder[] = $this->compiler->orderTerm($key, $names[$index]);
            $aggregates[] = ($key->takesMinimum ? 'MIN(' : 'MAX(') . $this->compiler->compared($key, $names[$index]) . ') OVER (PARTITION BY g) AS a' . $index;
            $outer[] = $this->dialect->orderTerm('a' . $index, $key->direction);
        }
        $windowOrder[] = $keys === [] ? 'bo' : 'id';

        return 'WITH hits AS (' . $hits . '), ranked AS (SELECT id, sc, ROW_NUMBER() OVER (PARTITION BY g ORDER BY ' . implode(', ', $windowOrder) . ') AS rn'
            . ($aggregates === [] ? '' : ', ' . implode(', ', $aggregates)) . ' FROM hits) SELECT id, sc FROM ranked WHERE rn = 1 ORDER BY '
            . implode(', ', [...$outer, 'id']) . $this->dialect->limit($criteria->limit, $criteria->offset);
    }

    private function total(StoreCriteria $criteria, StoreContext $context, SqlScope $scope): int
    {
        $binder = new Binder();
        $where = $this->where($criteria, $context, $binder, $scope, true);
        if ($criteria->groupField === null) {
            $sql = 'SELECT COUNT(*) FROM ' . $this->dialect->quote($scope->documents) . ' d WHERE ' . $where;
        } else {
            $group = $this->compiler->groupKey($criteria->groupField, $context, $binder, $scope);
            $sql = 'SELECT COUNT(DISTINCT g) + COALESCE(MAX(CASE WHEN g IS NULL THEN 1 ELSE 0 END), 0) FROM (SELECT ' . $group . ' AS g FROM ' . $this->dialect->quote($scope->documents) . ' d WHERE ' . $where . ') t';
        }

        return (int) $this->connection->fetchOne($sql, $binder->params(), $binder->types());
    }

    /**
     * Filters, ids and the search term, plus the post filters for the hits.
     *
     * @param list<Filter> $extra
     */
    private function where(StoreCriteria $criteria, StoreContext $context, Binder $binder, SqlScope $scope, bool $withPost, array $extra = []): string
    {
        $filters = [...$criteria->filters, ...$extra];
        if ($criteria->ids !== []) {
            $filters[] = new EqualsAny('id', $criteria->ids);
        }

        $conditions = $this->compiler->conditions($filters, $context, $binder, $scope);
        if ($criteria->search !== null) {
            $conditions[] = $this->compiler->search($criteria->search, $context, $binder, $scope);
        }
        if ($withPost) {
            $conditions = [...$conditions, ...$this->compiler->conditions($criteria->postFilters, $context, $binder, $scope)];
        }

        return $conditions === [] ? 'TRUE' : implode(' AND ', array_map(static fn (string $condition): string => '(' . $condition . ')', $conditions));
    }

    /**
     * The order of hits without sortings: the position in `ids` (without a search term), otherwise the insertion order.
     */
    private function baseOrder(StoreCriteria $criteria, SqlScope $scope, Binder $binder): string
    {
        if ($criteria->ids === [] || $criteria->search !== null) {
            return $this->dialect->position($scope->alias);
        }

        // the last occurrence of an id wins, like the reference
        $cases = [];
        $seen = [];
        foreach (array_reverse(array_values($criteria->ids), true) as $position => $id) {
            $lower = strtolower($id);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $cases[] = 'WHEN ' . $binder->bind($lower) . ' THEN ' . $position;
        }

        return 'CASE ' . $scope->alias . '.id ' . implode(' ', $cases) . ' END';
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>> keyed by the id column, in the order of $ids
     */
    private function fetchDocuments(SqlScope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows += $this->connection->fetchAllKeyValue(
                'SELECT id, doc FROM ' . $this->dialect->quote($scope->documents) . ' WHERE id IN (' . implode(', ', array_fill(0, \count($chunk), '?')) . ')',
                $chunk
            );
        }

        $documents = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $documents[$id] = json_decode((string) $rows[$id], true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        return $documents;
    }

    /**
     * @return array<string, AggregationResult>
     */
    private function aggregations(StoreCriteria $criteria, StoreContext $context, SqlScope $scope): array
    {
        $results = [];
        foreach ($criteria->aggregations as $aggregation) {
            $results[$aggregation->name] = $this->aggregation($criteria, $aggregation, $context, $scope);
        }

        return $results;
    }

    private function aggregation(StoreCriteria $criteria, Aggregation $aggregation, StoreContext $context, SqlScope $scope): AggregationResult
    {
        $field = $this->resolver->resolve($aggregation->field, $context);
        if ($aggregation instanceof Terms) {
            return $this->terms($criteria, $aggregation, $field, $context, $scope);
        }

        if ($field->isList() || $field->type === FieldType::ANY) {
            throw NotSupportedByEngine::field($aggregation->field, 'metrics are not answered for list and custom fields');
        }

        $binder = new Binder();
        $where = $this->where($criteria, $context, $binder, $scope, false, $aggregation->filters);
        $from = ' FROM ' . $this->dialect->quote($scope->documents) . ' d WHERE ' . $where;
        $value = $this->compiler->native($field, $binder, $scope);
        $numeric = \in_array($field->type, [FieldType::INT, FieldType::FLOAT, FieldType::BOOL], true);

        if ($aggregation instanceof Count) {
            $distinct = $numeric ? $value : match ($field->type) {
                FieldType::ID, FieldType::DATETIME => $this->dialect->binaryLower($value),
                default => $this->dialect->ciKey($value),
            };

            return new MetricResult($aggregation->name, (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT ' . $distinct . ')' . $from, $binder->params(), $binder->types()));
        }

        if ($aggregation instanceof Min || $aggregation instanceof Max) {
            $function = $aggregation instanceof Min ? 'MIN' : 'MAX';
            $expression = match (true) {
                $numeric => $value,
                \in_array($field->type, [FieldType::ID, FieldType::DATETIME], true) => $this->dialect->binaryLower($value),
                default => throw NotSupportedByEngine::field($aggregation->field, 'the minimum and maximum of a collated string depends on the engine collation'),
            };
            $raw = $this->connection->fetchOne('SELECT ' . $function . '(' . $expression . ')' . $from, $binder->params(), $binder->types());

            return new MetricResult($aggregation->name, $this->extreme($raw, $field->type));
        }

        if (!$numeric) {
            throw NotSupportedByEngine::field($aggregation->field, 'sum and average are only answered for numeric fields');
        }

        $float = $this->dialect->toFloat($value);
        if ($aggregation instanceof Avg || $aggregation instanceof Sum) {
            $function = $aggregation instanceof Avg ? 'AVG' : 'SUM';
            $raw = $this->connection->fetchOne('SELECT ' . $function . '(' . $float . ')' . $from, $binder->params(), $binder->types());

            return new MetricResult($aggregation->name, $raw === null || $raw === false ? null : (float) $raw);
        }

        if ($aggregation instanceof Stats) {
            $row = $this->connection->fetchAssociative('SELECT MIN(' . $value . ') AS mn, MAX(' . $value . ') AS mx, AVG(' . $float . ') AS av, SUM(' . $float . ') AS sm' . $from, $binder->params(), $binder->types());
            $row = \is_array($row) ? $row : ['mn' => null, 'mx' => null, 'av' => null, 'sm' => null];

            return new StatsResult(
                $aggregation->name,
                $this->extreme($row['mn'], $field->type),
                $this->extreme($row['mx'], $field->type),
                $row['av'] === null ? null : (float) $row['av'],
                $row['sm'] === null ? null : (float) $row['sm'],
            );
        }

        throw NotSupportedByEngine::feature($this->dialect->name(), $aggregation::class);
    }

    private function extreme(mixed $raw, FieldType $type): string|int|float|null
    {
        if ($raw === null || $raw === false) {
            return null;
        }

        return match ($type) {
            FieldType::INT, FieldType::BOOL => (int) round((float) $raw),
            FieldType::FLOAT => (float) $raw,
            default => (string) $raw,
        };
    }

    private function terms(StoreCriteria $criteria, Terms $terms, ResolvedField $field, StoreContext $context, SqlScope $scope): TermsResult
    {
        if ($field->type === FieldType::ANY) {
            throw NotSupportedByEngine::field($terms->field, 'buckets of custom fields are not answered');
        }

        $binder = new Binder();
        $where = $this->where($criteria, $context, $binder, $scope, false, $terms->filters);
        $documents = $this->dialect->quote($scope->documents);
        $buckets = [];

        if ($field->isList()) {
            $unnest = $this->dialect->unnest('d.doc', $field->paths[0], $binder);
            $rows = $this->connection->fetchAllAssociative(
                'SELECT jt_value AS k, COUNT(DISTINCT id) AS c FROM (SELECT d.id AS id, ' . $unnest['value'] . ' AS jt_value FROM ' . $documents . ' d ' . $unnest['from'] . ' WHERE ' . $where . ') t WHERE jt_value IS NOT NULL GROUP BY jt_value',
                $binder->params(),
                $binder->types()
            );
            foreach ($rows as $row) {
                $buckets[] = ['key' => (string) $row['k'], 'count' => (int) $row['c']];
            }

            // documents without a list (or an empty list) form the null bucket
            $nullBinder = new Binder();
            $nullWhere = $this->where($criteria, $context, $nullBinder, $scope, false, $terms->filters);
            $notEmpty = $this->dialect->arrayNotEmpty('d.doc', $field->paths[0], $nullBinder);
            $nulls = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $documents . ' d WHERE (' . $nullWhere . ') AND NOT COALESCE(' . $notEmpty . ', FALSE)',
                $nullBinder->params(),
                $nullBinder->types()
            );
            if ($nulls > 0) {
                $buckets[] = ['key' => null, 'count' => $nulls];
            }
        } else {
            $native = $this->compiler->native($field, $binder, $scope);
            $key = \in_array($field->type, [FieldType::INT, FieldType::FLOAT, FieldType::BOOL], true) ? $native : $this->dialect->binary($native);
            $rows = $this->connection->fetchAllAssociative(
                'SELECT k, COUNT(*) AS c FROM (SELECT ' . $key . ' AS k FROM ' . $documents . ' d WHERE ' . $where . ') t GROUP BY k',
                $binder->params(),
                $binder->types()
            );
            foreach ($rows as $row) {
                $buckets[] = ['key' => $row['k'] === null ? null : $this->bucketKey($row['k'], $field->type), 'count' => (int) $row['c']];
            }
        }

        return new TermsResult($terms->name, $this->orderBuckets($buckets, $terms->limit, $field));
    }

    private function bucketKey(mixed $raw, FieldType $type): string|int|float|bool
    {
        return match ($type) {
            FieldType::INT => (int) round((float) $raw),
            FieldType::FLOAT => (float) $raw,
            FieldType::BOOL => (bool) (int) round((float) $raw),
            default => (string) $raw,
        };
    }

    /**
     * Same ordering and limit rules as the reference: with a limit the buckets with the highest count are kept
     * (equal counts: the larger key first), the result is sorted by key, null first.
     *
     * @param list<array{key: string|int|float|bool|null, count: int}> $buckets
     *
     * @return list<array{key: string|int|float|bool|null, count: int}>
     */
    private function orderBuckets(array $buckets, ?int $limit, ResolvedField $field): array
    {
        if ($limit !== null) {
            usort($buckets, fn (array $a, array $b): int => [$b['count'], 0] <=> [$a['count'], $this->compareKeys($a['key'], $b['key'], $field->type)]);
            $buckets = \array_slice($buckets, 0, $limit);
        }
        usort($buckets, fn (array $a, array $b): int => $this->compareKeys($a['key'], $b['key'], $field->type));

        return $buckets;
    }

    private function compareKeys(string|int|float|bool|null $left, string|int|float|bool|null $right, FieldType $type): int
    {
        if ($left === null || $right === null) {
            return ($left === null ? 0 : 1) <=> ($right === null ? 0 : 1);
        }
        if ((\is_int($left) || \is_float($left) || \is_bool($left)) && (\is_int($right) || \is_float($right) || is_numeric($right))) {
            return (float) $left <=> (float) $right;
        }
        if ($type === FieldType::INT || $type === FieldType::FLOAT || $type === FieldType::BOOL) {
            return (float) $left <=> (float) $right;
        }
        if ($type === FieldType::ID || $type === FieldType::DATETIME || $type === FieldType::LIST) {
            return strcmp(strtolower((string) $left), strtolower((string) $right));
        }

        $this->collator ??= $this->createCollator();
        $result = $this->collator->compare((string) $left, (string) $right);

        return $result === false ? strcmp((string) $left, (string) $right) : $result;
    }

    private function createCollator(): \Collator
    {
        $collator = new \Collator('root');
        $collator->setStrength(\Collator::PRIMARY);

        return $collator;
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    private function storeChunk(array $documents, string $generation): void
    {
        $table = $this->table($generation);
        $keywordTable = $this->keywordTable($generation);
        $statement = $this->dialect->upsertDocument($table);

        $ids = [];
        $keywordRows = [];
        foreach ($documents as $document) {
            $id = strtolower((string) $document['id']);
            $ids[] = $id;
            $this->connection->executeStatement($statement, [
                'id' => $id,
                'doc' => json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            ]);

            foreach ($document['searchKeywords'] ?? [] as $languageId => $keywords) {
                foreach ($keywords as $keyword => $ranking) {
                    $keywordRows[] = [$id, (string) $languageId, QueryEvaluator::fold((string) $keyword), (string) (float) $ranking];
                }
            }
        }

        $marks = implode(', ', array_fill(0, \count($ids), '?'));
        $this->connection->executeStatement('DELETE FROM ' . $this->dialect->quote($keywordTable) . ' WHERE product_id IN (' . $marks . ')', $ids);

        foreach (array_chunk($keywordRows, 400) as $rows) {
            $params = [];
            foreach ($rows as $row) {
                array_push($params, ...$row);
            }
            $this->connection->executeStatement(
                'INSERT INTO ' . $this->dialect->quote($keywordTable) . ' (product_id, language_id, keyword_fold, ranking) VALUES ' . implode(', ', array_fill(0, \count($rows), '(?, ?, ?, ?)')),
                $params,
                array_fill(0, \count($params), ParameterType::STRING)
            );
        }
    }

    private function scope(): SqlScope
    {
        $this->prepare();
        $live = $this->liveGeneration();
        if ($live === null) {
            throw ReadStoreException::noLiveGeneration();
        }

        return new SqlScope($this->table($live), $this->keywordTable($live));
    }

    /**
     * @return list<string> the live generation and every pending one
     */
    private function targets(?string $generation): array
    {
        if ($generation !== null) {
            if (!\in_array($generation, $this->generations(), true)) {
                throw ReadStoreException::unknownGeneration($generation);
            }

            return [$generation];
        }

        if ($this->liveGeneration() === null) {
            throw ReadStoreException::noLiveGeneration();
        }

        return $this->generations();
    }

    /**
     * @return list<string>
     */
    private function generations(): array
    {
        try {
            $names = $this->connection->fetchFirstColumn('SELECT name FROM ' . $this->dialect->quote($this->metaTable()) . ' WHERE name LIKE \'gen:%\'');
        } catch (TableNotFoundException) {
            return [];
        }

        return array_map(static fn (string $name): string => substr($name, 4), $names);
    }

    private function meta(string $name): ?string
    {
        try {
            $value = $this->connection->fetchOne('SELECT value FROM ' . $this->dialect->quote($this->metaTable()) . ' WHERE name = :n', ['n' => $name]);
        } catch (TableNotFoundException) {
            return null;
        }

        return $value === false || $value === '' ? null : (string) $value;
    }

    private function setMeta(string $name, string $value): void
    {
        $this->connection->executeStatement($this->dialect->upsertMeta($this->metaTable()), ['name' => $name, 'value' => $value]);
    }

    private function createMeta(): void
    {
        foreach ($this->dialect->createMetaTable($this->metaTable()) as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    private function dropTables(string $generation): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->dialect->quote($this->table($generation)));
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->dialect->quote($this->keywordTable($generation)));
    }

    private function prepare(): void
    {
        $this->dialect->bootstrap($this->connection);
    }

    private function assertName(string $generation): void
    {
        if (preg_match('/^[A-Za-z0-9_]+\z/', $generation) !== 1 || \strlen($this->prefix . '_' . $generation . '_kw') > 63) {
            throw ReadStoreException::invalidCriteria('the generation name "' . $generation . '" is not usable as a table name');
        }
    }

    private function table(string $generation): string
    {
        return $this->prefix . '_' . $generation;
    }

    private function keywordTable(string $generation): string
    {
        return $this->prefix . '_' . $generation . '_kw';
    }

    private function metaTable(): string
    {
        return $this->prefix . '_meta';
    }
}
