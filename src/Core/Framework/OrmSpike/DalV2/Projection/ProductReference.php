<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Projection;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class ProductReference
{
    public function __construct(
        #[Path('id')]
        public string $id,
        #[Path('productNumber')]
        public string $productNumber,
        #[Path('manufacturer.name')]
        public ?string $manufacturer,
    ) {
    }
}
