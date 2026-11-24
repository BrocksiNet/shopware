<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Contract;

use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Max;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Stats;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;

/**
 * Contract of {@see \Shopware\Core\Framework\ReadStore\Port\AggregationAware}: aggregations see the filters, not the post filters, not the
 * collapse (`EntityAggregator` resets both), and each aggregation can have its own filters.
 *
 * @internal
 *
 * @phpstan-require-extends ContractTestCase
 */
trait AggregationContract
{
    public function testTermsCountsPerKey(): void
    {
        $terms = $this->terms(new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHIRTS)],
            aggregations: [new Terms('manufacturer', 'manufacturerId')],
        ), 'manufacturer');

        static::assertSame([F::MAN_A => 1, F::MAN_B => 2], $terms->counts());
    }

    public function testTermsKeepsTheNullBucket(): void
    {
        $terms = $this->terms(new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHOES)],
            aggregations: [new Terms('manufacturer', 'manufacturerId')],
        ), 'manufacturer');

        static::assertSame([F::MAN_A => 3, F::MAN_C => 1], $terms->counts());
        $nullBuckets = array_values(array_filter($terms->buckets, static fn (array $bucket): bool => $bucket['key'] === null));
        static::assertCount(1, $nullBuckets);
        static::assertSame(1, $nullBuckets[0]['count']);
    }

    public function testTermsOnAListFieldCountsDocumentsPerElement(): void
    {
        $terms = $this->terms(new StoreCriteria(
            filters: [new Equals('active', true)],
            aggregations: [new Terms('properties', 'propertyIds')],
        ), 'properties');

        static::assertSame([F::PROP_BLUE => 2, F::PROP_RED => 2], $terms->counts());
    }

    public function testTermsLimitKeepsTheLargestBuckets(): void
    {
        $terms = $this->terms(new StoreCriteria(
            filters: [new Equals('active', true)],
            aggregations: [new Terms('manufacturer', 'manufacturerId', limit: 1)],
        ), 'manufacturer');

        static::assertSame([F::MAN_A => 3], $terms->counts());
    }

    public function testMetrics(): void
    {
        $results = $this->aggregate(new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHIRTS)],
            aggregations: [
                new Min('min', 'price'),
                new Max('max', 'price'),
                new Avg('avg', 'price'),
                new Sum('sum', 'price'),
                new Stats('stats', 'price'),
                new Max('max-b', 'price', [new Equals('manufacturerId', F::MAN_B)]),
            ],
        ));

        static::assertEqualsWithDelta(10.0, $this->metric($results, 'min'), 0.001);
        static::assertEqualsWithDelta(20.0, $this->metric($results, 'max'), 0.001);
        static::assertEqualsWithDelta(15.0, $this->metric($results, 'avg'), 0.001);
        static::assertEqualsWithDelta(45.0, $this->metric($results, 'sum'), 0.001);
        static::assertEqualsWithDelta(20.0, $this->metric($results, 'max-b'), 0.001);

        $stats = $results['stats'] ?? null;
        static::assertInstanceOf(StatsResult::class, $stats);
        static::assertEqualsWithDelta(10.0, (float) $stats->min, 0.001);
        static::assertEqualsWithDelta(20.0, (float) $stats->max, 0.001);
        static::assertEqualsWithDelta(15.0, (float) $stats->avg, 0.001);
        static::assertEqualsWithDelta(45.0, (float) $stats->sum, 0.001);
    }

    public function testMetricsUseTheContextCurrencyAndSkipMissingValues(): void
    {
        $shirts = [new Equals('categoryTree', F::CAT_SHIRTS)];
        $usd = $this->aggregate(new StoreCriteria(filters: $shirts, aggregations: [new Max('max', 'price')]), F::context(currency: F::USD));
        static::assertEqualsWithDelta(22.0, $this->metric($usd, 'max'), 0.001);

        // S3 has no rating
        $rating = $this->aggregate(new StoreCriteria(filters: [new Equals('categoryTree', F::CAT_SHOES)], aggregations: [new Avg('avg', 'ratingAverage')]));
        static::assertEqualsWithDelta(4.0, $this->metric($rating, 'avg'), 0.001);
    }

    public function testCountCountsDistinctValues(): void
    {
        $results = $this->aggregate(new StoreCriteria(aggregations: [new Count('manufacturers', 'manufacturerId')]));

        static::assertEquals(3, $this->metric($results, 'manufacturers'));
    }

    public function testEmptyMatchGivesNullMetricsAndNoBuckets(): void
    {
        $results = $this->aggregate(new StoreCriteria(
            filters: [new Equals('productNumber', 'nope')],
            aggregations: [new Min('min', 'price'), new Terms('manufacturer', 'manufacturerId')],
        ));

        static::assertNull($this->metric($results, 'min'));
        $terms = $results['manufacturer'] ?? null;
        static::assertInstanceOf(TermsResult::class, $terms);
        static::assertSame([], $terms->buckets);
    }

    public function testFilterIgnoresPostFiltersAndCollapseForAggregations(): void
    {
        $result = $this->filter(new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHOES), new Equals('active', true)],
            postFilters: [new Equals('manufacturerId', F::MAN_A)],
            sortings: [new Sorting('price')],
            groupField: 'displayGroup',
            aggregations: [new Terms('manufacturer', 'manufacturerId'), new Sum('sum', 'price')],
        ));

        // hits: P (null group) and V1 (G_P) of manufacturer A
        static::assertSame([F::P, F::V1], $result->ids());

        // aggregations: S3 30, P 40, V1 40, V2 45
        $terms = $result->aggregations['manufacturer'] ?? null;
        static::assertInstanceOf(TermsResult::class, $terms);
        static::assertSame([F::MAN_A => 2, F::MAN_C => 1], $terms->counts());
        static::assertEqualsWithDelta(155.0, $this->metric($result->aggregations, 'sum'), 0.001);
    }

    public function testAggregateIgnoresPostFilters(): void
    {
        $terms = $this->terms(new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHIRTS)],
            postFilters: [new Equals('manufacturerId', F::MAN_B)],
            aggregations: [new Terms('manufacturer', 'manufacturerId')],
        ), 'manufacturer');

        static::assertSame([F::MAN_A => 1, F::MAN_B => 2], $terms->counts());
    }

    private function terms(StoreCriteria $criteria, string $name): TermsResult
    {
        $result = $this->aggregate($criteria)[$name] ?? null;
        static::assertInstanceOf(TermsResult::class, $result);

        return $result;
    }

    /**
     * @param array<string, AggregationResult> $results
     */
    private function metric(array $results, string $name): mixed
    {
        $result = $results[$name] ?? null;
        static::assertInstanceOf(MetricResult::class, $result);

        return $result->value;
    }
}
