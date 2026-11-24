<?php declare(strict_types=1);

use OpenSearch\Client;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\IndexSchema;
use Shopware\Core\Framework\ReadStore\Adapter\OpenSearch\OpenSearchStorage;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff O: OpenSearch adapter. Indices are named `rs_os_product_<generation>` and the live one carries the alias `rs_os_product`.
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(IndexSchema::class);

    $services->set('read_store.storage.opensearch', OpenSearchStorage::class)
        ->public()
        ->args([
            service(Client::class),
            'rs_os_',
            'product',
            service(FieldResolver::class),
            service(IndexSchema::class),
        ])
        ->tag('read_store.storage', ['key' => 'opensearch']);
};
