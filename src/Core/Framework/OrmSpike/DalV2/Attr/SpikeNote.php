<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Attr;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;
use Shopware\Core\Framework\Log\Package;

/**
 * X3 walkthrough: the whole definition of the new entity `spike_note`.
 * There is no definition, collection or repository class; the service tag `shopware.entity` is the only registration.
 *
 * @internal
 */
#[Package('framework')]
#[Entity('spike_note', since: '6.7.0.0')]
class SpikeNote extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: true)]
    public string $id;

    #[Field(type: FieldType::STRING, api: true)]
    public string $title;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $body = null;
}
