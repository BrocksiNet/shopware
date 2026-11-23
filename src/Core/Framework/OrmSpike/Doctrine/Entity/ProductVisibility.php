<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Fair\Constraint\HexUuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'product_visibility')]
class ProductVisibility
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    #[Assert\NotBlank]
    #[HexUuid]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'visibilities')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    #[ORM\JoinColumn(name: 'product_version_id', referencedColumnName: 'version_id', nullable: false)]
    public Product $product;

    #[ORM\Column(name: 'product_id', type: 'shopware_id', insertable: false, updatable: false)]
    public ?string $productId = null;

    #[ORM\Column(name: 'product_version_id', type: 'shopware_id', insertable: false, updatable: false)]
    public ?string $productVersionId = null;

    #[ORM\Column(name: 'sales_channel_id', type: 'shopware_id')]
    #[Assert\NotBlank]
    #[HexUuid]
    public string $salesChannelId;

    #[ORM\Column(name: 'visibility', type: 'integer')]
    #[Assert\Choice(choices: [10, 20, 30], strict: true)]
    public int $visibility;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
