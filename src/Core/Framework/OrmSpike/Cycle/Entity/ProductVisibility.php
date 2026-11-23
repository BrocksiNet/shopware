<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProductVisibility
{
    public string $id;
    public string $productId;
    public string $productVersionId;
    public string $salesChannelId;
    public int $visibility;
    public ?string $createdAt = null;
}
