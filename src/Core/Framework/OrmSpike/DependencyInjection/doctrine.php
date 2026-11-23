<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Wave 8 ORM spike, services of the doctrine implementation (owner: its handoff). Register the implementation class like this:
//
//   $containerConfigurator->services()->set(\Shopware\Core\Framework\OrmSpike\Doctrine\Implementation::class)
//       ->args([...])
//       ->tag('orm_spike.implementation');
//
// The class implements Shopware\Core\Framework\OrmSpike\Harness\Implementation, key() returns 'doctrine'.
return static function (ContainerConfigurator $containerConfigurator): void {
};
