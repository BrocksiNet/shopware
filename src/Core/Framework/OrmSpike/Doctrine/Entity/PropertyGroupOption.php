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
#[ORM\Table(name: 'property_group_option')]
class PropertyGroupOption
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    public string $id;

    #[ORM\ManyToOne(targetEntity: PropertyGroup::class)]
    #[ORM\JoinColumn(name: 'property_group_id', referencedColumnName: 'id', nullable: false)]
    public PropertyGroup $group;

    /**
     * @var Collection<int, PropertyGroupOptionTranslation>
     */
    #[ORM\OneToMany(targetEntity: PropertyGroupOptionTranslation::class, mappedBy: 'option')]
    public Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }
}
