<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class PropertyGroup
{
    public string $id;
    public array $translations = [];
    public array $translationsAlt = [];
}
