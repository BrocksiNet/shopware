<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\BooleanAssignmentRewriter;

/**
 * @internal
 */
#[CoversClass(BooleanAssignmentRewriter::class)]
class BooleanAssignmentRewriterTest extends TestCase
{
    #[DataProvider('statements')]
    public function testRewrite(string $sql, string $expected): void
    {
        $booleans = static fn (string $table): array => $table === 't' ? ['flag', 'other'] : [];

        static::assertSame($expected, BooleanAssignmentRewriter::rewrite($sql, $booleans));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statements(): iterable
    {
        yield 'insert literals and placeholders' => [
            'INSERT INTO "t" ("id", "flag", "other") VALUES (?, 1, ?)',
            'INSERT INTO "t" ("id", "flag", "other") VALUES (?, TRUE, mysql_compat_to_bool(?))',
        ];
        yield 'insert with several rows' => [
            'INSERT INTO "t" ("id", "flag") VALUES (?, 0), (?, ?)',
            'INSERT INTO "t" ("id", "flag") VALUES (?, FALSE), (?, mysql_compat_to_bool(?))',
        ];
        yield 'insert keeps boolean literals, NULL and DEFAULT' => [
            'INSERT INTO "t" ("flag", "other") VALUES (true, NULL), (DEFAULT, FALSE)',
            'INSERT INTO "t" ("flag", "other") VALUES (true, NULL), (DEFAULT, FALSE)',
        ];
        yield 'insert with function call values' => [
            'INSERT INTO "t" ("id", "flag") VALUES (unhex(?), IF(?, 1, 0))',
            'INSERT INTO "t" ("id", "flag") VALUES (unhex(?), mysql_compat_to_bool(IF(?, 1, 0)))',
        ];
        yield 'upsert assignments' => [
            '/* conflict-target: id */ INSERT INTO "t" ("id", "flag") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "flag" = 1, "id" = EXCLUDED."id"',
            '/* conflict-target: id */ INSERT INTO "t" ("id", "flag") VALUES (?, mysql_compat_to_bool(?)) ON CONFLICT ("id") DO UPDATE SET "flag" = TRUE, "id" = EXCLUDED."id"',
        ];
        yield 'upsert with a trailing statement terminator' => [
            'INSERT INTO "t" ("id", "flag") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "flag" = EXCLUDED."flag" ;',
            'INSERT INTO "t" ("id", "flag") VALUES (?, mysql_compat_to_bool(?)) ON CONFLICT ("id") DO UPDATE SET "flag" = mysql_compat_to_bool(EXCLUDED."flag") ;',
        ];

        yield 'upsert do nothing' => [
            'INSERT INTO "t" ("flag") VALUES (0) ON CONFLICT DO NOTHING',
            'INSERT INTO "t" ("flag") VALUES (FALSE) ON CONFLICT DO NOTHING',
        ];
        yield 'update' => [
            'UPDATE "t" SET "flag" = 0, "name" = ?, "other" = :o WHERE "id" = 1',
            'UPDATE "t" SET "flag" = FALSE, "name" = ?, "other" = mysql_compat_to_bool(:o) WHERE "id" = 1',
        ];
        yield 'update with alias and from' => [
            'UPDATE "t" AS x SET "flag" = (SELECT 1 FROM "o" WHERE "o"."id" = x."id") FROM "o" WHERE x."id" = "o"."id"',
            'UPDATE "t" AS x SET "flag" = mysql_compat_to_bool((SELECT 1 FROM "o" WHERE "o"."id" = x."id")) FROM "o" WHERE x."id" = "o"."id"',
        ];
        yield 'table without boolean columns' => [
            'INSERT INTO "o" ("flag") VALUES (1)',
            'INSERT INTO "o" ("flag") VALUES (1)',
        ];
        yield 'insert select is left to the InsertSelectRewriter' => [
            'INSERT INTO "t" ("flag") SELECT 1',
            'INSERT INTO "t" ("flag") SELECT 1',
        ];
        yield 'string containing a comma and a parenthesis' => [
            'INSERT INTO "t" ("name", "flag") VALUES (\'a, (b\', 1)',
            'INSERT INTO "t" ("name", "flag") VALUES (\'a, (b\', TRUE)',
        ];
        yield 'string with an escaped quote, a comma and a parenthesis' => [
            'INSERT INTO "t" ("name", "flag") VALUES (E\'a\\\'b, (c\', 1)',
            'INSERT INTO "t" ("name", "flag") VALUES (E\'a\\\'b, (c\', TRUE)',
        ];
        yield 'comment with a comma and a parenthesis' => [
            'INSERT INTO "t" ("name", "flag") VALUES (? /* x, ( */, 1)',
            'INSERT INTO "t" ("name", "flag") VALUES (? /* x, ( */, TRUE)',
        ];
        yield 'column count mismatch is unchanged' => [
            'INSERT INTO "t" ("id", "flag") VALUES (?)',
            'INSERT INTO "t" ("id", "flag") VALUES (?)',
        ];
    }
}
