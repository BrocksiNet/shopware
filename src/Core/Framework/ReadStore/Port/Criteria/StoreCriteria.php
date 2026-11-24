<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Aggregation;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;
use Shopware\Core\Framework\ReadStore\Port\Total;

/**
 * The query of {@see \Shopware\Core\Framework\ReadStore\Port\FilterAware} and {@see \Shopware\Core\Framework\ReadStore\Port\SearchAware}.
 * Only what S1 to S3, R3b, R4 and the Store API product routes need. `ids` restricts to these documents (like `new Criteria($ids)`),
 * without sortings the order of `ids` is kept.
 *
 * @internal
 */
#[Package('framework')]
final class StoreCriteria
{
    /**
     * @param list<string> $ids
     * @param list<Filter> $filters
     * @param list<Filter> $postFilters
     * @param list<Sorting> $sortings
     * @param list<Aggregation> $aggregations
     */
    public function __construct(
        public array $ids = [],
        public array $filters = [],
        public array $postFilters = [],
        public array $sortings = [],
        public ?string $groupField = null,
        public ?int $limit = null,
        public int $offset = 0,
        public Total $total = Total::NONE,
        public array $aggregations = [],
        public ?SearchTerm $search = null,
    ) {
    }
}
