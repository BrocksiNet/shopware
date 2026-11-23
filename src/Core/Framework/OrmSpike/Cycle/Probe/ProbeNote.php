<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Probe;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ProbeNote
{
    public string $id;

    public string $productId;

    public string $note;
}
