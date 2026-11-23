<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Projection;

use Shopware\Core\Framework\Log\Package;

/**
 * Names the DAL field path a constructor parameter of a projection DTO is filled from, e.g. `manufacturer.name`.
 * Translated and inherited values come out resolved, like `getTranslation()` on an entity.
 *
 * @internal
 */
#[Package('framework')]
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Path
{
    /**
     * @param list<string> $fields sub paths to load when the path is a to-many association
     */
    public function __construct(public readonly string $path, public readonly array $fields = [])
    {
    }
}
