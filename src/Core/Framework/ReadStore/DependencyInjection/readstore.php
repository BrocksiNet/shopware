<?php declare(strict_types=1);

use Shopware\Core\Framework\ReadStore\Adapter\ArrayStore\ArrayStorage;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff K: port, product document, Array adapter. Stores are registered as `read_store.storage.<id>` with the tag
// `read_store.storage` and the attribute `key` (the store id, e.g. `array`, `sql-mysql`, `opensearch`).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(FieldResolver::class);

    $services->set(QueryEvaluator::class)
        ->args([service(FieldResolver::class)]);

    $services->set('read_store.storage.array', ArrayStorage::class)
        ->public()
        ->args([param('kernel.project_dir') . '/var/read-store', service(QueryEvaluator::class)])
        ->tag('read_store.storage', ['key' => 'array']);
};
