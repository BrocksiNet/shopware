<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'product_price')]
class ProductPrice
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    public string $id;

    #[ORM\Id]
    #[ORM\Column(name: 'version_id', type: 'shopware_id')]
    public string $versionId;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    #[ORM\JoinColumn(name: 'product_version_id', referencedColumnName: 'version_id', nullable: false)]
    public Product $product;

    #[ORM\Column(name: 'product_id', type: 'shopware_id', insertable: false, updatable: false)]
    public ?string $productId = null;

    #[ORM\Column(name: 'product_version_id', type: 'shopware_id', insertable: false, updatable: false)]
    public ?string $productVersionId = null;

    #[ORM\Column(name: 'rule_id', type: 'shopware_id')]
    public string $ruleId;

    /**
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(name: 'price', type: 'json')]
    public array $price = [];

    #[ORM\Column(name: 'quantity_start', type: 'integer')]
    public int $quantityStart = 1;

    #[ORM\Column(name: 'quantity_end', type: 'integer', nullable: true)]
    public ?int $quantityEnd = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
