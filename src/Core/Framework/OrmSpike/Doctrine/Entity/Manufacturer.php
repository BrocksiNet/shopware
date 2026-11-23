<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'product_manufacturer')]
class Manufacturer
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    public string $id;

    #[ORM\Id]
    #[ORM\Column(name: 'version_id', type: 'shopware_id')]
    public string $versionId;

    /**
     * @var Collection<int, ManufacturerTranslation>
     */
    #[ORM\OneToMany(targetEntity: ManufacturerTranslation::class, mappedBy: 'manufacturer')]
    public Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }
}
