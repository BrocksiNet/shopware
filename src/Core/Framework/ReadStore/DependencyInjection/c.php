<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use Shopware\Core\Content\Product\Stock\StockStorage;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreConfig;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreGateway;
use Shopware\Core\Framework\ReadStore\Probe\ConsistencyProbeCommand;
use Shopware\Core\Framework\ReadStore\Probe\SamplePluginExtender;
use Shopware\Core\Framework\ReadStore\Projector\Rebuilder;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff C: consistency miss probes, extension probe, outage fallback, O3 copy step.
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(SamplePluginExtender::class)
        ->tag('read_store.document_extender');

    $services->set(ConsistencyProbeCommand::class)
        ->args([
            service(Connection::class),
            service(StorageRegistry::class),
            service(Rebuilder::class),
            service('product.repository'),
            service('product_manufacturer.repository'),
            service('category.repository'),
            service('property_group_option.repository'),
            service('media.repository'),
            service('tax.repository'),
            service('currency.repository'),
            service('rule.repository'),
            service(StockStorage::class),
            service('event_dispatcher'),
            service(ReadStoreConfig::class),
            service(ReadStoreGateway::class),
            service(ProductDetailRoute::class),
            service(ProductListingRoute::class),
            service(CachedSalesChannelContextFactory::class),
            service(ParamsFactory::class),
            service('clock'),
        ])
        ->tag('console.command');
};
