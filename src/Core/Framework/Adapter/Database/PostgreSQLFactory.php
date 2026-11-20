<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\ExceptionConverterMiddleware;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\IdCodecMiddleware;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\PlanCacheMiddleware;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\SessionMiddleware;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\SqlTranslationMiddleware;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\TimingMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * PostgreSQL counterpart of MySQLFactory.
 * Read replicas and the DATABASE_SSL_* variables are not supported yet, use the sslmode/sslrootcert DSN parameters instead.
 *
 * @phpstan-import-type Params from DriverManager
 *
 * @internal
 */
#[Package('framework')]
class PostgreSQLFactory
{
    /**
     * @param array<Middleware> $middlewares
     */
    public static function create(array $middlewares = []): Connection
    {
        // Benchmark probes (docs/13-benchmark.md): SHOPWARE_PG_TIMING=<file> adds timers between the layers, off by default
        $timing = (string) EnvironmentHelper::getVariable('SHOPWARE_PG_TIMING', getenv('SHOPWARE_PG_TIMING'));
        $probe = static fn (string $label): array => $timing === '' ? [] : [new TimingMiddleware($label, $timing)];

        // Plan cache (docs/22): prepared statements live as long as the backend, so it needs a persistent connection
        $planCache = EnvironmentHelper::getVariable('DATABASE_PERSISTENT_CONNECTION') && EnvironmentHelper::getVariable('DATABASE_PLAN_CACHE')
            ? [new PlanCacheMiddleware()]
            : [];

        $config = (new Configuration())
            ->setMiddlewares([...$planCache, new SessionMiddleware(), ...$probe('driver'), new SqlTranslationMiddleware(), ...$probe('translated'), new IdCodecMiddleware(), ...$probe('codec'), new ExceptionConverterMiddleware(), ...$middlewares]);

        return DriverManager::getConnection(self::getConnectionParameters(), $config);
    }

    /**
     * PostgreSQL has no multi statement switch, the migration connection only must not share a persistent handle.
     */
    public static function createMigrationConnection(Connection $connection): Connection
    {
        $parameters = self::getConnectionParameters();
        $parameters['driverOptions'][\PDO::ATTR_PERSISTENT] = false;
        $parameters['persistent'] = false;

        return DriverManager::getConnection($parameters, $connection->getConfiguration());
    }

    /**
     * @return Params&array{charset: string, driverOptions: array<mixed>, ...}
     */
    private static function getConnectionParameters(): array
    {
        $url = (string) EnvironmentHelper::getVariable('DATABASE_URL', getenv('DATABASE_URL'));

        $dsnParser = new DsnParser([
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
        ]);
        $dsnParameters = $dsnParser->parse($url);

        $parameters = array_merge([
            'charset' => 'utf8',
            'driver' => 'pdo_pgsql',
        ], $dsnParameters);

        // Same fetch behaviour as the MySQL connection: every scalar is returned as string
        $parameters['driverOptions'] = [
            \PDO::ATTR_STRINGIFY_FETCHES => true,
            \PDO::ATTR_TIMEOUT => 5,
        ] + ($dsnParameters['driverOptions'] ?? []);

        if (EnvironmentHelper::getVariable('DATABASE_PERSISTENT_CONNECTION')) {
            $parameters['driverOptions'][\PDO::ATTR_PERSISTENT] = true;
        }

        return $parameters;
    }
}
