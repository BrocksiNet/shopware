<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\SqlTranslationMiddleware;

/**
 * @internal
 */
#[CoversClass(SqlTranslationMiddleware::class)]
class SqlTranslationMiddlewareStatementSavepointsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $log = [];

    protected function setUp(): void
    {
        $this->log = [];
        putenv('SHOPWARE_PG_STATEMENT_SAVEPOINTS=1');
    }

    protected function tearDown(): void
    {
        putenv('SHOPWARE_PG_STATEMENT_SAVEPOINTS');
    }

    public function testStatementsInsideATransactionRunInASavepoint(): void
    {
        $connection = $this->connect();
        $connection->beginTransaction();
        $connection->prepare('DELETE FROM `a` WHERE `b` = 1')->execute();
        $connection->exec('DELETE FROM `a`');
        $connection->query('SELECT 1');

        static::assertSame([
            'begin',
            'prepare DELETE FROM "a" WHERE "b" = 1',
            'exec SAVEPOINT sw_ddl',
            'execute DELETE FROM "a" WHERE "b" = 1',
            'exec RELEASE SAVEPOINT sw_ddl',
            'exec SAVEPOINT sw_ddl',
            'exec DELETE FROM "a"',
            'exec RELEASE SAVEPOINT sw_ddl',
            'exec SAVEPOINT sw_ddl',
            'query SELECT 1',
            'exec RELEASE SAVEPOINT sw_ddl',
        ], $this->log);
    }

    public function testDoctrineSavepointsStayOutsideOfTheStatementSavepoint(): void
    {
        $connection = $this->connect();
        $connection->beginTransaction();
        $connection->exec('SAVEPOINT DOCTRINE_2');
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');

        static::assertSame(['begin', 'exec SAVEPOINT DOCTRINE_2', 'exec RELEASE SAVEPOINT DOCTRINE_2'], $this->log);
    }

    public function testStatementsOutsideATransactionAreNotWrapped(): void
    {
        $connection = $this->connect();
        $connection->exec('DELETE FROM `a`');

        static::assertSame(['exec DELETE FROM "a"'], $this->log);
    }

    public function testSessionSwitchSentThroughQueryBecomesNoop(): void
    {
        $connection = $this->connect();
        $connection->query('SET UNIQUE_CHECKS=0;');

        static::assertSame(['query SELECT 1'], $this->log);
    }

    public function testForeignKeyChecksSwitchTogglesTheReplicationRole(): void
    {
        $connection = $this->connect();
        $connection->query('SET FOREIGN_KEY_CHECKS=0;');
        $connection->query('SET FOREIGN_KEY_CHECKS=1;');

        static::assertCount(2, $this->log);
        static::assertStringContainsString('\'session_replication_role\', \'replica\'', $this->log[0]);
        static::assertStringContainsString('\'session_replication_role\', \'origin\'', $this->log[1]);
    }

    private function connect(): Connection
    {
        $log = &$this->log;

        $inner = static::createStub(Connection::class);
        $inner->method('exec')->willReturnCallback(static function (string $sql) use (&$log): int {
            $log[] = 'exec ' . $sql;

            return 0;
        });
        $inner->method('query')->willReturnCallback(function (string $sql) use (&$log): Result {
            $log[] = 'query ' . $sql;

            return $this->createStub(Result::class);
        });
        $inner->method('prepare')->willReturnCallback(function (string $sql) use (&$log): Statement {
            $log[] = 'prepare ' . $sql;
            $statement = $this->createStub(Statement::class);
            $statement->method('execute')->willReturnCallback(function () use (&$log, $sql): Result {
                $log[] = 'execute ' . $sql;

                return $this->createStub(Result::class);
            });

            return $statement;
        });
        $inner->method('beginTransaction')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'begin';
        });

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($inner);

        return (new SqlTranslationMiddleware())->wrap($driver)->connect([]);
    }
}
