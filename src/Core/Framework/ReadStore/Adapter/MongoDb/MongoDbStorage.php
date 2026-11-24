<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\MongoDb;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Shopware\Core\Framework\Log\Package;
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
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * MongoDB adapter (`mongodb/mongodb`, MongoDB 8). Time-boxed port-validation target: it shows that a document database can answer the port,
 * its licence (SSPL) makes it unlikely as a recommended Shopware default.
 *
 * Layout: database `rs_...` (the name must start with `rs_`), one collection `rs_<generation>` per generation with the default collation
 * `en`, strength 1 (case and accent insensitive like the ICU primary sorting of the reference, used for sorting, grouping and string ranges),
 * and `rs_meta` with the live pointer. Documents look like {@see MongoDbDocument}. Indexes are created when a generation goes live.
 *
 * What it answers exactly: everything of the port except what the `NotSupportedByEngine` messages of {@see MongoDbQueryCompiler} and the
 * aggregation checks below name (min and max of strings, aggregations on custom fields, string comparison with non-string values).
 *
 * @internal
 */
#[Package('framework')]
class MongoDbStorage implements Storage, FilterAware, AggregationAware, SearchAware
{
    private const COLLATION = ['locale' => 'en', 'strength' => 1];

    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private ?Client $client = null;

    public function __construct(
        private readonly string $uri = 'mongodb://instance-mongodb-1:27017',
        private readonly string $database = 'rs_readstore',
        private readonly MongoDbQueryCompiler $compiler = new MongoDbQueryCompiler(),
    ) {
        if (!str_starts_with($database, 'rs_')) {
            throw ReadStoreException::invalidCriteria('the MongoDB database name must start with rs_');
        }
    }

    public function setup(string $generation): void
    {
        $name = $this->collectionName($generation);
        $database = $this->db();

        $exists = false;
        foreach ($database->listCollectionNames(['filter' => ['name' => $name]]) as $found) {
            $exists = $found === $name;
        }
        if (!$exists) {
            $database->createCollection($name, ['collation' => self::COLLATION]);
        }

        $this->meta()->updateOne(['_id' => 'state'], ['$addToSet' => ['generations' => $generation]], ['upsert' => true]);
    }

    public function swap(string $generation): void
    {
        $state = $this->state();
        if (!\in_array($generation, $state['generations'], true)) {
            throw ReadStoreException::unknownGeneration($generation);
        }

        $this->ensureIndexes($this->collection($generation));

        $this->meta()->updateOne(['_id' => 'state'], ['$set' => ['live' => $generation]], ['upsert' => true]);

        $old = $state['live'];
        if ($old !== null && $old !== $generation) {
            $this->db()->dropCollection($this->collectionName($old));
            $this->meta()->updateOne(['_id' => 'state'], ['$pull' => ['generations' => $old]]);
        }
    }

    public function liveGeneration(): ?string
    {
        return $this->state()['live'];
    }

    public function store(array $documents, ?string $generation = null): void
    {
        if ($documents === []) {
            return;
        }

        $targets = $generation !== null ? [$generation] : $this->writeTargets();
        $operations = [];
        foreach ($documents as $document) {
            $bson = MongoDbDocument::toBson($document);
            $operations[] = ['replaceOne' => [['_id' => $bson['_id']], $bson, ['upsert' => true]]];
        }

        foreach ($targets as $target) {
            foreach (array_chunk($operations, 500) as $chunk) {
                $this->collection($target)->bulkWrite($chunk, ['ordered' => false]);
            }
        }
    }

    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->writeTargets() as $target) {
            $this->collection($target)->deleteMany(['_id' => ['$in' => array_values($ids)]]);
        }
    }

    public function get(string $id): ?array
    {
        return $this->mget([$id])[$id] ?? null;
    }

    public function mget(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->collection($this->requireLive())->find(
            ['_id' => ['$in' => array_values($ids)]],
            ['projection' => ['d' => 1], 'typeMap' => self::TYPE_MAP],
        );

        $byId = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            $byId[(string) $row['_id']] = $row['d'];
        }

        $documents = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $documents[$id] = $byId[$id];
            }
        }

        return $documents;
    }

    public function clear(): void
    {
        $this->collection($this->requireLive())->deleteMany([]);
    }

    public function destroy(): void
    {
        $database = $this->db();
        foreach ($database->listCollectionNames() as $name) {
            if (str_starts_with($name, 'rs_')) {
                $database->dropCollection($name);
            }
        }

        // the database holds only our collections in the tests, drop it then so no empty database stays behind
        if (iterator_to_array($database->listCollectionNames()) === []) {
            $database->drop();
        }
    }

    public function filter(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search !== null) {
            throw NotSupportedByEngine::feature('mongodb', 'a search term in filter(), use search()');
        }

        return $this->query($criteria, $context, null);
    }

    public function search(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search === null) {
            throw ReadStoreException::invalidCriteria('search() needs a search term');
        }

        return $this->query($criteria, $context, $criteria->search);
    }

    public function aggregate(StoreCriteria $criteria, StoreContext $context): array
    {
        return $this->aggregations($criteria, $context, $criteria->search);
    }

    private function query(StoreCriteria $criteria, StoreContext $context, ?SearchTerm $search): StoreResult
    {
        // resolve every field first, so an unsupported one fails before any round trip
        $aggregations = $this->aggregationPlans($criteria, $context);

        $filters = [...$criteria->filters, ...$criteria->postFilters];
        $pipeline = [['$match' => MongoDbQueryCompiler::all([
            $this->compiler->match($this->withIds($filters, $criteria), $context),
            $search !== null ? $this->compiler->search($search, $context) : [],
        ]) ?: new \stdClass()]];

        $collapse = $criteria->groupField !== null;
        $addFields = [];
        $sort = [];
        $keys = [];

        if ($search !== null) {
            $addFields['_score'] = $this->compiler->score($search, $context);
        }

        foreach ($criteria->sortings as $index => $sorting) {
            if ($sorting->field === Sorting::SCORE) {
                if ($search === null) {
                    continue; // the score of a filter() is 0 for every hit, it does not order
                }
                $keys[] = ['ref' => '_score', 'direction' => $sorting->direction, 'min' => $sorting->direction === Sorting::ASC];

                continue;
            }

            $value = $this->compiler->value($sorting->field, $context);
            if ($value['field']->isList()) {
                throw NotSupportedByEngine::field($sorting->field, 'cannot sort by a list field');
            }

            $ref = $value['path'];
            if ($collapse || !$value['single']) {
                $ref = '_s' . $index;
                $addFields[$ref] = $value['expression'];
            }

            $keys[] = [
                'ref' => $ref,
                'direction' => $sorting->direction,
                // groups are ordered by the minimum of ascending and the maximum of descending sortings, cheapestPrice always by the minimum
                'min' => $sorting->direction === Sorting::ASC || str_starts_with($sorting->field, 'cheapestPrice'),
            ];
        }

        $group = null;
        if ($criteria->groupField !== null) {
            $groupValue = $this->compiler->value($criteria->groupField, $context);
            if (\in_array($groupValue['field']->type, [FieldType::LIST, FieldType::ANY], true)) {
                throw NotSupportedByEngine::field($criteria->groupField, 'cannot collapse by a list or custom field');
            }
            $addFields['_g'] = $groupValue['expression'];
            $group = '$_g';
        }

        if ($addFields !== []) {
            $pipeline[] = ['$addFields' => $addFields];
        }

        if ($group !== null && $search !== null) {
            // DAL EntitySearcher::buildScoreRankedQuery(): highest scoring document is the representative, groups are ordered by its sort keys
            $pipeline[] = ['$sort' => ['_score' => -1, '_id' => 1]];
            $project = ['d' => 1, '_score' => 1, '_g' => 1];
            $groupSort = [];
            foreach ($keys as $key) {
                $project[$key['ref']] = 1;
                $groupSort['rep.' . $key['ref']] ??= $key['direction'] === Sorting::ASC ? 1 : -1;
            }
            $groupSort['rep._id'] = 1;

            $pipeline[] = ['$project' => $project];
            $pipeline[] = ['$group' => ['_id' => $group, 'rep' => ['$first' => '$$ROOT']]];
            $pipeline[] = ['$sort' => $groupSort];
            $pipeline[] = ['$replaceRoot' => ['newRoot' => '$rep']];
        } else {
            foreach ($keys as $key) {
                $sort[$key['ref']] ??= $key['direction'] === Sorting::ASC ? 1 : -1;
            }
            if ($sort === [] && $criteria->ids !== [] && $search === null) {
                $pipeline[] = ['$addFields' => ['_pos' => ['$indexOfArray' => [['$literal' => array_values(array_map(strtolower(...), $criteria->ids))], '$_id']]]];
                $sort['_pos'] = 1;
            }
            $sort['_id'] = 1;
            $pipeline[] = ['$sort' => $sort];

            if ($group !== null) {
                $project = ['d' => 1, '_score' => 1, '_g' => 1];
                $accumulators = ['rep' => ['$first' => '$$ROOT']];
                $groupSort = [];
                foreach ($keys as $position => $key) {
                    $project[$key['ref']] = 1;
                    $accumulators['a' . $position] = [$key['min'] ? '$min' : '$max' => '$' . $key['ref']];
                    $groupSort['a' . $position] ??= $key['direction'] === Sorting::ASC ? 1 : -1;
                }
                $groupSort['rep._id'] = 1;

                $pipeline[] = ['$project' => $project];
                $pipeline[] = ['$group' => ['_id' => $group, ...$accumulators]];
                $pipeline[] = ['$sort' => $groupSort];
                $pipeline[] = ['$replaceRoot' => ['newRoot' => '$rep']];
            }
        }

        $exact = $criteria->total === Total::EXACT;
        if ($criteria->limit === 0) {
            $total = null;
            if ($exact) {
                $pipeline[] = ['$count' => 'n'];
                $rows = $this->collection($this->requireLive())->aggregate($pipeline, ['allowDiskUse' => true, 'typeMap' => self::TYPE_MAP])->toArray();
                $total = (int) ($rows[0]['n'] ?? 0);
            }

            return new StoreResult(
                [],
                $total,
                $aggregations === [] ? [] : $this->runAggregations($criteria, $context, $search, $aggregations),
                [],
            );
        }

        $page = [];
        if ($criteria->offset > 0) {
            $page[] = ['$skip' => $criteria->offset];
        }
        if ($criteria->limit !== null) {
            $page[] = ['$limit' => $criteria->limit];
        }
        $page[] = ['$project' => ['d' => 1, '_score' => 1]];

        if ($exact) {
            $pipeline[] = ['$facet' => ['rows' => $page, 'total' => [['$count' => 'n']]]];
        } else {
            array_push($pipeline, ...$page);
        }

        $rows = $this->collection($this->requireLive())->aggregate($pipeline, ['allowDiskUse' => true, 'typeMap' => self::TYPE_MAP])->toArray();

        $total = null;
        if ($exact) {
            $facet = $rows[0] ?? ['rows' => [], 'total' => []];
            $total = (int) ($facet['total'][0]['n'] ?? 0);
            $rows = $facet['rows'];
        }

        $documents = [];
        $scores = [];
        foreach ($rows as $row) {
            $documents[] = $row['d'];
            if ($search !== null) {
                $scores[(string) $row['d']['id']] = (float) ($row['_score'] ?? 0.0);
            }
        }

        return new StoreResult(
            $documents,
            $total,
            $aggregations === [] ? [] : $this->runAggregations($criteria, $context, $search, $aggregations),
            $scores,
        );
    }

    /**
     * @return array<string, AggregationResult>
     */
    private function aggregations(StoreCriteria $criteria, StoreContext $context, ?SearchTerm $search): array
    {
        $plans = $this->aggregationPlans($criteria, $context);

        return $plans === [] ? [] : $this->runAggregations($criteria, $context, $search, $plans);
    }

    /**
     * One `$facet` branch per aggregation, so all aggregations take one round trip. They match the filters only (no post filters, no collapse)
     * and run without a collation, so buckets stay exact (`Red` and `red` are two buckets, only `Count` folds).
     *
     * @param list<array{aggregation: Aggregation, type: FieldType, pipeline: list<array<string, mixed>>}> $plans
     *
     * @return array<string, AggregationResult>
     */
    private function runAggregations(StoreCriteria $criteria, StoreContext $context, ?SearchTerm $search, array $plans): array
    {
        $branches = [];
        foreach ($plans as $position => $plan) {
            $branches['a' . $position] = $plan['pipeline'];
        }

        $pipeline = [
            ['$match' => MongoDbQueryCompiler::all([
                $this->compiler->match($this->withIds($criteria->filters, $criteria), $context),
                $search !== null ? $this->compiler->search($search, $context) : [],
            ]) ?: new \stdClass()],
            ['$facet' => $branches],
        ];

        $rows = $this->collection($this->requireLive())->aggregate($pipeline, [
            'allowDiskUse' => true,
            'typeMap' => self::TYPE_MAP,
            'collation' => ['locale' => 'simple'],
        ])->toArray();
        $facet = $rows[0] ?? [];

        $results = [];
        foreach ($plans as $position => $plan) {
            /** @var list<array<string, mixed>> $branch */
            $branch = $facet['a' . $position] ?? [];
            $results[$plan['aggregation']->name] = $this->result($plan['aggregation'], $plan['type'], $branch);
        }

        return $results;
    }

    /**
     * @return list<array{aggregation: Aggregation, type: FieldType, pipeline: list<array<string, mixed>>}>
     */
    private function aggregationPlans(StoreCriteria $criteria, StoreContext $context): array
    {
        $plans = [];
        foreach ($criteria->aggregations as $aggregation) {
            $folded = $aggregation instanceof Count;
            $value = $this->compiler->value($aggregation->field, $context, $folded ? 'f' : 'd');
            $field = $value['field'];

            $allowed = match (true) {
                $aggregation instanceof Terms => [FieldType::STRING, FieldType::TEXT, FieldType::ID, FieldType::LIST, FieldType::INT, FieldType::FLOAT, FieldType::BOOL, FieldType::DATETIME],
                $aggregation instanceof Min, $aggregation instanceof Max => [FieldType::ID, FieldType::LIST, FieldType::INT, FieldType::FLOAT, FieldType::BOOL, FieldType::DATETIME],
                $aggregation instanceof Count => [FieldType::STRING, FieldType::TEXT, FieldType::ID, FieldType::LIST, FieldType::INT, FieldType::FLOAT, FieldType::DATETIME],
                $aggregation instanceof Avg, $aggregation instanceof Sum, $aggregation instanceof Stats => [FieldType::INT, FieldType::FLOAT],
                default => throw NotSupportedByEngine::feature('mongodb', $aggregation::class),
            };
            if (!\in_array($field->type, $allowed, true)) {
                throw NotSupportedByEngine::field($aggregation->field, \sprintf('%s is not answered exactly for %s fields', (new \ReflectionClass($aggregation))->getShortName(), $field->type->name));
            }

            $expression = $value['expression'];
            $pipeline = [];
            if ($aggregation->filters !== []) {
                $pipeline[] = ['$match' => $this->compiler->match($aggregation->filters, $context)];
            }

            if ($field->isList()) {
                // a list contributes each distinct element once, an empty or missing list one null
                $pipeline[] = ['$project' => ['_id' => 0, 'v' => ['$cond' => [
                    ['$and' => [['$isArray' => $expression], ['$gt' => [['$size' => $expression], 0]]]],
                    ['$setUnion' => [$expression, []]],
                    [null],
                ]]]];
                $pipeline[] = ['$unwind' => '$v'];
            } else {
                $pipeline[] = ['$project' => ['_id' => 0, 'v' => $expression]];
            }

            $pipeline = match (true) {
                $aggregation instanceof Terms => $this->termsPipeline($pipeline, $aggregation, $field->type),
                $aggregation instanceof Count => [...$pipeline, ['$match' => ['v' => ['$ne' => null]]], ['$group' => ['_id' => '$v']], ['$count' => 'n']],
                default => [...$pipeline, ['$match' => ['v' => ['$ne' => null]]], ['$group' => [
                    '_id' => null,
                    'min' => ['$min' => '$v'],
                    'max' => ['$max' => '$v'],
                    'sum' => ['$sum' => '$v'],
                    'n' => ['$sum' => 1],
                ]]],
            };

            $plans[] = ['aggregation' => $aggregation, 'type' => $field->type, 'pipeline' => $pipeline];
        }

        return $plans;
    }

    /**
     * @param list<array<string, mixed>> $pipeline
     *
     * @return list<array<string, mixed>>
     */
    private function termsPipeline(array $pipeline, Terms $terms, FieldType $type): array
    {
        $pipeline[] = ['$group' => ['_id' => '$v', 'count' => ['$sum' => 1]]];

        // strings order by the ICU collation of the reference, which MongoDB cannot do in this collation-free command: the cut happens in PHP
        if ($terms->limit !== null && !$this->isString($type)) {
            $pipeline[] = ['$sort' => ['count' => -1, '_id' => 1]];
            $pipeline[] = ['$limit' => $terms->limit];
        }

        return $pipeline;
    }

    private function isString(FieldType $type): bool
    {
        return $type === FieldType::STRING || $type === FieldType::TEXT;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function result(Aggregation $aggregation, FieldType $type, array $rows): AggregationResult
    {
        $row = $rows[0] ?? null;

        if ($aggregation instanceof Terms) {
            $buckets = array_map(static fn (array $bucket): array => ['key' => $bucket['_id'], 'count' => (int) $bucket['count']], $rows);

            if ($aggregation->limit !== null && $this->isString($type)) {
                usort($buckets, fn (array $a, array $b): int => [$b['count'], $this->compareKeys($a['key'], $b['key'], $type)] <=> [$a['count'], 0]);
                $buckets = \array_slice($buckets, 0, $aggregation->limit);
            }
            usort($buckets, fn (array $a, array $b): int => $this->compareKeys($a['key'], $b['key'], $type));

            return new TermsResult($aggregation->name, $buckets);
        }

        if ($aggregation instanceof Count) {
            return new MetricResult($aggregation->name, (int) ($row['n'] ?? 0));
        }

        $count = (int) ($row['n'] ?? 0);
        $min = $row['min'] ?? null;
        $max = $row['max'] ?? null;
        $sum = $count === 0 ? null : (float) ($row['sum'] ?? 0);
        $min = \is_bool($min) ? (int) $min : $min;
        $max = \is_bool($max) ? (int) $max : $max;

        return match (true) {
            $aggregation instanceof Min => new MetricResult($aggregation->name, $min),
            $aggregation instanceof Max => new MetricResult($aggregation->name, $max),
            $aggregation instanceof Sum => new MetricResult($aggregation->name, $sum),
            $aggregation instanceof Avg => new MetricResult($aggregation->name, $sum === null ? null : $sum / $count),
            default => new StatsResult($aggregation->name, $min, $max, $sum === null ? null : $sum / $count, $sum),
        };
    }

    private function compareKeys(mixed $left, mixed $right, FieldType $type): int
    {
        if ($left === null || $right === null) {
            return ($left === null ? 0 : 1) <=> ($right === null ? 0 : 1);
        }

        if (!\is_string($left) || !\is_string($right)) {
            return (float) $left <=> (float) $right;
        }

        if ($this->isString($type)) {
            $collator = new \Collator('root');
            $collator->setStrength(\Collator::PRIMARY);
            $result = $collator->compare($left, $right);

            return $result === false ? strcmp($left, $right) : $result;
        }

        return strcmp(strtolower($left), strtolower($right));
    }

    /**
     * @param list<Filter> $filters
     *
     * @return list<Filter>
     */
    private function withIds(array $filters, StoreCriteria $criteria): array
    {
        if ($criteria->ids !== []) {
            $filters[] = new EqualsAny('id', $criteria->ids);
        }

        return $filters;
    }

    private function ensureIndexes(Collection $collection): void
    {
        // wildcard indexes keep the schema free (languages, currencies, rules and custom fields are keys): one for the folded values filters
        // compare, one for the keywords. Sorting by a language or price specific key still sorts in memory.
        $collection->createIndexes([
            ['key' => ['f.$**' => 1], 'name' => 'rs_f'],
            ['key' => ['s.$**' => 1], 'name' => 'rs_s'],
        ]);
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->uri, ['serverSelectionTimeoutMS' => 3000], ['typeMap' => self::TYPE_MAP]);
    }

    private function db(): Database
    {
        return $this->client()->selectDatabase($this->database);
    }

    private function meta(): Collection
    {
        return $this->db()->selectCollection('rs_meta');
    }

    private function collection(string $generation): Collection
    {
        return $this->db()->selectCollection($this->collectionName($generation));
    }

    private function collectionName(string $generation): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+\z/', $generation) !== 1) {
            throw ReadStoreException::unknownGeneration($generation);
        }

        return 'rs_' . $generation;
    }

    /**
     * @return array{live: string|null, generations: list<string>}
     */
    private function state(): array
    {
        /** @var array{live?: string|null, generations?: list<string>}|null $state */
        $state = $this->meta()->findOne(['_id' => 'state'], ['typeMap' => self::TYPE_MAP]);

        return ['live' => $state['live'] ?? null, 'generations' => $state['generations'] ?? []];
    }

    private function requireLive(): string
    {
        return $this->liveGeneration() ?? throw ReadStoreException::noLiveGeneration();
    }

    /**
     * @return list<string> the live generation and every pending one
     */
    private function writeTargets(): array
    {
        $state = $this->state();
        $live = $state['live'] ?? throw ReadStoreException::noLiveGeneration();

        return array_values(array_unique([$live, ...$state['generations']]));
    }
}
