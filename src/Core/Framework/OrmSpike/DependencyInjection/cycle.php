<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\OrmSpike\Cycle\CycleImplementation;
use Shopware\Core\Framework\OrmSpike\Cycle\Probe\ProbeCommand;
use Shopware\Core\Framework\OrmSpike\Fair\WriteContract;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenEvents;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 8 ORM spike, Cycle ORM implementation (owner: handoff C).
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->services()->set(CycleImplementation::class)
        ->args([service(Connection::class), service(StatementCounter::class), service(WriteContract::class), service(WrittenEvents::class)])
        ->tag('orm_spike.implementation');

    $containerConfigurator->services()->set(ProbeCommand::class)
        ->args([service(Connection::class), service(StatementCounter::class)])
        ->tag('console.command');
};
