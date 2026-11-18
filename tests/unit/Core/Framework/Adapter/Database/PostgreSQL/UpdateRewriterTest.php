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

        yield 'left join is unchanged' => [
            'UPDATE a LEFT JOIN b ON c SET a.x = b.y WHERE w',
            'UPDATE a LEFT JOIN b ON c SET a.x = b.y WHERE w',
        ];

        yield 'plain update is unchanged' => [
            'UPDATE a SET x = 1 WHERE w',
            'UPDATE a SET x = 1 WHERE w',
        ];
    }
}
