<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\OrmSpike\DalV2\Read;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Aggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MinResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntityAggregatorInterface;
use Shopware\Core\Framework\OrmSpike\DalV2\Read\StatsFusionAggregator;

/**
 * @internal
 */
#[CoversClass(StatsFusionAggregator::class)]
class StatsFusionAggregatorTest extends TestCase
{
    public function testMinAndMaxOnOneFieldRunAsOneStatsAggregation(): void
    {
        $inner = new RecordingAggregator();
        $criteria = (new Criteria())->addAggregation(
            new MinAggregation('min-price', 'price'),
            new MaxAggregation('max-price', 'price'),
        );

        $result = (new StatsFusionAggregator($inner))->aggregate(new ProductDefinition(), $criteria, Context::createDefaultContext());

        static::assertCount(1, $inner->received);
        static::assertInstanceOf(StatsAggregation::class, array_values($inner->received)[0]);
        static::assertEquals(new MinResult('min-price', 1), $result->get('min-price'));
        static::assertEquals(new MaxResult('max-price', 9), $result->get('max-price'));
        static::assertSame(['min-price', 'max-price'], array_keys($result->getElements()));
    }

    public function testACallerAggregationWithTheInternalNameIsKept(): void
    {
        $inner = new RecordingAggregator();
        $criteria = (new Criteria())->addAggregation(
            new MinAggregation('min-price', 'price'),
            new MaxAggregation('max-price', 'price'),
            new CountAggregation('__dalv2_fused_stats_0', 'id'),
            new CountAggregation('__stats_price', 'id'),
        );

        $result = (new StatsFusionAggregator($inner))->aggregate(new ProductDefinition(), $criteria, Context::createDefaultContext());

        static::assertCount(3, $inner->received);
        static::assertEquals(new CountResult('__dalv2_fused_stats_0', 3), $result->get('__dalv2_fused_stats_0'));
        static::assertEquals(new CountResult('__stats_price', 3), $result->get('__stats_price'));
        static::assertEquals(new MinResult('min-price', 1), $result->get('min-price'));
        static::assertEquals(new MaxResult('max-price', 9), $result->get('max-price'));
        static::assertCount(4, $result);
    }
}

/**
 * @internal
 */
class RecordingAggregator implements EntityAggregatorInterface
{
    /**
     * @var array<string, Aggregation>
     */
    public array $received = [];

    public function aggregate(EntityDefinition $definition, Criteria $criteria, Context $context): AggregationResultCollection
    {
        $this->received = $criteria->getAggregations();
        $result = new AggregationResultCollection();
        foreach ($criteria->getAggregations() as $aggregation) {
            $result->add($aggregation instanceof StatsAggregation
                ? new StatsResult($aggregation->getName(), 1, 9, null, null)
                : new CountResult($aggregation->getName(), 3));
        }

        return $result;
    }
}
