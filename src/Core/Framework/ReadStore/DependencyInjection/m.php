<?php declare(strict_types=1);

use OpenSearch\Client;
use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbStorage;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\IndexSchema;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\OpenSearchStorage;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearchValkey\OpenSearchValkeyStorage;
use Shopware\Core\Framework\ReadStore\Adapter\Valkey\ValkeyStorage;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff M: MongoDB, Valkey and the composed OpenSearch + Valkey store (`opensearch-valkey`, indices `rs_ov_product_<generation>`,
// keys `rs_ov`, its own namespaces so it never shares data with the plain `opensearch` and `valkey` stores). Namespaces stay below `rs_` (database `rs_readstore`, key prefix `rs_store`, Valkey database 5).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(MongoDbQueryCompiler::class)
        ->args([service(FieldResolver::class)]);

    $services->set('read_store.storage.mongodb', MongoDbStorage::class)
        ->public()
        ->args([
            'mongodb://instance-mongodb-1:27017',
            'rs_readstore',
            service(MongoDbQueryCompiler::class),
        ])
        ->tag('read_store.storage', ['key' => 'mongodb']);

    $services->set('read_store.storage.valkey', ValkeyStorage::class)
        ->public()
        ->args(['instance-valkey-1', 6379, 'rs_store'])
        ->tag('read_store.storage', ['key' => 'valkey']);

    $services->set('read_store.storage.opensearch-valkey', OpenSearchValkeyStorage::class)
        ->public()
        ->args([
            inline_service(OpenSearchStorage::class)->args([service(Client::class), 'rs_ov_', 'product', service(FieldResolver::class), service(IndexSchema::class)]),
            inline_service(ValkeyStorage::class)->args(['instance-valkey-1', 6379, 'rs_ov']),
        ])
        ->tag('read_store.storage', ['key' => 'opensearch-valkey']);
};
