<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class ParamsFactory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(): Params
    {
        return new Params(
            salesChannelId: $this->one('SELECT ' . IdBinding::text('id') . ' FROM sales_channel WHERE type_id = :t', ['t' => IdBinding::value(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)], 'storefront sales channel'),
            languageId: $this->one('SELECT ' . IdBinding::text('l.id') . ' FROM language l INNER JOIN locale lo ON lo.id = l.translation_code_id WHERE lo.code = :c', ['c' => 'de-DE'], 'language de-DE'),
            systemLanguageId: Defaults::LANGUAGE_SYSTEM,
            currencyId: Defaults::CURRENCY,
            taxId: $this->one('SELECT ' . IdBinding::text('id') . ' FROM tax WHERE name = :n', ['n' => 'Standard rate'], 'tax "Standard rate"'),
            ruleId: Fixtures::id('rule'),
            liveVersionId: Defaults::LIVE_VERSION,
            categoryId: Fixtures::categoryId(5),
            page: 2,
            limit: 24,
            searchTerm: 'LI',
            manufacturerIds: array_map(Fixtures::manufacturerId(...), [3, 7, 11, 17, 23]),
            variantId: Fixtures::variantId(15, 0),
            criteriaJson: [
                'filter' => [
                    ['type' => 'equals', 'field' => 'active', 'value' => true],
                    ['type' => 'range', 'field' => 'stock', 'parameters' => ['gte' => 20, 'lte' => 80]],
                    ['type' => 'contains', 'field' => 'name', 'value' => 'o'],
                ],
                'sort' => [['field' => 'productNumber', 'order' => 'DESC']],
                'limit' => 10,
                'total-count-mode' => 1,
                'associations' => ['manufacturer' => []],
            ],
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function one(string $sql, array $params, string $what): string
    {
        $value = $this->connection->fetchOne($sql, $params);
        if (!\is_string($value) || $value === '') {
            throw new \RuntimeException(\sprintf('Spike parameter "%s" not found, run orm-spike:seed on this database first.', $what));
        }

        return strtolower($value);
    }
}
