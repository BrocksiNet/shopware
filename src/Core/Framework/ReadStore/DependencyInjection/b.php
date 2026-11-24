<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\ReadStore\Builder\ProductIds;
use Shopware\Core\Framework\ReadStore\Builder\SqlDocumentBuilder;
use Shopware\Core\Framework\ReadStore\Probe\SourceLimitsProbeCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 10 handoff B: document size, mapped fields and Valkey cost per custom field and language count (docs/48).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(SourceLimitsProbeCommand::class)
        ->args([
            service(Connection::class),
            service(SqlDocumentBuilder::class),
            service(ProductIds::class),
            service('Shopware\Elasticsearch\Product\ElasticsearchProductDefinition')->nullOnInvalid(),
        ])
        ->tag('console.command');
};
