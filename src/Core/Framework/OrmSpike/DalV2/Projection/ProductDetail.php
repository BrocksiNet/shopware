<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Projection;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class ProductDetail
{
    /**
     * @param iterable<mixed> $prices
     * @param iterable<mixed> $options
     * @param iterable<mixed> $categories
     */
    public function __construct(
        #[Path('id')]
        public string $id,
        #[Path('parentId')]
        public ?string $parentId,
        #[Path('productNumber')]
        public string $productNumber,
        #[Path('name')]
        public ?string $name,
        #[Path('description')]
        public ?string $description,
        #[Path('manufacturerId')]
        public ?string $manufacturerId,
        #[Path('manufacturer.name')]
        public ?string $manufacturer,
        #[Path('cover.media.path')]
        public ?string $cover,
        #[Path('price')]
        public mixed $price,
        #[Path('prices', ['quantityStart', 'quantityEnd', 'price'])]
        public iterable $prices,
        #[Path('options', ['name', 'group.name'])]
        public iterable $options,
        #[Path('categories', ['id'])]
        public iterable $categories,
    ) {
    }
}
