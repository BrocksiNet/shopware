<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'property_group_translation')]
class PropertyGroupTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PropertyGroup::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'property_group_id', referencedColumnName: 'id', nullable: false)]
    public PropertyGroup $propertyGroup;

    #[ORM\Id]
    #[ORM\Column(name: 'language_id', type: 'shopware_id')]
    public string $languageId;

    #[ORM\Column(name: 'name', type: 'string', nullable: true)]
    public ?string $name = null;
}
