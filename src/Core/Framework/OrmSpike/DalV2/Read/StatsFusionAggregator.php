<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Read;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Aggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MinResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntityAggregatorInterface;
use Shopware\Core\Framework\Log\Package;

/**
 * Lever A1: a `MinAggregation` and a `MaxAggregation` on the same field run as one statement.
 *
 * The DAL runs one statement per aggregation. This decorator replaces such a pair by one {@see StatsAggregation} (min and max only)
 * and splits the {@see StatsResult} back into the two result objects the caller asked for. Other aggregations are untouched.
 *
 * @internal
 */
#[Package('framework')]
class StatsFusionAggregator implements EntityAggregatorInterface
{
    public function __construct(private readonly EntityAggregatorInterface $inner)
    {
    }

    public function aggregate(EntityDefinition $definition, Criteria $criteria, Context $context): AggregationResultCollection
    {
        $mins = [];
        $maxs = [];
        foreach ($criteria->getAggregations() as $aggregation) {
            if ($aggregation instanceof MinAggregation) {
                $mins[$aggregation->getField()][] = $aggregation;
            } elseif ($aggregation instanceof MaxAggregation) {
                $maxs[$aggregation->getField()][] = $aggregation;
            }
        }

        /** @var array<string, array{min: MinAggregation, max: MaxAggregation}> $pairs */
        $pairs = [];
        foreach ($mins as $field => $minAggregations) {
            if (isset($maxs[$field])) {
                $pairs['__stats_' . $field] = ['min' => $minAggregations[0], 'max' => $maxs[$field][0]];
            }
        }

        if ($pairs === []) {
            return $this->inner->aggregate($definition, $criteria, $context);
        }

        $replaced = [];
        foreach ($pairs as $pair) {
            $replaced[$pair['min']->getName()] = true;
            $replaced[$pair['max']->getName()] = true;
        }

        $fused = clone $criteria;
        $fused->resetAggregations();
        $keep = array_filter($criteria->getAggregations(), static fn (Aggregation $a): bool => !isset($replaced[$a->getName()]));
        $fused->addAggregation(...array_values($keep));
        foreach ($pairs as $name => $pair) {
            $fused->addAggregation(new StatsAggregation($name, $pair['min']->getField(), true, true, false, false));
        }

        $result = $this->inner->aggregate($definition, $fused, $context);

        foreach ($pairs as $name => $pair) {
            $stats = $result->get($name);
            if (!$stats instanceof StatsResult) {
                continue;
            }
            $result->set($pair['min']->getName(), new MinResult($pair['min']->getName(), $stats->getMin()));
            $result->set($pair['max']->getName(), new MaxResult($pair['max']->getName(), $stats->getMax()));
            $result->remove($name);
        }

        return $result;
    }
}
