<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * The data of one S4 run: 50 products, each with 2 translations (system language en-GB and de-DE), 1 price (default currency),
 * 2 categories and a visibility (storefront sales channel, visibility 30), then the stock of all 50 is updated to `newStock`.
 * Live version, tax {@see Params::$taxId}, active = true, no manufacturer, no media.
 *
 * @internal
 */
#[Package('framework')]
final readonly class WriteSet
{
    /**
     * @param list<array{id: string, productNumber: string, nameEn: string, nameDe: string, gross: float, net: float, stock: int, newStock: int, categoryIds: list<string>}> $products
     */
    public function __construct(
        public string $runId,
        public array $products
    ) {
    }

    public static function forRun(string $runId): self
    {
        return new self($runId, Fixtures::writeProducts($runId));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_column($this->products, 'id');
    }
}
