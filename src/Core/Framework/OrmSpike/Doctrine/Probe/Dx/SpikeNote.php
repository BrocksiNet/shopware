<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Probe\Dx;

use Doctrine\ORM\Mapping as ORM;
use Shopware\Core\Framework\Log\Package;

/**
 * DX walkthrough: the whole definition of a new entity `spike_note`. Mapping is the attributes, there is no definition,
 * collection or repository class and no service registration (the mapping path of the entity manager covers the folder).
 *
 * @internal
 */
#[Package('framework')]
#[ORM\Entity]
#[ORM\Table(name: 'spike_note')]
class SpikeNote
{
    #[ORM\Id]
    #[ORM\Column(type: 'shopware_id')]
    public string $id;

    #[ORM\Column(type: 'string')]
    public string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $body = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
