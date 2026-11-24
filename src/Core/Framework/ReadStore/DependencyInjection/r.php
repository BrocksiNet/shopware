<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Category\Service\CategoryBreadcrumbBuilder;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoader;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductConfiguratorLoader;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRoute;
use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Integration\DocumentHydrator;
use Shopware\Core\Framework\ReadStore\Integration\IntegrationBenchCommand;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreConfig;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreGateway;
use Shopware\Core\Framework\ReadStore\Integration\RouteExtensionSubscriber;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff R: Store API route integration via ExtensionDispatcher `.pre` listeners.
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(ReadStoreConfig::class)
        ->args([
            service(SystemConfigService::class),
            service('request_stack'),
        ]);

    $services->set(DocumentHydrator::class)
        ->args([
            service(SalesChannelProductDefinition::class),
            service(ProductDefinition::class),
            service('event_dispatcher'),
            service(DefinitionInstanceRegistry::class),
        ]);

    $services->set(ReadStoreGateway::class)
        ->args([
            service(ReadStoreConfig::class),
            service(StorageRegistry::class),
            service(CriteriaBridge::class),
            service(DocumentHydrator::class),
            service(SystemConfigService::class),
            service(CategoryBreadcrumbBuilder::class),
            service(ProductConfiguratorLoader::class),
            service(SalesChannelCmsPageLoader::class),
            service(SalesChannelProductDefinition::class),
            service('event_dispatcher'),
            service(CacheTagCollector::class),
            service('logger'),
        ]);

    $services->set(RouteExtensionSubscriber::class)
        ->args([
            service(ReadStoreGateway::class),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(IntegrationBenchCommand::class)
        ->args([
            service(ReadStoreConfig::class),
            service(ReadStoreGateway::class),
            service(ProductDetailRoute::class),
            service(ProductListingRoute::class),
            service(ProductSearchRoute::class),
            service(CachedSalesChannelContextFactory::class),
            service(ParamsFactory::class),
            service(StatementCounter::class),
            service(Connection::class),
            service('clock'),
        ])
        ->tag('console.command');
};
