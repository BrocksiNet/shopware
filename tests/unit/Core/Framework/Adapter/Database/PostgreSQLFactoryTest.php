<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQLFactory;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\EnvTestBehaviour;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(PostgreSQLFactory::class)]
class PostgreSQLFactoryTest extends TestCase
{
    use EnvTestBehaviour;

    public function testWithoutReplicaUrlTheConnectionIsPlain(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware',
            'DATABASE_REPLICA_0_URL' => null,
        ]);

        $connection = PostgreSQLFactory::create();

        static::assertNotInstanceOf(PrimaryReadReplicaConnection::class, $connection);
        static::assertSame('primary', $connection->getParams()['host'] ?? null);
    }

    public function testReplicaUrlsCreateAPrimaryReadReplicaConnection(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware',
            'DATABASE_REPLICA_0_URL' => 'pgsql://ro:ro_pass@replica0:5433/shopware',
            'DATABASE_REPLICA_1_URL' => 'pgsql://ro:ro_pass@replica1:5434/shopware',
            'DATABASE_PERSISTENT_CONNECTION' => '1',
        ]);

        $connection = PostgreSQLFactory::create();
        $params = $connection->getParams();

        static::assertInstanceOf(PrimaryReadReplicaConnection::class, $connection);
        static::assertFalse($connection->isConnected());
        static::assertTrue($params['keepReplica'] ?? null);
        static::assertSame('primary', $params['primary']['host'] ?? null);
        static::assertArrayNotHasKey('replica', $params['primary'] ?? []);

        $replicas = $params['replica'] ?? [];
        static::assertCount(2, $replicas);
        static::assertSame('replica0', $replicas[0]['host'] ?? null);
        static::assertSame(5433, $replicas[0]['port'] ?? null);
        static::assertSame('ro', $replicas[0]['user'] ?? null);
        static::assertSame('pdo_pgsql', $replicas[0]['driver'] ?? null);
        static::assertSame('replica1', $replicas[1]['host'] ?? null);
        static::assertTrue($replicas[0]['driverOptions'][\PDO::ATTR_PERSISTENT] ?? null);
        static::assertTrue($replicas[0]['driverOptions'][\PDO::ATTR_STRINGIFY_FETCHES] ?? null);
    }

    public function testKeepReplicaCanBeDisabledViaDsn(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware?keepReplica=0',
            'DATABASE_REPLICA_0_URL' => 'pgsql://ro:ro_pass@replica0:5432/shopware',
        ]);

        static::assertFalse(PostgreSQLFactory::create()->getParams()['keepReplica'] ?? null);
    }

    public function testMigrationConnectionUsesThePrimaryOnly(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware',
            'DATABASE_REPLICA_0_URL' => 'pgsql://ro:ro_pass@replica0:5432/shopware',
        ]);

        $migration = PostgreSQLFactory::createMigrationConnection(PostgreSQLFactory::create());
        $params = $migration->getParams();

        static::assertNotInstanceOf(PrimaryReadReplicaConnection::class, $migration);
        static::assertSame('primary', $params['host'] ?? null);
        static::assertArrayNotHasKey('replica', $params);
    }

    public function testSslVariablesMapToLibpqParameters(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware',
            'DATABASE_REPLICA_0_URL' => 'pgsql://ro:ro_pass@replica0:5432/shopware',
            'DATABASE_SSL_CA' => '/ca.pem',
            'DATABASE_SSL_CERT' => '/cert.pem',
            'DATABASE_SSL_KEY' => '/key.pem',
            'DATABASE_SSL_DONT_VERIFY_SERVER_CERT' => null,
        ]);

        $params = PostgreSQLFactory::create()->getParams();

        foreach ([$params['primary'] ?? [], $params['replica'][0] ?? []] as $connection) {
            static::assertSame('verify-full', $connection['sslmode'] ?? null);
            static::assertSame('/ca.pem', $connection['sslrootcert'] ?? null);
            static::assertSame('/cert.pem', $connection['sslcert'] ?? null);
            static::assertSame('/key.pem', $connection['sslkey'] ?? null);
        }
    }

    public function testDontVerifyServerCertMeansRequire(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware',
            'DATABASE_REPLICA_0_URL' => null,
            'DATABASE_SSL_CA' => '/ca.pem',
            'DATABASE_SSL_DONT_VERIFY_SERVER_CERT' => '1',
        ]);

        static::assertSame('require', PostgreSQLFactory::create()->getParams()['sslmode'] ?? null);
    }

    public function testSslModeInTheUrlWins(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware?sslmode=verify-ca',
            'DATABASE_REPLICA_0_URL' => null,
            'DATABASE_SSL_CA' => '/ca.pem',
            'DATABASE_SSL_DONT_VERIFY_SERVER_CERT' => null,
        ]);

        static::assertSame('verify-ca', PostgreSQLFactory::create()->getParams()['sslmode'] ?? null);
    }

    public function testNoSslVariablesAddNoSslParameters(): void
    {
        $this->setEnvVars([
            'DATABASE_URL' => 'pgsql://user:pass@primary:5432/shopware',
            'DATABASE_REPLICA_0_URL' => null,
            'DATABASE_SSL_CA' => null,
            'DATABASE_SSL_CERT' => null,
            'DATABASE_SSL_KEY' => null,
            'DATABASE_SSL_DONT_VERIFY_SERVER_CERT' => null,
        ]);

        static::assertArrayNotHasKey('sslmode', PostgreSQLFactory::create()->getParams());
    }
}
