<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProductOption
{
    public string $productId;

    public string $productVersionId;

    public string $optionId;

    public ?PropertyGroupOption $option = null;
}
