<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class PropertyGroupOption
{
    public string $id;

    public string $groupId;

    public array $translations = [];

    public array $translationsAlt = [];

    public ?PropertyGroup $group = null;
}
