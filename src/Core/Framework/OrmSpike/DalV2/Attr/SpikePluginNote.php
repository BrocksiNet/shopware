<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Attr;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ManyToOne;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\OnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ReferenceVersion;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;
use Shopware\Core\Framework\Log\Package;

/**
 * Plugin probe: a new table with an association to the core `product`, without editing the core definition.
 *
 * @internal
 */
#[Package('framework')]
#[Entity('spike_plugin_note', since: '6.7.0.0')]
class SpikePluginNote extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: true)]
    public string $id;

    #[Field(type: FieldType::STRING, api: true)]
    public string $title;

    #[ForeignKey(entity: 'product', api: true)]
    public ?string $productId = null;

    #[ReferenceVersion(entity: 'product')]
    public ?string $productVersionId = null;

    #[ManyToOne(entity: 'product', onDelete: OnDelete::CASCADE, api: true)]
    public ?ProductEntity $product = null;
}
