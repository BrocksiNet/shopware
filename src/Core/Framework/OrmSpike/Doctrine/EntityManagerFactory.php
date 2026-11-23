<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Doctrine\Query\DialectFunction;
use Shopware\Core\Framework\OrmSpike\Doctrine\Type\ShopwareIdType;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * An EntityManager on the existing DBAL connection of Shopware (same middlewares, no second connection).
 * Attribute mapping, native PHP 8.4 lazy objects (no generated proxy classes), metadata and query cache as PSR-6 pool in the kernel cache directory.
 *
 * @internal
 */
#[Package('framework')]
final class EntityManagerFactory
{
    /**
     * @param string $namespace cache namespace, a second manager with other mapping (the probes) needs its own
     */
    public static function create(Connection $connection, string $cacheDir, string $namespace = 'orm_spike_doctrine', ?MappingDriver $driver = null, ?EventManager $events = null): EntityManagerInterface
    {
        if (!Type::hasType(ShopwareIdType::NAME)) {
            Type::addType(ShopwareIdType::NAME, ShopwareIdType::class);
        }

        $cache = new ChainAdapter([new ArrayAdapter(), new FilesystemAdapter($namespace, 0, $cacheDir . '/' . $namespace)]);
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Entity'], false, null, $cache);
        if ($driver !== null) {
            $config->setMetadataDriverImpl($driver);
        }
        $config->enableNativeLazyObjects(true);
        $config->addCustomStringFunction('SW_CI', static fn (string $name): DialectFunction => new DialectFunction($name, 'ci'));
        $config->addCustomNumericFunction('SW_PRICE', static fn (string $name): DialectFunction => new DialectFunction($name, 'price'));
        $config->addCustomNumericFunction('SW_TOTAL', static fn (string $name): DialectFunction => new DialectFunction($name, 'total'));

        return new EntityManager($connection, $config, $events);
    }
}
