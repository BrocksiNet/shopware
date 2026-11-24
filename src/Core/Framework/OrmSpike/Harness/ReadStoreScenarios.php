<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * The read scenarios R3b and R4 of wave 9 (docs/39-read-store-baseline.md). An {@see Implementation} that also implements this
 * interface can be measured and verified with `orm-spike:bench --scenario=R3b,R4`; one that does not is reported as unsupported.
 * Result shapes: {@see ResultContract::R3B} and {@see ResultContract::R4}.
 *
 * @internal
 */
#[Package('framework')]
interface ReadStoreScenarios
{
    /**
     * R3b storefront search: word search with {@see Params::$searchWord} as the storefront does it (`ProductSearchBuilder`), the manufacturer
     * list as post filter, variant collapsing with `displayGroup`, name ASC then product number ASC, page 1 of 24, total,
     * min/max price and manufacturer terms aggregations over all matches of the term (not affected by the post filter).
     *
     * @return array<string, mixed>
     */
    public function r3bStorefrontSearch(Params $params): array;

    /**
     * R4 batch by id: the products of {@see Params::$mgetIds}, in that order, each normalized like S2.
     *
     * @return array<string, mixed>
     */
    public function r4Mget(Params $params): array;
}
