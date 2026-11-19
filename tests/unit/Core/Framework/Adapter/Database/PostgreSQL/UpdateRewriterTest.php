<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\UpdateRewriter;

/**
 * @internal
 */
#[CoversClass(UpdateRewriter::class)]
class UpdateRewriterTest extends TestCase
{
    #[DataProvider('rewriteProvider')]
    public function testRewrite(string $sql, string $expected): void
    {
        static::assertSame($expected, UpdateRewriter::rewrite($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rewriteProvider(): iterable
    {
        yield 'multi table' => [
            'UPDATE a, b SET a.x = b.y WHERE w',
            'UPDATE a SET x = b.y FROM b WHERE w',
        ];

        yield 'inner join' => [
            'UPDATE a INNER JOIN b ON c SET a.x = b.y WHERE w',
            'UPDATE a SET x = b.y FROM b WHERE (c) AND (w)',
        ];

        yield 'left join keeps unmatched rows through a ctid subquery' => [
            'UPDATE a LEFT JOIN b ON c SET a.x = b.y WHERE w',
            'UPDATE a SET x = b.y FROM (SELECT a.ctid AS "__ctid", b.* FROM a LEFT JOIN b ON c) AS b WHERE a.ctid = b."__ctid" AND (w)',
        ];

        yield 'order by and limit become a ctid subquery' => [
            'UPDATE "t" SET "a" = 1 WHERE "b" = 2 ORDER BY "c" LIMIT 5',
            'UPDATE "t" SET "a" = 1 WHERE ctid IN (SELECT ctid FROM "t" WHERE "b" = 2 ORDER BY "c" LIMIT 5)',
        ];

        yield 'order by without limit is dropped' => [
            'UPDATE "t" SET "a" = 1 ORDER BY "c"',
            'UPDATE "t" SET "a" = 1 ',
        ];

        yield 'right join becomes inner join' => [
            'UPDATE a RIGHT JOIN b ON c SET a.x = b.y WHERE w',
            'UPDATE a SET x = b.y FROM b WHERE (c) AND (w)',
        ];

        yield 'escaped quote and comma inside a string do not end the assignment' => [
            'UPDATE a, b SET a.x = \'it\\\'s, ok\', a.y = b.y WHERE w',
            'UPDATE a SET x = \'it\\\'s, ok\', y = b.y FROM b WHERE w',
        ];

        yield 'comment with parenthesis and keyword does not hide the where clause' => [
            'UPDATE a INNER JOIN b ON c SET a.x = b.y /* ( WHERE */ WHERE w',
            'UPDATE a SET x = b.y /* ( WHERE */ FROM b WHERE (c) AND (w)',
        ];

        yield 'backtick identifier with a keyword is not a keyword' => [
            'UPDATE a INNER JOIN b ON c SET a.x = `WHERE`.y WHERE w',
            'UPDATE a SET x = `WHERE`.y FROM b WHERE (c) AND (w)',
        ];

        yield 'plain update is unchanged' => [
            'UPDATE a SET x = 1 WHERE w',
            'UPDATE a SET x = 1 WHERE w',
        ];
    }
}
