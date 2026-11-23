<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class ProbeResult extends AbstractResultMiddleware
{
    public function fetchAssociative(): array|false
    {
        Probe::enter('sql');

        try {
            return parent::fetchAssociative();
        } finally {
            Probe::leave();
        }
    }

    public function fetchAllAssociative(): array
    {
        Probe::enter('sql');

        try {
            return parent::fetchAllAssociative();
        } finally {
            Probe::leave();
        }
    }

    public function fetchFirstColumn(): array
    {
        Probe::enter('sql');

        try {
            return parent::fetchFirstColumn();
        } finally {
            Probe::leave();
        }
    }

    public function fetchOne(): mixed
    {
        Probe::enter('sql');

        try {
            return parent::fetchOne();
        } finally {
            Probe::leave();
        }
    }
}
