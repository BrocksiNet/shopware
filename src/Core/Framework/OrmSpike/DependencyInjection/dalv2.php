<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\CriteriaFieldsResolver;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\CriteriaQueryBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\RowLoader;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEventFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntityAggregatorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Parser\SqlQueryParser;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Telemetry\DalSearchInstrumentor;
use Shopware\Core\Framework\DataAbstractionLayer\VersionManager;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\OrmSpike\DalV2\Attr\SpikeNote;
use Shopware\Core\Framework\OrmSpike\DalV2\Attr\SpikePluginNote;
use Shopware\Core\Framework\OrmSpike\DalV2\DalV2Implementation;
use Shopware\Core\Framework\OrmSpike\DalV2\Probe\DalV2ProbeCommand;
use Shopware\Core\Framework\OrmSpike\DalV2\Typed\FieldPathChecker;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\DalV2\Write\SwitchableWriteGateway;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 8 ORM spike, services of the dalv2 implementation (handoff V).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(DalV2Implementation::class)
        ->args([
            service(Connection::class),
            service('service_container'),
            service('sales_channel.product.repository'),
            service('product.repository'),
            service(CachedSalesChannelContextFactory::class),
            service(RequestCriteriaBuilder::class),
            service(ProductDefinition::class),
            service(EntityDefinitionQueryHelper::class),
            service(SqlQueryParser::class),
            service(CriteriaQueryBuilder::class),
            service('logger'),
            service(CriteriaFieldsResolver::class),
            service(SqlDialect::class),
            service(EntitySearcherInterface::class),
            service(EntityAggregatorInterface::class),
            service('event_dispatcher'),
            service(EntityLoadedEventFactory::class),
            service(DalSearchInstrumentor::class),
            service(VersionManager::class),
        ])
        ->tag('orm_spike.implementation');

    $services->set(SwitchableWriteGateway::class)
        ->decorate(EntityWriteGatewayInterface::class)
        ->args([
            service('.inner'),
            param('shopware.dal.batch_size'),
            service(Connection::class),
            service('event_dispatcher'),
            service(ExceptionHandlerRegistry::class),
            service(DefinitionInstanceRegistry::class),
            service(RowLoader::class),
            service(SqlDialect::class),
        ]);

    // X1 and X3: attribute entities, registered by the tag only (no definition class)
    $services->set(SpikeNote::class)->tag('shopware.entity');
    $services->set(SpikePluginNote::class)->tag('shopware.entity');

    $services->set(DalV2ProbeCommand::class)
        ->args([
            service(Connection::class),
            service('product.repository'),
            service('spike_note.repository'),
            service('spike_plugin_note.repository'),
            service(ParamsFactory::class),
            service(FieldPathChecker::class),
        ])
        ->tag('console.command');

    $services->set(FieldPathChecker::class)->args([service(DefinitionInstanceRegistry::class)]);
};
