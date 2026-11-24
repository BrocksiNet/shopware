<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
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
 * Supports read replicas (DATABASE_REPLICA_<n>_URL) and maps the DATABASE_SSL_* variables to the libpq ssl parameters.
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

        return DriverManager::getConnection(self::addReplicaParameters(self::getConnectionParameters()), $config);
    }

    /**
     * PostgreSQL has no multi statement switch, the migration connection only must not share a persistent handle.
     */
    public static function createMigrationConnection(Connection $connection): Connection
    {
        // Migrations must read their own writes and schema changes, so they never use a replica
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

        $dsnParameters = self::parseDsn($url);

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

        return self::addSslParameters($parameters);
    }

    /**
     * pdo_pgsql has no PDO attributes for TLS, libpq takes sslmode, sslrootcert, sslcert and sslkey in the DSN.
     * The DATABASE_SSL_* variables of MySQLFactory map onto them; values in DATABASE_URL win.
     * A CA means verify-full (certificate and host name), DATABASE_SSL_DONT_VERIFY_SERVER_CERT means require (encrypted, not verified).
     *
     * @param Params&array{charset: string, driverOptions: array<mixed>, ...} $parameters
     *
     * @return Params&array{charset: string, driverOptions: array<mixed>, ...}
     */
    private static function addSslParameters(array $parameters): array
    {
        $ssl = array_filter([
            'sslrootcert' => (string) EnvironmentHelper::getVariable('DATABASE_SSL_CA', ''),
            'sslcert' => (string) EnvironmentHelper::getVariable('DATABASE_SSL_CERT', ''),
            'sslkey' => (string) EnvironmentHelper::getVariable('DATABASE_SSL_KEY', ''),
        ], static fn (string $value): bool => $value !== '');

        if (EnvironmentHelper::getVariable('DATABASE_SSL_DONT_VERIFY_SERVER_CERT')) {
            $ssl['sslmode'] = 'require';
        } elseif (isset($ssl['sslrootcert'])) {
            $ssl['sslmode'] = 'verify-full';
        }

        foreach ($ssl as $key => $value) {
            $parameters[$key] ??= $value;
        }

        return $parameters;
    }

    /**
     * Same contract as MySQLFactory: DATABASE_REPLICA_0_URL, DATABASE_REPLICA_1_URL, ... turn the connection into a
     * PrimaryReadReplicaConnection that keeps its replica, so ReplicaConnectionResetter can switch back between requests.
     * Replicas share the driver options and the SSL parameters of the primary; a plan cache lives per backend, so it works on both.
     *
     * @param Params&array{charset: string, driverOptions: array<mixed>, ...} $parameters
     *
     * @return Params
     */
    private static function addReplicaParameters(array $parameters): array
    {
        if ((string) EnvironmentHelper::getVariable('DATABASE_REPLICA_0_URL', '') === '') {
            return $parameters;
        }

        $parameters['wrapperClass'] ??= PrimaryReadReplicaConnection::class;
        $parameters['keepReplica'] = \array_key_exists('keepReplica', $parameters)
            ? filter_var($parameters['keepReplica'], \FILTER_VALIDATE_BOOL)
            : true;

        $primary = $parameters;
        unset($primary['wrapperClass'], $primary['primary'], $primary['replica'], $primary['keepReplica']);
        $parameters['primary'] = $primary;
        $parameters['replica'] = [];

        for ($i = 0; $replicaUrl = (string) EnvironmentHelper::getVariable('DATABASE_REPLICA_' . $i . '_URL', ''); ++$i) {
            $replica = self::parseDsn($replicaUrl);
            unset($replica['primary'], $replica['replica'], $replica['keepReplica'], $replica['wrapperClass']);

            $parameters['replica'][$i] = self::addSslParameters(array_merge([
                'charset' => $primary['charset'],
                'driver' => 'pdo_pgsql',
            ], $replica, [
                'driverOptions' => $primary['driverOptions'] + ($replica['driverOptions'] ?? []),
            ]));
        }

        // DBAL's OverrideParams shape for primary and replica lists no sslmode/sslrootcert/sslcert/sslkey, the pdo_pgsql driver reads them anyway
        return $parameters; // @phpstan-ignore return.type
    }

    /**
     * @return Params
     */
    private static function parseDsn(string $url): array
    {
        return (new DsnParser([
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
        ]))->parse($url);
    }
}
