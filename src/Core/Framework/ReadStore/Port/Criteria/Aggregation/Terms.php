<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;

/**
 * Documents per distinct value; on a list field every element counts once per document (like the DAL join on `properties.id`).
 * Documents without a value form the bucket with key `null`, like SQL `GROUP BY`. Buckets are ordered by key, `limit` keeps the first ones
 * after ordering by count descending, key ascending.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Terms extends Aggregation
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        string $name,
        string $field,
        array $filters = [],
        public ?int $limit = null,
    ) {
        parent::__construct($name, $field, $filters);
    }
}
