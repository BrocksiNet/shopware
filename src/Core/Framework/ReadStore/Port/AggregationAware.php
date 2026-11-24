<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;

/**
 * Marks that `filter()` and `search()` answer the aggregations of the criteria; `aggregate()` runs them alone (no hits, no total).
 * Aggregations match the criteria `filters` (and the search term), never `postFilters`, and ignore the collapse, like the DAL `EntityAggregator`.
 *
 * @internal
 */
#[Package('framework')]
interface AggregationAware
{
    /**
     * @throws NotSupportedByEngine
     *
     * @return array<string, AggregationResult> keyed by aggregation name
     */
    public function aggregate(StoreCriteria $criteria, StoreContext $context): array;
}
