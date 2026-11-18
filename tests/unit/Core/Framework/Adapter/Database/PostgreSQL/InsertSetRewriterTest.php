<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\InsertSetRewriter;

/**
 * @internal
 */
#[CoversClass(InsertSetRewriter::class)]
class InsertSetRewriterTest extends TestCase
{
    public function testSetBecomesColumnsAndValues(): void
    {
        static::assertSame(
            'INSERT INTO "t" ("a", "b") VALUES (1, :b)',
            InsertSetRewriter::rewrite('INSERT INTO "t" SET "a" = 1, "b" = :b')
        );
    }

    public function testOnDuplicateKeyUpdateIsKept(): void
    {
        static::assertSame(
            'INSERT INTO "t" ("a") VALUES (1) ON DUPLICATE KEY UPDATE "a" = 2',
            InsertSetRewriter::rewrite('INSERT INTO "t" SET "a" = 1 ON DUPLICATE KEY UPDATE "a" = 2')
        );
    }

    public function testInsertWithValuesIsUnchanged(): void
    {
        $sql = 'INSERT INTO "t" ("a") VALUES (1)';

        static::assertSame($sql, InsertSetRewriter::rewrite($sql));
    }
}
