<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * PostgreSQL only, a no-op on every other platform.
 *
 * The string columns are `COLLATE "ci"` (nondeterministic), which neither a btree index nor `LIKE` can use efficiently.
 * `SqlDialect::caseInsensitive()` queries `lower(column) COLLATE ucs_basic` instead, these indexes serve that expression.
 * See docs/16-postgresql-hot-spots.md of the research repository.
 *
 * @internal
 */
#[Package('framework')]
class Migration1795000000PostgreSqlCaseInsensitiveSearchIndexes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795000000;
    }

    public function update(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);

        $native->exec('CREATE INDEX IF NOT EXISTS "seo_url.idx.pg_path_info_lower" ON seo_url (language_id, (lower(path_info) COLLATE ucs_basic))');

        // trigram indexes serve the LIKE patterns of the search keyword lookup, they need the pg_trgm extension (a trusted extension since PostgreSQL 13)
        try {
            $native->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\PDOException) {
            return;
        }

        $native->exec('CREATE INDEX IF NOT EXISTS "product_keyword_dictionary.idx.pg_keyword_trgm" ON product_keyword_dictionary USING gin ((lower(keyword) COLLATE ucs_basic) gin_trgm_ops)');
        $native->exec('CREATE INDEX IF NOT EXISTS "product_keyword_dictionary.idx.pg_reversed_trgm" ON product_keyword_dictionary USING gin ((lower(reversed) COLLATE ucs_basic) gin_trgm_ops)');
    }
}
