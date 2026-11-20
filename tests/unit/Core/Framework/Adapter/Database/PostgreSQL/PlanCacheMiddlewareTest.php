<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\PlanCacheMiddleware;

/**
 * @internal
 */
#[CoversClass(PlanCacheMiddleware::class)]
class PlanCacheMiddlewareTest extends TestCase
{
    public function testPositionalPlaceholders(): void
    {
        $rewritten = PlanCacheMiddleware::rewrite('SELECT a FROM t WHERE x = ? AND y IN (?, ?) ');

        static::assertSame(['sql' => 'SELECT a FROM t WHERE x = $1 AND y IN ($2, $3)', 'arguments' => ['?', '?', '?']], $rewritten);
    }

    public function testNamedPlaceholdersReuseTheNumberOfARepeatedName(): void
    {
        $rewritten = PlanCacheMiddleware::rewrite('WITH c AS (SELECT 1) SELECT x::text FROM t WHERE a = :id OR b = :id OR c = :other;');

        static::assertSame(['sql' => 'WITH c AS (SELECT 1) SELECT x::text FROM t WHERE a = $1 OR b = $1 OR c = $2', 'arguments' => [':id', ':other']], $rewritten);
    }

    public function testQuotesAndCommentsAreLeftAlone(): void
    {
        $sql = "SELECT '?' AS \"a?\", 'it''s :x' /* ? */ FROM t -- ? :y\n WHERE a = ? AND b ?? 'k'";
        $rewritten = PlanCacheMiddleware::rewrite($sql);

        static::assertNotNull($rewritten);
        static::assertSame("SELECT '?' AS \"a?\", 'it''s :x' /* ? */ FROM t -- ? :y\n WHERE a = \$1 AND b ? 'k'", $rewritten['sql']);
        static::assertSame(['?'], $rewritten['arguments']);
    }

    public function testBackslashInNormalStringIsPlain(): void
    {
        $rewritten = PlanCacheMiddleware::rewrite('SELECT 1 WHERE p LIKE \'%\\|x\\|%\' AND a = ?');

        static::assertNotNull($rewritten);
        static::assertSame('SELECT 1 WHERE p LIKE \'%\\|x\\|%\' AND a = $1', $rewritten['sql']);
    }

    public function testLeadingCommentOfTheDal(): void
    {
        $rewritten = PlanCacheMiddleware::rewrite("-- product::data::read\nSELECT a FROM t WHERE id IN (?, ?)");

        static::assertNotNull($rewritten);
        static::assertSame("-- product::data::read\nSELECT a FROM t WHERE id IN (\$1, \$2)", $rewritten['sql']);
        static::assertNull(PlanCacheMiddleware::rewrite("-- c\nUPDATE t SET a = ?"));
    }

    public function testStatementWithoutPlaceholders(): void
    {
        static::assertSame(['sql' => 'SELECT 1', 'arguments' => []], PlanCacheMiddleware::rewrite('SELECT 1'));
    }

    #[DataProvider('notCacheable')]
    public function testNotCacheable(string $sql): void
    {
        static::assertNull(PlanCacheMiddleware::rewrite($sql));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCacheable(): iterable
    {
        yield 'insert' => ['INSERT INTO t (a) VALUES (?)'];
        yield 'update' => ['UPDATE t SET a = ?'];
        yield 'multiple statements' => ['SELECT 1; SELECT 2'];
        yield 'dollar quoting' => ['SELECT $$a$$'];
        yield 'mixed placeholders' => ['SELECT a FROM t WHERE a = ? AND b = :b'];
        yield 'escape string' => ['SELECT E\'a\\\\b\' WHERE a = ?'];
        yield 'unterminated string' => ['SELECT \'a WHERE a = ?'];
    }
}
