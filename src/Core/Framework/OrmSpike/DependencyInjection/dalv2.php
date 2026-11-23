<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Wave 8 ORM spike, services of the dalv2 implementation (owner: its handoff). Register the implementation class like this:
//
//   $containerConfigurator->services()->set(\Shopware\Core\Framework\OrmSpike\DalV2\Implementation::class)
//       ->args([...])
//       ->tag('orm_spike.implementation');
//
// The class implements Shopware\Core\Framework\OrmSpike\Harness\Implementation, key() returns 'dalv2'.
return static function (ContainerConfigurator $containerConfigurator): void {
};
