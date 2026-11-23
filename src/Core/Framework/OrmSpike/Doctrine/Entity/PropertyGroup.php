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
#[ORM\Table(name: 'property_group')]
class PropertyGroup
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    public string $id;

    /**
     * @var Collection<int, PropertyGroupTranslation>
     */
    #[ORM\OneToMany(targetEntity: PropertyGroupTranslation::class, mappedBy: 'propertyGroup')]
    public Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }
}
