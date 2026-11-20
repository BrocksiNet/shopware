<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * PostgreSQL only, a no-op on every other platform.
 *
 * The DAL renders `ORDER BY ... ASC` with an explicit `NULLS FIRST` on PostgreSQL to match the MySQL NULL order.
 * The default btree index on `product.product_number` is `NULLS LAST` and cannot serve that order, so the planner falls back to a scan plus a top-N sort.
 * The gap to the indexed plan grows with the row count (about 6 ms at 50,000 and 28 ms at 500,000 products).
 * See docs/21-dal-failures-postgresql.md "Wave 7" of the research repository.
 *
 * @internal
 */
#[Package('framework')]
class Migration1795000200PostgreSqlProductNumberNullsFirstIndex extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795000200;
    }

    public function update(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);

        $native->exec('CREATE INDEX IF NOT EXISTS "product.idx.pg_product_number_nulls_first" ON "product" ("product_number" NULLS FIRST, "version_id")');
    }
}
