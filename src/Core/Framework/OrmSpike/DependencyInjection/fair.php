<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\OrmSpike\Fair\FairCheckCommand;
use Shopware\Core\Framework\OrmSpike\Fair\WriteContract;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenEvents;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\WriteCleaner;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(WriteContract::class)
        ->args([service('validator'), service(DefinitionInstanceRegistry::class)]);

    $services->set(WrittenEvents::class)
        ->args([service('event_dispatcher')]);

    $services->set(FairCheckCommand::class)
        ->args([service(ImplementationRegistry::class), service(ParamsFactory::class), service(WriteCleaner::class), service(Connection::class), service('event_dispatcher'), service(WriteContract::class), service('product.repository')])
        ->tag('console.command');
};
