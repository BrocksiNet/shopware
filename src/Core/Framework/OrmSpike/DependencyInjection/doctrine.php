<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Shopware\Core\Framework\OrmSpike\Doctrine\DoctrineImplementation;
use Shopware\Core\Framework\OrmSpike\Doctrine\EntityManagerFactory;
use Shopware\Core\Framework\OrmSpike\Doctrine\Probe\ProbeCommand;
use Shopware\Core\Framework\OrmSpike\Fair\WriteContract;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenEvents;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\WriteCleaner;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 8 ORM spike, services of the Doctrine ORM implementation (owner: handoff D).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set('orm_spike.doctrine.entity_manager', EntityManagerInterface::class)
        ->factory([EntityManagerFactory::class, 'create'])
        ->args([service(Connection::class), '%kernel.cache_dir%']);

    $services->set(DoctrineImplementation::class)
        ->args([service('orm_spike.doctrine.entity_manager'), service(WriteContract::class), service(WrittenEvents::class)])
        ->tag('orm_spike.implementation');

    $services->set(ProbeCommand::class)
        ->args([
            service(Connection::class),
            service('orm_spike.doctrine.entity_manager'),
            service(DoctrineImplementation::class),
            service(ParamsFactory::class),
            service(WriteCleaner::class),
            '%kernel.cache_dir%',
        ])
        ->tag('console.command');
};
