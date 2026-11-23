<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'product_media')]
class ProductMedia
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'shopware_id')]
    public string $id;

    #[ORM\Id]
    #[ORM\Column(name: 'version_id', type: 'shopware_id')]
    public string $versionId;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: false)]
    public Media $media;
}
