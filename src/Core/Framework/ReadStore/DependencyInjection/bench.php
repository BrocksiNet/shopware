<?php declare(strict_types=1);

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\ReadStore\Bench\DalOpenSearchImplementation;
use Shopware\Core\Framework\ReadStore\Bench\LagCommand;
use Shopware\Core\Framework\ReadStore\Bench\LoadCommand;
use Shopware\Core\Framework\ReadStore\Bench\LoadWorkerCommand;
use Shopware\Core\Framework\ReadStore\Bench\RebuildCommand;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff P: O1 (`dal-opensearch`) and the R5, R6, R7 commands. Adapters register their own `store-*` implementation
// in their own file of this folder with the tag `orm_spike.implementation` and the attribute `key` (see docs/39-read-store-baseline.md).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set('read_store.bench.dal_opensearch', DalOpenSearchImplementation::class)
        ->args([
            service('sales_channel.product.repository'),
            service('product.repository'),
            service(CachedSalesChannelContextFactory::class),
            service(RequestCriteriaBuilder::class),
            service(ProductDefinition::class),
            service(ProductSearchBuilderInterface::class),
            [Criteria::STATE_ELASTICSEARCH_AWARE],
        ])
        ->tag('orm_spike.implementation', ['key' => 'dal-opensearch']);

    $services->set(RebuildCommand::class)
        ->args([service(ImplementationRegistry::class), service(StatementCounter::class)])
        ->tag('console.command');

    $services->set(LagCommand::class)
        ->args([service(ImplementationRegistry::class), service(ParamsFactory::class), service('product.repository'), service(StatementCounter::class)])
        ->tag('console.command');

    $services->set(LoadWorkerCommand::class)
        ->args([service(ImplementationRegistry::class), service(ParamsFactory::class), service(StatementCounter::class)])
        ->tag('console.command');

    $services->set(LoadCommand::class)
        ->args([param('kernel.project_dir')])
        ->tag('console.command');
};
