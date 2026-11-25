<?php declare(strict_types=1);

use OpenSearch\Client;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\IndexSchema;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\OpenSearchStorage;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearchValkey\OpenSearchValkeyStorage;
use Shopware\Core\Framework\ReadStore\Adapter\Valkey\ValkeyStorage;
use Shopware\Core\Framework\ReadStore\Bench\StoreImplementation;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Projector\Rebuilder;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 10 handoff A: the composed OpenSearch + Valkey store in its own wave 10 namespaces (indices `rs_w10_ov_product_<generation>`,
// Valkey keys `rs_w10_ov` in database 5), so the O1 / O1a / store-opensearch-valkey comparison never shares data with wave 9 stores.
// The O1a projector target (`opensearch-source`) lives in the Elasticsearch bundle, next to the product index it writes.
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set('read_store.storage.w10-opensearch-valkey', OpenSearchValkeyStorage::class)
        ->public()
        ->args([
            inline_service(OpenSearchStorage::class)->args([service(Client::class), 'rs_w10_ov_', 'product', service(FieldResolver::class), service(IndexSchema::class)]),
            inline_service(ValkeyStorage::class)->args(['instance-valkey-1', 6379, 'rs_w10_ov']),
        ])
        ->tag('read_store.storage', ['key' => 'w10-opensearch-valkey']);

    $services->set('read_store.bench.store.w10-opensearch-valkey', StoreImplementation::class)
        ->args([
            service(StorageRegistry::class),
            'w10-opensearch-valkey',
            service(CriteriaBridge::class),
            service(CachedSalesChannelContextFactory::class),
            service(RequestCriteriaBuilder::class),
            service(ProductDefinition::class),
            service(ProductSearchBuilderInterface::class),
            service(Rebuilder::class),
        ])
        ->tag('orm_spike.implementation', ['key' => 'store-w10-opensearch-valkey']);
};
