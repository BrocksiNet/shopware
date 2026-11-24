<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;

/**
 * An aggregation over the documents that match the criteria `filters` (not `postFilters`, no collapse) and its own `filters`
 * (the DAL `FilterAggregation` wrapped around it). Missing values are skipped, like SQL aggregate functions.
 *
 * @internal
 */
#[Package('framework')]
abstract readonly class Aggregation
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public string $name,
        public string $field,
        public array $filters = [],
    ) {
    }
}
