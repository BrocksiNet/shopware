<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\OrmSpike\Cycle\CycleImplementation;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 8 ORM spike, Cycle ORM implementation (owner: handoff C).
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->services()->set(CycleImplementation::class)
        ->args([service(Connection::class), service(StatementCounter::class)])
        ->tag('orm_spike.implementation');
};
