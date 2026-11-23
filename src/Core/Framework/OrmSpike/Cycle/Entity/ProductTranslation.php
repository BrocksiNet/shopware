<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProductTranslation
{
    public string $productId;

    public string $productVersionId;

    public string $languageId;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $createdAt = null;
}
