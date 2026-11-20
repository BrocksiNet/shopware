<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * PostgreSQL only, a no-op on every other platform.
 *
 * The DAL string filters (`equals`, `equalsAny`, `contains`, `prefix`, `suffix`) compare `lower(column) COLLATE ucs_basic` on PostgreSQL (`SqlDialect::caseInsensitiveStringFilters()`),
 * which the existing btree indexes on the nondeterministic `ci` columns cannot serve.
 * These expression indexes serve `equals`, `equalsAny` and `prefix` lookups on the columns that shops look up by value.
 * Other string columns are filtered by a scan, as `contains` and `suffix` filters are on every column.
 * See docs/23-postgresql-api-reads.md of the research repository.
 *
 * @internal
 */
#[Package('framework')]
class Migration1795000001PostgreSqlStringFilterIndexes extends MigrationStep
{
    private const INDEXES = [
        ['product', 'product_number'],
        ['product', 'manufacturer_number'],
        ['product', 'ean'],
        ['customer', 'customer_number'],
        ['customer', 'email'],
        ['order', 'order_number'],
        ['promotion', 'code'],
        ['promotion_individual_code', 'code'],
        ['sales_channel_domain', 'url'],
        ['user', 'username'],
        ['user', 'email'],
        ['newsletter_recipient', 'email'],
        ['media', 'file_name'],
        ['tag', 'name'],
        ['custom_field', 'name'],
    ];

    public function getCreationTimestamp(): int
    {
        return 1795000001;
    }

    public function update(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);

        foreach (self::INDEXES as [$table, $column]) {
            $native->exec(\sprintf(
                'CREATE INDEX IF NOT EXISTS "%1$s.idx.pg_%2$s_lower" ON "%1$s" ((lower("%2$s") COLLATE ucs_basic))',
                $table,
                $column
            ));
        }
    }
}
