<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityHydrator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Log\Package;

/**
 * Hydrator that only adds the probe, the recursion into associations is handled by the exclusive time of the stack.
 *
 * @internal
 */
#[Package('framework')]
class TimedHydrator extends EntityHydrator
{
    public function hydrate(EntityCollection $collection, string $entityClass, EntityDefinition $definition, array $rows, string $root, Context $context, array $partial = []): EntityCollection
    {
        Probe::enter('hydrate');

        try {
            return parent::hydrate($collection, $entityClass, $definition, $rows, $root, $context, $partial);
        } finally {
            Probe::leave();
        }
    }
}
