<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProductCategoryTree
{
    public string $productId;

    public string $productVersionId;

    public string $categoryId;

    public string $categoryVersionId;
}
