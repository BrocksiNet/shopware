<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * One data access implementation of the wave 8 spike (DAL, hand-written DBAL, Doctrine ORM, Cycle ORM, DAL v2).
 * Every scenario method returns the normalized result array described in docs/30-orm-spike-baseline.md, so that
 * `orm-spike:bench --verify` can compare it with the DAL result.
 * An implementation that does not support a scenario throws {@see ScenarioNotSupported}.
 *
 * Register it in the services file of its folder (`OrmSpike/DependencyInjection/<impl>.php`) with the tag `orm_spike.implementation`.
 *
 * @internal
 */
#[Package('framework')]
interface Implementation
{
    /**
     * `dal`, `dbal`, `doctrine`, `cycle` or `dalv2`, the value of `--impl`.
     */
    public static function key(): string;

    /**
     * Gets the `--option key=value` pairs of the bench command, called once before the scenarios run.
     * An implementation ignores keys it does not know.
     *
     * @param array<string, string> $options
     */
    public function configure(array $options): void;

    /**
     * S1 listing, result: {@see ResultContract::S1}.
     *
     * @return array<string, mixed>
     */
    public function s1Listing(Params $params): array;

    /**
     * S2 variant detail, result: {@see ResultContract::S2}.
     *
     * @return array<string, mixed>
     */
    public function s2Detail(Params $params): array;

    /**
     * S3 search with aggregations, result: {@see ResultContract::S3}.
     *
     * @return array<string, mixed>
     */
    public function s3Search(Params $params): array;

    /**
     * S4 write: create the products of the write set in one call, then update their stock. Returns nothing,
     * the harness reads the written rows and removes them afterwards ({@see WriteSet}).
     */
    public function s4Write(Params $params, WriteSet $set): void;

    /**
     * S5 Store API criteria JSON ({@see Params::$criteriaJson}) turned into a query, result: {@see ResultContract::S5}.
     *
     * @return array<string, mixed>
     */
    public function s5CriteriaFromJson(Params $params): array;
}
