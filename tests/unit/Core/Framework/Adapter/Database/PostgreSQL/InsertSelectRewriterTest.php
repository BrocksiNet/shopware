<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\InsertSelectRewriter;

/**
 * @internal
 */
#[CoversClass(InsertSelectRewriter::class)]
class InsertSelectRewriterTest extends TestCase
{
    public function testPlaceholderIsCastToTheColumnType(): void
    {
        $sql = InsertSelectRewriter::rewrite(
            'INSERT INTO "t" ("a", "b") SELECT :x, "c" FROM "o"',
            static fn (string $table, string $column): ?string => $column === 'a' ? 'integer' : null
        );

        static::assertSame('INSERT INTO "t" ("a", "b") SELECT CAST(:x AS integer), "c" FROM "o"', $sql);
    }

    public function testStatementWithoutSelectIsUnchanged(): void
    {
        $sql = 'INSERT INTO "t" ("a") VALUES (:x)';

        static::assertSame($sql, InsertSelectRewriter::rewrite($sql, static fn (): string => 'integer'));
    }

    public function testUnknownColumnTypeLeavesThePlaceholder(): void
    {
        $sql = 'INSERT INTO "t" ("a") SELECT :x';

        static::assertSame($sql, InsertSelectRewriter::rewrite($sql, static fn (): ?string => null));
    }
}
