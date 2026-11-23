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
#[ORM\Table(name: 'product_translation')]
class ProductTranslation
{
    /**
     * Doctrine cannot use an association to an entity with a composite key as part of its own key (ClassMetadata, cannotMapCompositePrimaryKeyEntitiesAsForeignId),
     * so the key columns are plain fields and the association shares the same columns.
     */
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    #[ORM\JoinColumn(name: 'product_version_id', referencedColumnName: 'version_id', nullable: false)]
    public Product $product;

    #[ORM\Id]
    #[ORM\Column(name: 'language_id', type: 'shopware_id')]
    #[Assert\NotBlank]
    #[HexUuid]
    public string $languageId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_id', type: 'shopware_id')]
    public string $productId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_version_id', type: 'shopware_id')]
    public string $productVersionId;

    #[ORM\Column(name: 'name', type: 'string', nullable: true)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255, normalizer: 'trim')]
    public ?string $name = null;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
