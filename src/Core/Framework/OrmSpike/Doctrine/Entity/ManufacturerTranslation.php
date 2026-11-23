<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'product_manufacturer_translation')]
class ManufacturerTranslation
{
    #[ORM\ManyToOne(targetEntity: Manufacturer::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'product_manufacturer_id', referencedColumnName: 'id', nullable: false)]
    #[ORM\JoinColumn(name: 'product_manufacturer_version_id', referencedColumnName: 'version_id', nullable: false)]
    public Manufacturer $manufacturer;

    #[ORM\Id]
    #[ORM\Column(name: 'language_id', type: 'shopware_id')]
    public string $languageId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_manufacturer_id', type: 'shopware_id')]
    public string $manufacturerId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_manufacturer_version_id', type: 'shopware_id')]
    public string $manufacturerVersionId;

    #[ORM\Column(name: 'name', type: 'string', nullable: true)]
    public ?string $name = null;
}
