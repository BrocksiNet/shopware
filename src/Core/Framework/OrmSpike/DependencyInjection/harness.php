<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\BenchCommand;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\SeedCommand;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\OrmSpike\Harness\Verifier;
use Shopware\Core\Framework\OrmSpike\Harness\WriteCleaner;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSnapshot;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

// Wave 8 ORM spike, harness services (owner: handoff B). Loaded by Framework::build() together with the other files of this folder.
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(ParamsFactory::class)->args([service(Connection::class)]);
    $services->set(StatementCounter::class)->args([service(Connection::class)]);
    $services->set(WriteCleaner::class)->args([service(Connection::class)]);
    $services->set(WriteSnapshot::class)->args([service(Connection::class)]);
    $services->set(Verifier::class);

    $services->set(ImplementationRegistry::class)
        ->args([tagged_locator('orm_spike.implementation', 'key', 'key')]);

    $services->set(BenchCommand::class)
        ->args([
            service(ImplementationRegistry::class),
            service(ParamsFactory::class),
            service(StatementCounter::class),
            service(WriteCleaner::class),
            service(WriteSnapshot::class),
            service(Verifier::class),
            param('kernel.project_dir'),
            service(Connection::class),
        ])
        ->tag('console.command');

    $services->set(SeedCommand::class)
        ->args([
            service(Connection::class),
            service('product.repository'),
            service('category.repository'),
            service('product_manufacturer.repository'),
            service('media.repository'),
            service('property_group.repository'),
            service('rule.repository'),
            service(EntityIndexerRegistry::class),
            service(ParamsFactory::class),
        ])
        ->tag('console.command');
};
