<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * PostgreSQL only, a no-op on every other platform.
 *
 * The storefront search filters `product_search_keyword` with `lower(keyword) COLLATE ucs_basic = :term OR ... LIKE :pattern` in the
 * score query and again in every aggregation, listing and detail query of the search page (nine statements per request).
 * No btree can serve the leading wildcard of the pattern, so each of them scanned the whole table (8 ms for 34 000 rows, 7 of them per request).
 * A trigram index serves the equality and the pattern, see docs/25-postgresql-search-and-detail.md of the research repository.
 *
 * @internal
 */
#[Package('framework')]
class Migration1795000200PostgreSqlSearchKeywordTrigramIndex extends MigrationStep
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

        // pg_trgm is a trusted extension since PostgreSQL 13, without it the search stays correct but scans the table
        try {
            $native->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\PDOException) {
            return;
        }

        $native->exec('CREATE INDEX IF NOT EXISTS "product_search_keyword.idx.pg_keyword_trgm" ON product_search_keyword USING gin ((lower(keyword) COLLATE ucs_basic) gin_trgm_ops)');
    }
}
