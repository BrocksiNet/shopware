<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\OpenSearch;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Aggregation;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Max;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Stats;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\Schema\ResolvedField;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * Builds the aggregations of a request and reads the response. Every aggregation is a `filter` aggregation (its own filters)
 * that wraps the real one, so the filters of one aggregation do not leak into the others.
 *
 * `cardinality` and `terms` are only exact below their limits, a result that could be inexact throws {@see NotSupportedByEngine}.
 *
 * @internal
 */
#[Package('framework')]
final class AggregationBuilder
{
    public const CARDINALITY_THRESHOLD = 40000;

    private const ENGINE = 'opensearch';

    private const MAX_BUCKETS = 60000;

    public function __construct(
        private readonly FieldResolver $resolver,
        private readonly QueryBuilder $queries,
    ) {
    }

    /**
     * @param list<Aggregation> $aggregations
     *
     * @return array{aggs: array<string, mixed>, plan: list<array{key: string, aggregation: Aggregation, field: ResolvedField}>}
     */
    public function build(array $aggregations, StoreContext $context): array
    {
        $aggs = [];
        $plan = [];

        foreach ($aggregations as $index => $aggregation) {
            $field = $this->resolver->resolve($aggregation->field, $context);
            $key = 'a' . $index;

            $filter = $aggregation->filters === []
                ? ['match_all' => new \stdClass()]
                : ['bool' => ['filter' => $this->queries->all($aggregation->filters, $context)]];

            $aggs[$key] = ['filter' => $filter, 'aggs' => $this->inner($aggregation, $field)];
            $plan[] = ['key' => $key, 'aggregation' => $aggregation, 'field' => $field];
        }

        return ['aggs' => $aggs, 'plan' => $plan];
    }

    /**
     * @param list<array{key: string, aggregation: Aggregation, field: ResolvedField}> $plan
     * @param array<string, mixed> $response the `aggregations` of the search response
     *
     * @return array<string, AggregationResult>
     */
    public function results(array $plan, array $response): array
    {
        $results = [];
        foreach ($plan as ['key' => $key, 'aggregation' => $aggregation, 'field' => $field]) {
            $results[$aggregation->name] = $this->result($aggregation, $field, $response[$key] ?? []);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function inner(Aggregation $aggregation, ResolvedField $field): array
    {
        $paths = array_map(static fn (string $path): string => 'rs.f.' . $path, $field->dottedPaths());

        if ($aggregation instanceof Terms) {
            return $this->terms($aggregation, $field, $paths);
        }

        if ($aggregation instanceof Count) {
            if ($field->type === FieldType::ANY || $field->type === FieldType::OBJECT) {
                throw NotSupportedByEngine::field($aggregation->field, 'count of a field of unknown type');
            }

            return ['m' => ['cardinality' => $this->value($paths) + ['precision_threshold' => self::CARDINALITY_THRESHOLD]]];
        }

        if (!$aggregation instanceof Min && !$aggregation instanceof Max && !$aggregation instanceof Avg && !$aggregation instanceof Sum && !$aggregation instanceof Stats) {
            throw NotSupportedByEngine::feature(self::ENGINE, $aggregation::class);
        }

        if ($field->isList() || ($field->type !== FieldType::INT && $field->type !== FieldType::FLOAT)) {
            throw NotSupportedByEngine::field($aggregation->field, 'metrics are only supported on numbers');
        }

        return ['m' => ['stats' => $this->value($paths)]];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private function terms(Terms $terms, ResolvedField $field, array $paths): array
    {
        if (!\in_array($field->type, [FieldType::ID, FieldType::LIST, FieldType::INT, FieldType::FLOAT, FieldType::BOOL], true) || \count($paths) !== 1) {
            throw NotSupportedByEngine::field($terms->field, 'terms are only supported on ids, lists, numbers and booleans');
        }

        $missing = $field->isList()
            ? ['bool' => ['should' => [
                ['bool' => ['must_not' => [['exists' => ['field' => 'rs.n.' . $field->dottedPaths()[0]]]]]],
                ['term' => ['rs.n.' . $field->dottedPaths()[0] => 0]],
            ], 'minimum_should_match' => 1]]
            : $this->queries->allMissing($paths);

        return [
            'm' => ['terms' => ['field' => $paths[0], 'size' => self::MAX_BUCKETS]],
            'null' => ['filter' => $missing],
        ];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private function value(array $paths): array
    {
        if (\count($paths) === 1) {
            return ['field' => $paths[0]];
        }

        return ['script' => [
            'lang' => 'painless',
            'source' => 'for (String p : params.paths) { if (doc.containsKey(p) && doc[p].size() > 0) { return doc[p].value; } } return null;',
            'params' => ['paths' => $paths],
        ]];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function result(Aggregation $aggregation, ResolvedField $field, array $response): AggregationResult
    {
        $name = $aggregation->name;

        if ($aggregation instanceof Terms) {
            return new TermsResult($name, $this->buckets($aggregation, $field, $response));
        }

        if ($aggregation instanceof Count) {
            $value = (int) ($response['m']['value'] ?? 0);
            if ($value >= self::CARDINALITY_THRESHOLD) {
                throw NotSupportedByEngine::field($aggregation->field, 'the distinct count may be inexact above ' . self::CARDINALITY_THRESHOLD);
            }

            return new MetricResult($name, $value);
        }

        $stats = $response['m'] ?? [];
        $empty = (int) ($stats['count'] ?? 0) === 0;
        $number = fn (mixed $value): int|float|null => $empty || $value === null ? null : ($field->type === FieldType::INT ? (int) round((float) $value) : (float) $value);

        return match (true) {
            $aggregation instanceof Min => new MetricResult($name, $number($stats['min'] ?? null)),
            $aggregation instanceof Max => new MetricResult($name, $number($stats['max'] ?? null)),
            $aggregation instanceof Avg => new MetricResult($name, $empty ? null : (float) $stats['avg']),
            $aggregation instanceof Sum => new MetricResult($name, $empty ? null : (float) $stats['sum']),
            default => new StatsResult(
                $name,
                $number($stats['min'] ?? null),
                $number($stats['max'] ?? null),
                $empty ? null : (float) $stats['avg'],
                $empty ? null : (float) $stats['sum'],
            ),
        };
    }

    /**
     * Ordering and limit follow the reference evaluator: the limit keeps the highest counts, then the output is ordered by key.
     *
     * @param array<string, mixed> $response
     *
     * @return list<array{key: string|int|float|bool|null, count: int}>
     */
    private function buckets(Terms $terms, ResolvedField $field, array $response): array
    {
        if ((int) ($response['m']['sum_other_doc_count'] ?? 0) > 0) {
            throw NotSupportedByEngine::field($terms->field, 'more than ' . self::MAX_BUCKETS . ' distinct values');
        }

        $buckets = [];
        foreach ($response['m']['buckets'] ?? [] as $bucket) {
            $key = match ($field->type) {
                FieldType::INT => (int) $bucket['key'],
                FieldType::FLOAT => (float) $bucket['key'],
                FieldType::BOOL => ($bucket['key_as_string'] ?? '') === 'true',
                default => (string) $bucket['key'],
            };
            $buckets[] = ['key' => $key, 'count' => (int) $bucket['doc_count']];
        }

        $nulls = (int) ($response['null']['doc_count'] ?? 0);
        if ($nulls > 0) {
            $buckets[] = ['key' => null, 'count' => $nulls];
        }

        if ($terms->limit !== null) {
            usort($buckets, fn (array $a, array $b): int => [$b['count'], 0] <=> [$a['count'], $this->compare($a['key'], $b['key'], $field->type)]);
            $buckets = \array_slice($buckets, 0, $terms->limit);
        }
        usort($buckets, fn (array $a, array $b): int => $this->compare($a['key'], $b['key'], $field->type));

        return $buckets;
    }

    private function compare(string|int|float|bool|null $left, string|int|float|bool|null $right, FieldType $type): int
    {
        if ($left === null || $right === null) {
            return ($left === null ? 0 : 1) <=> ($right === null ? 0 : 1);
        }

        if ($type === FieldType::INT || $type === FieldType::FLOAT || $type === FieldType::BOOL) {
            return (float) $left <=> (float) $right;
        }

        return strcmp(strtolower((string) $left), strtolower((string) $right));
    }
}
