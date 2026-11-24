<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;

/**
 * Filter, sort, page and collapse in one round trip.
 *
 * Semantics every adapter must keep (the contract suite checks them, they follow the DAL on MySQL):
 * - hits match `filters` and `postFilters`, aggregations match `filters` only and ignore the collapse;
 * - with a `groupField`, hits are collapsed to one document per distinct value (`null` is one group of its own, like SQL `GROUP BY`),
 *   groups are ordered by the minimum (ascending sort) or maximum (descending sort) of each sort field over the group's matching documents,
 *   the representative is the group's first document in the sort order, ties by id; `total` counts groups;
 * - string comparisons of `Equals`, `EqualsAny`, `Contains` and `Prefix` are case-insensitive, ids compare exactly;
 * - `Equals` and `EqualsAny` are null-safe (`null` matches `null`, a missing value does not match a value, also under `Not`),
 *   `Contains`, `Prefix`, `Range` and filters on a missing list are unknown for a missing value (three-valued logic, as SQL);
 * - `Contains` is a substring match on the resolved field, not a word search, see {@see SearchAware}.
 *
 * An adapter that implements this interface but not {@see AggregationAware} throws {@see NotSupportedByEngine} for criteria with aggregations.
 *
 * @internal
 */
#[Package('framework')]
interface FilterAware
{
    /**
     * @throws NotSupportedByEngine when the criteria use a field, filter, aggregation or option the engine cannot answer
     */
    public function filter(StoreCriteria $criteria, StoreContext $context): StoreResult;
}
