<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Projection;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class ProductListItem
{
    public function __construct(
        #[Path('id')]
        public string $id,
        #[Path('productNumber')]
        public string $productNumber,
        #[Path('name')]
        public ?string $name,
        #[Path('manufacturer.name')]
        public ?string $manufacturer,
        #[Path('cover.media.path')]
        public ?string $cover,
        #[Path('price')]
        public mixed $price,
    ) {
    }
}
