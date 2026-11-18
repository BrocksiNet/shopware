<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\UpsertRewriter;

/**
 * @internal
 */
#[CoversClass(UpsertRewriter::class)]
class UpsertRewriterTest extends TestCase
{
    /**
     * @param list<string> $primaryKey
     * @param list<string> $contains
     * @param list<string> $notContains
     */
    #[DataProvider('rewriteProvider')]
    public function testRewrite(string $sql, array $primaryKey, array $contains, array $notContains = []): void
    {
        $result = UpsertRewriter::rewrite($sql, static fn (string $table): array => $primaryKey);

        foreach ($contains as $expected) {
            static::assertStringContainsString($expected, $result);
        }
        foreach ($notContains as $unexpected) {
            static::assertStringNotContainsString($unexpected, $result);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, 3?: list<string>}>
     */
    public static function rewriteProvider(): iterable
    {
        yield 'insert ignore' => [
            'INSERT IGNORE INTO "t" ("a", "b") VALUES (1, 2)',
            ['a'],
            ['INSERT INTO "t"', 'ON CONFLICT DO NOTHING'],
            ['IGNORE'],
        ];

        yield 'values function' => [
            'INSERT INTO "t" ("pk", "b") VALUES (1, 2) ON DUPLICATE KEY UPDATE "b" = VALUES("b")',
            ['pk'],
            ['ON CONFLICT ("pk") DO UPDATE SET "b" = EXCLUDED."b"'],
            ['DUPLICATE', 'VALUES("b")'],
        ];

        yield 'qualifies quoted columns on the right side' => [
            'INSERT INTO "increment" ("pool", "cluster", "key", "count", "updated_at") VALUES ($1, $2, $3, $4, $5) ON DUPLICATE KEY UPDATE "count" = "count" + 1, "updated_at" = $5',
            ['pool', 'cluster', 'key'],
            ['"count" = "increment"."count" + 1, "updated_at" = $5'],
        ];

        yield 'bare word that is an inserted column is qualified' => [
            'INSERT INTO "t" ("pk", "cnt") VALUES (1, 2) ON DUPLICATE KEY UPDATE "cnt" = cnt + 1',
            ['pk'],
            ['"cnt" = "t".cnt + 1'],
        ];

        yield 'bare word that is no inserted column is untouched' => [
            'INSERT INTO "t" ("pk", "cnt") VALUES (1, 2) ON DUPLICATE KEY UPDATE "cnt" = other + 1',
            ['pk'],
            ['"cnt" = other + 1'],
            ['"t".other'],
        ];

        yield 'string literal, named parameter, function and qualified name are untouched' => [
            'INSERT INTO "t" ("pk", "a", "b") VALUES (1, 2, 3) ON DUPLICATE KEY UPDATE "a" = \'"a"\', "b" = :a, "pk" = COALESCE("t"."a", 1)',
            ['pk'],
            ['"a" = \'"a"\'', '"b" = :a', 'COALESCE("t"."a", 1)'],
            ['"t"."t"', '"t".:a'],
        ];

        yield 'replace into' => [
            'REPLACE INTO "t" ("pk", "a") VALUES (1, 2)',
            ['pk'],
            ['INSERT INTO "t"', 'ON CONFLICT ("pk") DO UPDATE SET "a" = EXCLUDED."a"'],
            ['REPLACE'],
        ];

        yield 'replace into with only primary key columns' => [
            'REPLACE INTO "t" ("pk") VALUES (1)',
            ['pk'],
            ['ON CONFLICT ("pk") DO NOTHING'],
        ];
    }

    public function testStatementWithoutUpsertIsUnchanged(): void
    {
        $sql = 'INSERT INTO "t" ("a") VALUES (1)';

        static::assertSame($sql, UpsertRewriter::rewrite($sql, static fn (): array => ['a']));
    }
}
