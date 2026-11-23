<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * Read only view of the `product_category_tree` table (written by the category indexer), used for the listing filter.
 *
 * @internal
 */
#[Package('framework')]
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'product_category_tree')]
class ProductCategoryTree
{
    #[ORM\Id]
    #[ORM\Column(name: 'product_id', type: 'shopware_id')]
    public string $productId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_version_id', type: 'shopware_id')]
    public string $productVersionId;

    #[ORM\Id]
    #[ORM\Column(name: 'category_id', type: 'shopware_id')]
    public string $categoryId;

    #[ORM\Id]
    #[ORM\Column(name: 'category_version_id', type: 'shopware_id')]
    public string $categoryVersionId;
}
