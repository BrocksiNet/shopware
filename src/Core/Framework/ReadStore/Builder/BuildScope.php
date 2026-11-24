<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

/**
 * The shop data every document depends on: the languages (with the fallback chain a sales channel context of that language gets)
 * and the currencies (factor and item rounding). Loaded once per build run, ids are lower case hex.
 *
 * @internal
 */
#[Package('framework')]
final readonly class BuildScope
{
    /**
     * @param array<string, non-empty-list<string>> $languageChains language id => [language, parent language?, system language]
     * @param array<string, array{factor: float, decimals: int, interval: float, roundForNet: bool}> $currencies currency id => factor and item rounding
     */
    public function __construct(
        public array $languageChains,
        public array $currencies,
    ) {
    }

    public static function load(Connection $connection): self
    {
        $system = Defaults::LANGUAGE_SYSTEM;
        /** @var array<string, string|null> $parents */
        $parents = $connection->fetchAllKeyValue(\sprintf('SELECT %s, %s FROM language ORDER BY id', IdBinding::text('id'), IdBinding::text('parent_id')));

        $chains = [];
        foreach ($parents as $id => $parent) {
            // like ContextFactory::buildLanguageChain() of the sales channel context
            $chains[$id] = array_values(array_unique(array_filter([$id, $parent, $system])));
        }

        $currencies = [];
        foreach ($connection->fetchAllAssociative(\sprintf('SELECT %s AS id, factor, item_rounding FROM currency ORDER BY id', IdBinding::text('id'))) as $row) {
            /** @var array{decimals: int|string, interval: float|int|string, roundForNet: bool} $rounding */
            $rounding = json_decode((string) $row['item_rounding'], true, 512, \JSON_THROW_ON_ERROR);
            $currencies[(string) $row['id']] = [
                'factor' => (float) $row['factor'],
                'decimals' => (int) $rounding['decimals'],
                'interval' => (float) $rounding['interval'],
                'roundForNet' => (bool) $rounding['roundForNet'],
            ];
        }

        return new self($chains, $currencies);
    }

    /**
     * @return list<string>
     */
    public function languageIds(): array
    {
        return array_keys($this->languageChains);
    }
}
