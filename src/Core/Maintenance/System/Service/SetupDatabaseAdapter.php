<?php declare(strict_types=1);

namespace Shopware\Core\Maintenance\System\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Kernel;
use Shopware\Core\Maintenance\MaintenanceException;

/**
 * @internal
 *
 * @codeCoverageIgnore
 *
 * @see \Shopware\Tests\Integration\Core\Maintenance\System\Service\SetupDatabaseAdapterTest
 */
#[Package('framework')]
class SetupDatabaseAdapter
{
    public function dropDatabase(Connection $connection, string $database): void
    {
        if ($this->isPostgreSQL($connection)) {
            $this->executeNative($connection, 'DROP DATABASE IF EXISTS ' . $this->quotePostgreSQL($database) . ' WITH (FORCE)');

            return;
        }

        $connection->executeStatement('DROP DATABASE IF EXISTS `' . $database . '`');
    }

    public function createDatabase(Connection $connection, string $database): void
    {
        if ($this->isPostgreSQL($connection)) {
            $exists = $connection->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database]);
            if ($exists === false) {
                $this->executeNative($connection, 'CREATE DATABASE ' . $this->quotePostgreSQL($database));
            }

            return;
        }

        $connection->executeStatement('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_ci`');
    }

    public function initializeShopwareDb(Connection $connection, ?string $database = null): bool
    {
        if (!$hasShopwareTables = $this->hasShopwareTables($connection, $database)) {
            if ($this->isPostgreSQL($connection)) {
                $this->loadPostgreSQLCompatibilityLayer($connection);
            }

            $connection->executeStatement($this->getBaseSchema());
        }

        return !$hasShopwareTables;
    }

    public function hasShopwareTables(Connection $connection, ?string $database = null): bool
    {
        if ($database && !$this->isPostgreSQL($connection)) {
            $connection->executeStatement('USE `' . $database . '`');
        }

        $tables = $this->listTables($connection);

        return \in_array('migration', $tables, true);
    }

    public function getTableCount(Connection $connection, string $database): int
    {
        if (!$this->isPostgreSQL($connection)) {
            $connection->executeStatement('USE `' . $database . '`');
        }

        $tables = $this->listTables($connection);

        return \count($tables);
    }

    /**
     * @param list<string> $ignoredSchemas
     *
     * @return array<string>
     */
    public function getExistingDatabases(Connection $connection, array $ignoredSchemas): array
    {
        $query = $connection->createQueryBuilder()
            ->select('SCHEMA_NAME')
            ->from('information_schema.SCHEMATA');

        if ($ignoredSchemas !== []) {
            $query->andWhere('SCHEMA_NAME NOT IN (:ignoredSchemas)')
                ->setParameter('ignoredSchemas', $ignoredSchemas, ArrayParameterType::STRING);
        }

        return $query->executeQuery()->fetchFirstColumn();
    }

    /**
     * The PostgreSQL connection is bound to one database, the caller has to connect to the target database first
     */
    public function isPostgreSQL(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /**
     * Functions, casts and the collation the translated SQL relies on, the file is idempotent
     */
    private function loadPostgreSQLCompatibilityLayer(Connection $connection): void
    {
        $path = __DIR__ . '/../../../Framework/Adapter/Database/PostgreSQL/mysql-compat.sql';
        if (!is_file($path) || !is_readable($path)) {
            throw MaintenanceException::couldNotReadFile($path);
        }

        $this->executeNative($connection, (string) file_get_contents($path));
    }

    /**
     * Bypasses the translating middleware, the statement is PostgreSQL SQL
     */
    private function executeNative(Connection $connection, string $sql): void
    {
        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);
        $native->exec($sql);
    }

    private function quotePostgreSQL(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * @return list<string>
     */
    private function listTables(Connection $connection): array
    {
        if ($this->isPostgreSQL($connection)) {
            return array_map(strval(...), $connection->fetchFirstColumn('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'));
        }

        return array_map(strval(...), $connection->fetchFirstColumn('SHOW TABLES'));
    }

    private function getBaseSchema(): string
    {
        $kernelClass = new \ReflectionClass(Kernel::class);
        $directory = \dirname((string) $kernelClass->getFileName());

        $path = $directory . '/schema.sql';
        if (!is_file($path) || !is_readable($path)) {
            throw MaintenanceException::couldNotReadFile($path);
        }

        return (string) file_get_contents($path);
    }
}
