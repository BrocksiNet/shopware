<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProductPrice
{
    public string $id;

    public string $versionId;

    public string $productId;

    public string $productVersionId;

    public string $ruleId;

    public string $price;

    public int $quantityStart;

    public ?int $quantityEnd = null;
}
