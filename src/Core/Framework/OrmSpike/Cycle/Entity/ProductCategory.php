<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Fair\Constraint\HexUuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @internal
 */
#[Package('framework')]
class ProductCategory
{
    public string $productId;

    public string $productVersionId;

    #[Assert\NotBlank]
    #[HexUuid]
    public string $categoryId;

    public string $categoryVersionId;
}
