<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Fair\Constraint\HexUuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @internal
 */
#[Package('framework')]
class ProductTranslation
{
    public string $productId;

    public string $productVersionId;

    #[Assert\NotBlank]
    #[HexUuid]
    public string $languageId;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255, normalizer: 'trim')]
    public ?string $name = null;

    public ?string $description = null;

    public ?string $createdAt = null;
}
