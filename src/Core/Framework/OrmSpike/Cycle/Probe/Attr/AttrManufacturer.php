<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Probe\Attr;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Cycle\HexIdTypecast;

/**
 * The attribute variant of one entity (`product_manufacturer`), for comparison with the runtime schema array.
 *
 * @internal
 */
#[Package('framework')]
#[Entity(role: 'attrManufacturer', table: 'product_manufacturer', database: 'default', typecast: [HexIdTypecast::class, \Cycle\ORM\Parser\Typecast::class])]
class AttrManufacturer
{
    #[Column(type: 'binary(16)', name: 'id', primary: true, typecast: HexIdTypecast::RULE)]
    public string $id;

    #[Column(type: 'binary(16)', name: 'version_id', primary: true, typecast: HexIdTypecast::RULE)]
    public string $versionId;

    #[Column(type: 'binary(16)', name: 'media_id', nullable: true, typecast: HexIdTypecast::RULE)]
    public ?string $mediaId = null;
}
