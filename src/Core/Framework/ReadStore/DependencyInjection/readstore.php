<?php declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\ReadStore\Adapter\ArrayStore\ArrayStorage;
use Shopware\Core\Framework\ReadStore\Bench\StoreImplementation;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Builder\DalDocumentBuilder;
use Shopware\Core\Framework\ReadStore\Builder\DocumentExtenders;
use Shopware\Core\Framework\ReadStore\Builder\ProductIds;
use Shopware\Core\Framework\ReadStore\Builder\SqlDocumentBuilder;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Projector\ProjectProductsHandler;
use Shopware\Core\Framework\ReadStore\Projector\ReadStoreProjector;
use Shopware\Core\Framework\ReadStore\Projector\ReadStoreProjectorSubscriber;
use Shopware\Core\Framework\ReadStore\Projector\RebuildCommand;
use Shopware\Core\Framework\ReadStore\Projector\Rebuilder;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

// Wave 9 handoff K: port, product document, builders, bridge, projector and the Array adapter.
// Stores are registered as `read_store.storage.<key>` with the tag `read_store.storage` and the attribute `key` (e.g. `array`, `sql-mysql`, `opensearch`),
// document builders with the tag `read_store.document_builder` and the attribute `key` (`sql`, `dal`), document extenders with the tag `read_store.document_extender`.
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()
        // comma-separated store keys the projector keeps current, empty switches it off
        ->set('env(READ_STORE_PROJECTOR_STORES)', '')
        // `sync` (in the request, after the product indexer) or `queue` (one deduplicated message per indexer event)
        ->set('env(READ_STORE_PROJECTOR_MODE)', ReadStoreProjector::MODE_SYNC)
        ->set('env(READ_STORE_PROJECTOR_BUILDER)', 'sql');

    $services = $containerConfigurator->services();

    $services->set(FieldResolver::class);

    $services->set(QueryEvaluator::class)
        ->args([service(FieldResolver::class)]);

    $services->set('read_store.storage.array', ArrayStorage::class)
        ->public()
        ->args([param('kernel.project_dir') . '/var/read-store', service(QueryEvaluator::class)])
        ->tag('read_store.storage', ['key' => 'array']);

    $services->set(DocumentExtenders::class)
        ->args([tagged_iterator('read_store.document_extender')]);

    $services->set(SqlDocumentBuilder::class)
        ->public()
        ->args([service('Doctrine\DBAL\Connection'), service(DocumentExtenders::class)])
        ->tag('read_store.document_builder', ['key' => 'sql']);

    $services->set(DalDocumentBuilder::class)
        ->public()
        ->args([service('product.repository'), service('Doctrine\DBAL\Connection'), service(DocumentExtenders::class)])
        ->tag('read_store.document_builder', ['key' => 'dal']);

    $services->set(ProductIds::class)
        ->args([service('Doctrine\DBAL\Connection')]);

    $services->set(CriteriaBridge::class)
        ->public()
        ->args([service(FieldResolver::class), param('shopware.dal.max_rule_prices')]);

    $services->set(StorageRegistry::class)
        ->public()
        ->args([tagged_locator('read_store.storage', 'key'), tagged_locator('read_store.document_builder', 'key')]);

    $services->set(Rebuilder::class)
        ->args([service(ProductIds::class), service(ClockInterface::class)]);

    $services->set(RebuildCommand::class)
        ->args([service(StorageRegistry::class), service(Rebuilder::class), service(ClockInterface::class)])
        ->tag('console.command');

    $services->set(ReadStoreProjector::class)
        ->public()
        ->args([
            service(StorageRegistry::class),
            service('messenger.default_bus'),
            '%env(READ_STORE_PROJECTOR_STORES)%',
            '%env(READ_STORE_PROJECTOR_BUILDER)%',
            '%env(READ_STORE_PROJECTOR_MODE)%',
        ]);

    $services->set(ReadStoreProjectorSubscriber::class)
        ->args([service(ReadStoreProjector::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ProjectProductsHandler::class)
        ->args([service(ReadStoreProjector::class)])
        ->tag('messenger.message_handler');

    // the harness side of every store (`store-<key>`), see docs/40-read-store-port-and-document.md; the stores are registered in s.php, o.php and m.php
    foreach (['array', 'sql-mysql', 'sql-mariadb', 'sql-pgsql', 'sql-sqlite', 'opensearch', 'mongodb', 'valkey', 'opensearch-valkey'] as $key) {
        $services->set('read_store.bench.store.' . $key, StoreImplementation::class)
            ->args([
                service(StorageRegistry::class),
                $key,
                service(CriteriaBridge::class),
                service(CachedSalesChannelContextFactory::class),
                service(RequestCriteriaBuilder::class),
                service(ProductDefinition::class),
                service(ProductSearchBuilderInterface::class),
                service(Rebuilder::class),
            ])
            ->tag('orm_spike.implementation', ['key' => 'store-' . $key]);
    }
};
