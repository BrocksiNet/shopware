<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'property_group_option_translation')]
class PropertyGroupOptionTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PropertyGroupOption::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'property_group_option_id', referencedColumnName: 'id', nullable: false)]
    public PropertyGroupOption $option;

    #[ORM\Id]
    #[ORM\Column(name: 'language_id', type: 'shopware_id')]
    public string $languageId;

    #[ORM\Column(name: 'name', type: 'string', nullable: true)]
    public ?string $name = null;
}
