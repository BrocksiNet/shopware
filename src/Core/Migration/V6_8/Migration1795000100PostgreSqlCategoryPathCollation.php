<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * PostgreSQL only, a no-op on every other platform.
 *
 * `category.path` holds `|hex|hex|` and is matched with `LIKE '%|id|%'` by every navigation read.
 * On the nondeterministic collation `ci` PostgreSQL compares every row through ICU (about 25 ms for 500 categories, several times per storefront request),
 * a deterministic collation does the same match in under 0.5 ms and gives the same result for lowercase hex ids.
 * See docs/18-postgresql-performance.md of the research repository.
 *
 * @internal
 */
#[Package('framework')]
class Migration1795000100PostgreSqlCategoryPathCollation extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795000100;
    }

    public function update(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $collation = $connection->fetchOne(
            'SELECT collation_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = \'category\' AND column_name = \'path\''
        );

        if ($collation === 'ucs_basic') {
            return;
        }

        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);

        $native->exec('ALTER TABLE category ALTER COLUMN path TYPE text COLLATE ucs_basic');
    }
}
