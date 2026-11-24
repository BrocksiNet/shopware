<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\OpenSearch;

use OpenSearch\Client;
use OpenSearch\Exception\NotFoundHttpException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\AggregationAware;
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * Read store on OpenSearch. A generation is an index `<prefix><name>_<generation>`, the live generation is the one that carries the
 * alias `<prefix><name>`, so every process sees a swap. The index holds the document in `_source` and the query side (folded values,
 * sort keys, ...) below `rs`, see {@see IndexSchema}. Only indices of the own prefix, which must start with `rs_`, are touched.
 *
 * @internal
 */
#[Package('framework')]
class OpenSearchStorage implements Storage, FilterAware, AggregationAware, SearchAware
{
    public const MAX_WINDOW = 100000;

    private const ENGINE = 'opensearch';

    private const BATCH = 500;

    private readonly QueryBuilder $queries;

    private readonly AggregationBuilder $aggregations;

    private readonly FieldResolver $resolver;

    private readonly IndexSchema $schema;

    /**
     * @param bool $refresh wait for the next refresh after every write, makes writes visible at once (tests), costs throughput
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $prefix = 'rs_os_',
        private readonly string $name = 'product',
        ?FieldResolver $resolver = null,
        ?IndexSchema $schema = null,
        private readonly bool $refresh = false,
        private readonly string $refreshInterval = '1s',
    ) {
        if (preg_match('/^rs_[a-z0-9_]*\z/', $prefix) !== 1) {
            throw ReadStoreException::invalidCriteria('the OpenSearch index prefix must start with rs_ and contain lower case letters, digits and underscores only');
        }
        if (preg_match('/^[a-z0-9]+\z/', $name) !== 1) {
            throw ReadStoreException::invalidCriteria('the OpenSearch store name must contain lower case letters and digits only');
        }

        $this->resolver = $resolver ?? new FieldResolver();
        $this->schema = $schema ?? new IndexSchema();
        $this->queries = new QueryBuilder($this->resolver, $this->schema);
        $this->aggregations = new AggregationBuilder($this->resolver, $this->queries);
    }

    public function setup(string $generation): void
    {
        $index = $this->index($generation);
        if ($this->client->indices()->exists(['index' => $index])) {
            return;
        }

        $this->client->indices()->create([
            'index' => $index,
            'body' => [
                'settings' => [
                    'index' => [
                        'number_of_shards' => 1,
                        'number_of_replicas' => 0,
                        'refresh_interval' => $this->refreshInterval,
                        'max_result_window' => self::MAX_WINDOW,
                        'mapping' => ['total_fields' => ['limit' => 20000]],
                    ],
                ],
                'mappings' => $this->schema->mapping(),
            ],
        ]);
    }

    public function swap(string $generation): void
    {
        $indices = $this->indices();
        $new = $this->index($generation);
        if (!\array_key_exists($new, $indices)) {
            throw ReadStoreException::unknownGeneration($generation);
        }

        $old = $this->liveIndex($indices);
        if ($old === $new) {
            return;
        }

        $actions = [];
        if ($old !== null) {
            $actions[] = ['remove' => ['index' => $old, 'alias' => $this->alias()]];
        }
        $actions[] = ['add' => ['index' => $new, 'alias' => $this->alias()]];

        $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);

        if ($old !== null) {
            $this->client->indices()->delete(['index' => $old]);
        }
    }

    public function liveGeneration(): ?string
    {
        $live = $this->liveIndex($this->indices());

        return $live === null ? null : substr($live, \strlen($this->alias() . '_'));
    }

    public function store(array $documents, ?string $generation = null): void
    {
        if ($documents === []) {
            return;
        }

        $targets = $this->targets($generation);

        foreach (array_chunk($documents, self::BATCH) as $chunk) {
            $lines = [];
            foreach ($chunk as $document) {
                $indexed = json_encode($this->schema->index($document), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
                foreach ($targets as $target) {
                    $lines[] = json_encode(['index' => ['_index' => $target, '_id' => (string) $document['id']]], \JSON_THROW_ON_ERROR);
                    $lines[] = $indexed;
                }
            }

            $this->bulk($lines);
        }
    }

    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $targets = $this->targets(null);

        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $lines = [];
            foreach ($chunk as $id) {
                foreach ($targets as $target) {
                    $lines[] = json_encode(['delete' => ['_index' => $target, '_id' => $id]], \JSON_THROW_ON_ERROR);
                }
            }

            $this->bulk($lines);
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

        $documents = [];
        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            try {
                $response = $this->client->mget([
                    'index' => $this->alias(),
                    '_source_excludes' => 'rs',
                    'body' => ['ids' => $chunk],
                ]);
            } catch (NotFoundHttpException) {
                throw ReadStoreException::noLiveGeneration();
            }

            foreach ($response['docs'] ?? [] as $position => $doc) {
                if (($doc['found'] ?? false) === true) {
                    $documents[$chunk[$position]] = $doc['_source'];
                }
            }
        }

        // keep the order of the requested ids
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($documents[$id])) {
                $ordered[$id] = $documents[$id];
            }
        }

        return $ordered;
    }

    public function clear(): void
    {
        try {
            $this->client->deleteByQuery([
                'index' => $this->alias(),
                'refresh' => true,
                'conflicts' => 'proceed',
                'body' => ['query' => ['match_all' => new \stdClass()]],
            ]);
        } catch (NotFoundHttpException) {
            throw ReadStoreException::noLiveGeneration();
        }
    }

    public function destroy(): void
    {
        foreach (array_keys($this->indices()) as $index) {
            $this->client->indices()->delete(['index' => $index]);
        }
    }

    public function filter(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search !== null) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'a search term in filter(), use search()');
        }

        return $this->run($criteria, $context);
    }

    public function search(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search === null) {
            throw ReadStoreException::invalidCriteria('search() needs a search term');
        }

        return $this->run($criteria, $context);
    }

    public function aggregate(StoreCriteria $criteria, StoreContext $context): array
    {
        if ($criteria->aggregations === []) {
            return [];
        }

        ['aggs' => $aggs, 'plan' => $plan] = $this->aggregations->build($criteria->aggregations, $context);

        $response = $this->request([
            'size' => 0,
            'track_total_hits' => false,
            'query' => $this->baseQuery($criteria, $context),
            'aggs' => $aggs,
        ]);

        return $this->aggregations->results($plan, $response['aggregations'] ?? []);
    }

    private function run(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        $search = $criteria->search;
        $collapse = $criteria->groupField !== null;
        $limit = $criteria->limit;
        $size = $limit ?? (self::MAX_WINDOW - $criteria->offset);

        if ($size < 0 || $criteria->offset + $size > self::MAX_WINDOW) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'paging beyond ' . self::MAX_WINDOW . ' hits');
        }

        $query = $this->baseQuery($criteria, $context);
        if ($search !== null) {
            $query = ['script_score' => ['query' => $query, 'script' => $this->scoreScript($search)]];
        }

        $needsTotal = $criteria->total === Total::EXACT || $limit === null;
        $body = [
            'from' => $criteria->offset,
            'size' => $size,
            'query' => $query,
            'sort' => $this->queries->sort($criteria->sortings, $criteria->ids, $search !== null, $context),
            '_source' => ['excludes' => ['rs']],
            'track_total_hits' => $needsTotal && !$collapse,
            'track_scores' => $search !== null,
        ];

        if ($criteria->postFilters !== []) {
            $body['post_filter'] = ['bool' => ['filter' => $this->queries->all($criteria->postFilters, $context)]];
        }

        $checks = [];
        if ($collapse) {
            $checks = $this->collapse($body, $criteria, $context, $needsTotal);
        }

        $response = $this->request($body);

        $total = null;
        if ($collapse) {
            $this->assertCollapse($checks, $response['aggregations'] ?? []);
            $groups = (int) ($response['aggregations']['groups']['n']['value'] ?? 0);
            if ($groups >= AggregationBuilder::CARDINALITY_THRESHOLD) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'an exact group count above ' . AggregationBuilder::CARDINALITY_THRESHOLD);
            }
            $count = $groups;
        } else {
            $count = (int) ($response['hits']['total']['value'] ?? 0);
        }

        if ($limit === null && $count > $criteria->offset + $size) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'reading more than ' . self::MAX_WINDOW . ' hits without a limit');
        }
        if ($criteria->total === Total::EXACT) {
            $total = $count;
        }

        $documents = [];
        $scores = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $documents[] = $hit['_source'];
            if ($search !== null) {
                $scores[(string) $hit['_source']['id']] = (float) $hit['_score'];
            }
        }

        $aggregations = $criteria->aggregations === [] ? [] : $this->aggregate($criteria, $context);

        return new StoreResult($documents, $total, $aggregations, $scores);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseQuery(StoreCriteria $criteria, StoreContext $context): array
    {
        $filters = $this->queries->all($criteria->filters, $context);

        if ($criteria->ids !== []) {
            $filters[] = ['terms' => ['rs.f.id' => array_values(array_unique(array_map('strtolower', $criteria->ids)))]];
        }

        if ($criteria->search !== null) {
            $filters[] = $this->searchFilter($criteria->search, $context);
        }

        return $filters === [] ? ['match_all' => new \stdClass()] : ['bool' => ['filter' => $filters]];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchFilter(SearchTerm $search, StoreContext $context): array
    {
        $field = 'rs.f.kw.' . $context->languageId;
        $folded = static fn (array $keywords): array => array_values(array_unique(array_map(QueryEvaluator::fold(...), $keywords)));

        $filters = [['exists' => ['field' => $field]]];
        if (!$search->andLogic) {
            $filters[] = ['terms' => [$field => $folded($search->anyKeywords())]];

            return ['bool' => ['filter' => $filters]];
        }

        foreach ($search->tokenKeywords as $group) {
            $filters[] = $group === [] ? ['match_none' => new \stdClass()] : ['terms' => [$field => $folded($group)]];
        }

        return ['bool' => ['filter' => $filters]];
    }

    /**
     * Score of the reference evaluator: summed over the keywords of all languages.
     *
     * @return array<string, mixed>
     */
    private function scoreScript(SearchTerm $search): array
    {
        $words = [];
        $scores = [];
        foreach ($search->scoredKeywords as $scored) {
            $words[] = QueryEvaluator::fold($scored['keyword']);
            $scores[] = (float) $scored['score'];
        }

        return [
            'lang' => 'painless',
            'source' => 'double s = 0.0; def keywords = params[\'_source\'].rs.k; if (keywords == null) { return 0.0; } '
                . 'for (def e : keywords) { String k = e.k; double r = e.r; '
                . 'for (int i = 0; i < params.words.length; i++) { if (params.words[i].equals(k)) { s += params.scores[i] * r; } } '
                . 'if (params.original.length() > 0 && k.contains(params.original)) { s += params.originalScore * r; } } return s;',
            'params' => [
                'words' => $words,
                'scores' => $scores,
                'original' => QueryEvaluator::fold($search->original),
                'originalScore' => $search->originalScore,
            ],
        ];
    }

    /**
     * Prepares the native collapse, returns the checks that must hold for the response to be exact.
     *
     * @param array<string, mixed> $body
     *
     * @return array{missing: bool}
     */
    private function collapse(array &$body, StoreCriteria $criteria, StoreContext $context, bool $needsTotal): array
    {
        \assert($criteria->groupField !== null);
        $group = $this->resolver->resolve($criteria->groupField, $context);
        if ($group->type !== FieldType::ID || !\in_array($criteria->groupField, $this->schema->collapseFields(), true)) {
            throw NotSupportedByEngine::field($criteria->groupField, 'only the configured collapse fields can group');
        }

        if (\count($criteria->sortings) !== 1) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'collapse without or with several sortings, the group order of the reference is an aggregate per sort field');
        }

        $sorting = $criteria->sortings[0];
        $missingPaths = [];
        if ($sorting->field !== Sorting::SCORE) {
            $sortField = $this->resolver->resolve($sorting->field, $context);
            if ($sorting->direction === Sorting::DESC && str_starts_with($sorting->field, 'cheapestPrice')) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'collapse sorted by cheapestPrice descending, the reference orders groups by the minimum');
            }
            if ($sorting->direction === Sorting::ASC) {
                $missingPaths = array_map(static fn (string $path): string => 'rs.f.' . $path, $sortField->dottedPaths());
            }
        }

        $body['collapse'] = ['field' => 'rs.g.' . $criteria->groupField];

        $postFilters = $this->queries->all($criteria->postFilters, $context);
        $inScope = $postFilters === [] ? ['match_all' => new \stdClass()] : ['bool' => ['filter' => $postFilters]];

        $aggs = [];
        if ($needsTotal) {
            $aggs['groups'] = [
                'filter' => $inScope,
                'aggs' => ['n' => ['cardinality' => ['field' => 'rs.g.' . $criteria->groupField, 'precision_threshold' => AggregationBuilder::CARDINALITY_THRESHOLD]]],
            ];
        }
        if ($missingPaths !== []) {
            $aggs['missing'] = ['filter' => ['bool' => ['filter' => [...$postFilters, $this->queries->allMissing($missingPaths)]]]];
        }
        if ($aggs !== []) {
            $body['aggs'] = $aggs;
        }

        return ['missing' => $missingPaths !== []];
    }

    /**
     * The native collapse picks the first document of a group in sort order, the reference orders ascending groups by the smallest
     * existing value, which differs as soon as a missing value is in play.
     *
     * @param array{missing: bool} $checks
     * @param array<string, mixed> $aggregations
     */
    private function assertCollapse(array $checks, array $aggregations): void
    {
        if ($checks['missing'] && (int) ($aggregations['missing']['doc_count'] ?? 0) > 0) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'collapse sorted ascending with missing sort values, the reference ignores them in the group minimum');
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(array $body): array
    {
        try {
            return $this->client->search(['index' => $this->alias(), 'body' => $body]);
        } catch (NotFoundHttpException) {
            throw ReadStoreException::noLiveGeneration();
        }
    }

    /**
     * @param list<string> $lines
     */
    private function bulk(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $response = $this->client->bulk([
            'refresh' => $this->refresh ? 'true' : 'false',
            'body' => implode("\n", $lines) . "\n",
        ]);

        if (($response['errors'] ?? false) !== true) {
            return;
        }

        foreach ($response['items'] ?? [] as $item) {
            $result = reset($item);
            if (\is_array($result) && isset($result['error'])) {
                throw ReadStoreException::invalidCriteria(\sprintf(
                    'OpenSearch rejected a document: %s %s',
                    $result['error']['type'] ?? 'error',
                    $result['error']['reason'] ?? '',
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function targets(?string $generation): array
    {
        $indices = $this->indices();

        if ($generation !== null) {
            $index = $this->index($generation);
            if (!\array_key_exists($index, $indices)) {
                throw ReadStoreException::unknownGeneration($generation);
            }

            return [$index];
        }

        return array_keys($indices);
    }

    /**
     * All generations of this store: index name => aliases.
     *
     * @return array<string, list<string>>
     */
    private function indices(): array
    {
        $pattern = $this->alias() . '_*';

        try {
            $response = $this->client->indices()->getAlias(['index' => $pattern]);
        } catch (NotFoundHttpException) {
            return [];
        }

        $indices = [];
        foreach ($response as $index => $info) {
            // never touch anything that is not ours, whatever the pattern matched
            if (!str_starts_with((string) $index, $this->alias() . '_')) {
                continue;
            }
            $indices[(string) $index] = array_values(array_map('strval', array_keys($info['aliases'] ?? [])));
        }

        return $indices;
    }

    /**
     * @param array<string, list<string>> $indices
     */
    private function liveIndex(array $indices): ?string
    {
        foreach ($indices as $index => $aliases) {
            if (\in_array($this->alias(), $aliases, true)) {
                return $index;
            }
        }

        return null;
    }

    private function alias(): string
    {
        return $this->prefix . $this->name;
    }

    private function index(string $generation): string
    {
        if (preg_match('/^[a-z0-9_]+\z/', $generation) !== 1) {
            throw ReadStoreException::invalidCriteria('an OpenSearch generation may contain lower case letters, digits and underscores only');
        }

        return $this->alias() . '_' . $generation;
    }
}
