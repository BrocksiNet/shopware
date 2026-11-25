<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\ShowRewriter;

/**
 * @internal
 */
#[CoversClass(ShowRewriter::class)]
class ShowRewriterTest extends TestCase
{
    public function testMatches(): void
    {
        static::assertTrue(ShowRewriter::matches('SHOW INDEXES FROM t'));
        static::assertTrue(ShowRewriter::matches('SHOW FULL COLUMNS FROM t'));
        static::assertTrue(ShowRewriter::matches('SHOW TABLES'));
        static::assertFalse(ShowRewriter::matches('SELECT 1'));
    }

    public function testShowColumnsExposesMySqlColumnNamesAndTypes(): void
    {
        $sql = ShowRewriter::rewrite('SHOW COLUMNS FROM "t" WHERE Field = \'a\'');

        static::assertStringContainsString('AS "Field"', $sql);
        static::assertStringContainsString('AS "Type"', $sql);
        static::assertStringContainsString('WHEN \'uuid\' THEN \'binary(16)\'', $sql);
        static::assertStringContainsString('c.table_name = \'t\'', $sql);
        static::assertStringEndsWith('WHERE "Field" = \'a\'', $sql);
    }

    public function testShowColumnsReportsBooleanDefaultsLikeMySql(): void
    {
        $sql = ShowRewriter::rewrite('SHOW COLUMNS FROM "t"');

        static::assertStringContainsString('WHEN \'true\' THEN \'1\' WHEN \'false\' THEN \'0\'', $sql);
    }

    public function testShowColumnsReportsIdentityColumnsAsAutoIncrement(): void
    {
        $sql = ShowRewriter::rewrite('SHOW COLUMNS FROM "customer"');

        static::assertStringContainsString('WHEN c.is_identity = \'YES\' OR c.column_default LIKE \'nextval(%\' THEN \'auto_increment\'', $sql);
    }

    public function testShowIndexesStripsTablePrefixAndMapsPrimary(): void
    {
        $sql = ShowRewriter::rewrite('SHOW INDEXES FROM "t" WHERE "key_name" = \'idx\'');

        static::assertStringContainsString('AS "Key_name"', $sql);
        static::assertStringContainsString('\'PRIMARY\'', $sql);
        static::assertStringContainsString('t.relname = \'t\'', $sql);
        static::assertStringEndsWith('WHERE "Key_name" = \'idx\'', $sql);
    }

    public function testShowTablesWithLike(): void
    {
        static::assertSame(
            'SELECT tablename::text AS "Tables_in_db" FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE \'x\'',
            ShowRewriter::rewrite('SHOW TABLES LIKE \'x\'')
        );
    }
}
