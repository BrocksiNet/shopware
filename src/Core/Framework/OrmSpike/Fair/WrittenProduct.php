<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Fair;

use Shopware\Core\Framework\Log\Package;

/**
 * What one implementation wrote for one S4 product, read back from its own entities or rows. Input of {@see WrittenEvents}.
 *
 * @internal
 */
#[Package('framework')]
final readonly class WrittenProduct
{
    /**
     * @param list<array{languageId: string, name: string}> $translations
     * @param list<string> $categoryIds
     * @param list<array{id: string, salesChannelId: string, visibility: int}> $visibilities
     */
    public function __construct(
        public string $id,
        public string $productNumber,
        public int $stock,
        public int $newStock,
        public string $taxId,
        public bool $active,
        public string $currencyId,
        public float $gross,
        public float $net,
        public array $translations,
        public array $categoryIds,
        public array $visibilities,
    ) {
    }
}
