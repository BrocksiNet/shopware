<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ManufacturerTranslation
{
    public string $manufacturerId;
    public string $manufacturerVersionId;
    public string $languageId;
    public ?string $name = null;
    public ?string $createdAt = null;
}
