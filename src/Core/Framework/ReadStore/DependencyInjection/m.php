<?php declare(strict_types=1);

use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbQueryCompiler;
use Shopware\Core\Framework\ReadStore\Adapter\MongoDb\MongoDbStorage;
use Shopware\Core\Framework\ReadStore\Adapter\Valkey\ValkeyStorage;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff M: MongoDB and Valkey adapters. The composed OpenSearch + Valkey store (`opensearch-valkey`) follows once the OpenSearch
// adapter is committed. Namespaces stay below `rs_` (database `rs_readstore`, key prefix `rs_store`, Valkey database 5).
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
};
