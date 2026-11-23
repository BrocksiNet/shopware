<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Fair\Constraint\HexUuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Doctrine mapping of the existing `product` table (composite key id + version_id, parent self reference with parent_version_id).
 * Only the columns the spike scenarios use are mapped, unmapped columns keep their database defaults on insert.
 *
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    #[Assert\NotBlank]
    #[HexUuid]
    public string $id;

    #[ORM\Id]
    #[ORM\Column(name: 'version_id', type: 'shopware_id')]
    public string $versionId;

    #[ORM\Column(name: 'product_number', type: 'string')]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255, normalizer: 'trim')]
    public string $productNumber;

    /**
     * smallint on PostgreSQL, tinyint elsewhere: mapped as integer, a boolean type would bind a boolean into a smallint column
     */
    #[ORM\Column(name: 'active', type: 'smallint', nullable: true)]
    public ?int $active = null;

    #[ORM\Column(name: 'stock', type: 'integer')]
    #[Assert\NotNull]
    public int $stock = 0;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    #[ORM\Column(name: 'price', type: 'json', nullable: true)]
    #[Assert\NotBlank]
    #[Assert\All([new Assert\Collection(fields: [
        'currencyId' => [new Assert\NotBlank(), new HexUuid()],
        'gross' => [new Assert\NotBlank(), new Assert\Type('numeric')],
        'net' => [new Assert\NotBlank(), new Assert\Type('numeric')],
        'linked' => [new Assert\Type('bool')],
    ])])]
    public ?array $price = null;

    #[ORM\Column(name: 'tax_id', type: 'shopware_id', nullable: true)]
    #[Assert\NotBlank]
    #[HexUuid]
    public ?string $taxId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    /**
     * read only copies of join columns, so DQL conditions (`COALESCE(p.parentId, p.id)`) need no IDENTITY()
     */
    #[ORM\Column(name: 'parent_id', type: 'shopware_id', nullable: true, insertable: false, updatable: false)]
    public ?string $parentId = null;

    #[ORM\Column(name: 'product_manufacturer_id', type: 'shopware_id', nullable: true, insertable: false, updatable: false)]
    public ?string $manufacturerId = null;

    #[ORM\Column(name: 'product_media_id', type: 'shopware_id', nullable: true, insertable: false, updatable: false)]
    public ?string $coverId = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id')]
    #[ORM\JoinColumn(name: 'parent_version_id', referencedColumnName: 'version_id')]
    public ?Product $parent = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'product_manufacturer_id', referencedColumnName: 'id')]
    #[ORM\JoinColumn(name: 'product_manufacturer_version_id', referencedColumnName: 'version_id')]
    public ?Manufacturer $manufacturer = null;

    #[ORM\ManyToOne(targetEntity: ProductMedia::class)]
    #[ORM\JoinColumn(name: 'product_media_id', referencedColumnName: 'id')]
    #[ORM\JoinColumn(name: 'product_media_version_id', referencedColumnName: 'version_id')]
    public ?ProductMedia $cover = null;

    /**
     * @var Collection<int, ProductTranslation>
     */
    #[ORM\OneToMany(targetEntity: ProductTranslation::class, mappedBy: 'product', cascade: ['persist'])]
    #[Assert\Valid]
    public Collection $translations;

    /**
     * @var Collection<int, ProductPrice>
     */
    #[ORM\OneToMany(targetEntity: ProductPrice::class, mappedBy: 'product', cascade: ['persist'])]
    public Collection $prices;

    /**
     * @var Collection<int, ProductVisibility>
     */
    #[ORM\OneToMany(targetEntity: ProductVisibility::class, mappedBy: 'product', cascade: ['persist'])]
    #[Assert\Valid]
    public Collection $visibilities;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'product_category')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    #[ORM\JoinColumn(name: 'product_version_id', referencedColumnName: 'version_id')]
    #[ORM\InverseJoinColumn(name: 'category_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'category_version_id', referencedColumnName: 'version_id')]
    public Collection $categories;

    /**
     * @var Collection<int, PropertyGroupOption>
     */
    #[ORM\ManyToMany(targetEntity: PropertyGroupOption::class)]
    #[ORM\JoinTable(name: 'product_option')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    #[ORM\JoinColumn(name: 'product_version_id', referencedColumnName: 'version_id')]
    #[ORM\InverseJoinColumn(name: 'property_group_option_id', referencedColumnName: 'id')]
    public Collection $options;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->prices = new ArrayCollection();
        $this->visibilities = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->options = new ArrayCollection();
    }

    /**
     * Ids of the referenced categories, only for the write validation (no proxy is initialized).
     *
     * @return list<string>
     */
    #[Assert\All([new HexUuid()])]
    public function getCategoryIds(): array
    {
        return array_map(static fn (Category $category): string => $category->id, $this->categories->toArray());
    }
}
