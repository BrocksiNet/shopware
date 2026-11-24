<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bridge;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * What {@see CriteriaBridge::convert()} returns: the store criteria, the store context, and the aggregations the DAL would answer with
 * entities (`EntityAggregation`), which the store answers as terms over the id field, so the caller has to load the entities of the keys.
 *
 * @internal
 */
#[Package('framework')]
final readonly class BridgedCriteria
{
    /**
     * @param array<string, string> $entityAggregations aggregation name => entity name
     */
    public function __construct(
        public StoreCriteria $criteria,
        public StoreContext $context,
        public array $entityAggregations = [],
    ) {
    }
}
