<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Probe\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Doctrine\Entity\Product;

/**
 * Probe "plugin extension": an entity a plugin ships for a table the plugin creates, associated with the core `Product`
 * without editing the core class.
 *
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'spike_plugin_note')]
class PluginNote
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    #[ORM\JoinColumn(name: 'product_version_id', referencedColumnName: 'version_id', nullable: false)]
    public Product $product;

    #[ORM\Column(name: 'note', type: 'string')]
    public string $note;
}
