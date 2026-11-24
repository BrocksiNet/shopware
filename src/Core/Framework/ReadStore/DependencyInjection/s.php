<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\JsonDialect;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlConnectionFactory;
use Shopware\Core\Framework\ReadStore\Adapter\Sql\SqlStorage;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Wave 9 handoff S: SQL JSON adapters. Each engine has its own plain DBAL connection (no Shopware middlewares, the SQL is native
// to the engine) and reads its URL from an environment variable, a connection is only opened by the first query.
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()
        ->set('env(READ_STORE_SQL_MYSQL_URL)', 'mysql://root:root@mysql:3306/read_store')
        ->set('env(READ_STORE_SQL_MARIADB_URL)', 'mysql://root:root@database:3306/read_store')
        ->set('env(READ_STORE_SQL_PGSQL_URL)', 'pgsql://shopware:shopware@postgres:5432/read_store')
        ->set('env(READ_STORE_SQL_SQLITE_URL)', 'sqlite:///%kernel.project_dir%/var/read-store/read-store.sqlite');

    $services = $containerConfigurator->services();

    foreach (['mysql' => 'MYSQL', 'mariadb' => 'MARIADB', 'pgsql' => 'PGSQL', 'sqlite' => 'SQLITE'] as $dialect => $variable) {
        $services->set('read_store.sql_connection.' . $dialect, Connection::class)
            ->factory([SqlConnectionFactory::class, 'create'])
            ->args([env('READ_STORE_SQL_' . $variable . '_URL')]);

        $services->set('read_store.storage.sql-' . $dialect, SqlStorage::class)
            ->public()
            ->args([
                service('read_store.sql_dialect.' . $dialect),
                service('read_store.sql_connection.' . $dialect),
                service(FieldResolver::class),
            ])
            ->tag('read_store.storage', ['key' => 'sql-' . $dialect]);

        $services->set('read_store.sql_dialect.' . $dialect, JsonDialect::class)
            ->factory([SqlConnectionFactory::class, 'dialect'])
            ->args([$dialect]);
    }
};
