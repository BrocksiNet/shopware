<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\OrmSpike\Dbal\DbalImplementation;
use Shopware\Core\Framework\OrmSpike\Fair\WriteContract;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenEvents;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 8 ORM spike, hand-written DBAL reference implementation (owner: handoff B).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(DbalImplementation::class)
        ->args([service(Connection::class), service(WriteContract::class), service(WrittenEvents::class)])
        ->tag('orm_spike.implementation');
};
