<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProductMedia
{
    public string $id;

    public string $versionId;

    public string $productId;

    public string $productVersionId;

    public string $mediaId;

    public int $position;

    public ?string $createdAt = null;

    public ?Media $media = null;
}
