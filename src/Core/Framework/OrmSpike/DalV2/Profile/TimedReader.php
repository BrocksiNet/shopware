<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Read\EntityReaderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class TimedReader implements EntityReaderInterface
{
    public function __construct(private readonly EntityReaderInterface $inner)
    {
    }

    public function read(EntityDefinition $definition, Criteria $criteria, Context $context): EntityCollection
    {
        Probe::enter('read.php');

        try {
            return $this->inner->read($definition, $criteria, $context);
        } finally {
            Probe::leave();
        }
    }
}
