<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\OrmSpike\Dal\DalImplementation;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 8 ORM spike, DAL reference implementation (owner: handoff B).
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(DalImplementation::class)
        ->args([
            service('sales_channel.product.repository'),
            service('product.repository'),
            service(CachedSalesChannelContextFactory::class),
            service(RequestCriteriaBuilder::class),
            service(ProductDefinition::class),
        ])
        ->tag('orm_spike.implementation');
};
