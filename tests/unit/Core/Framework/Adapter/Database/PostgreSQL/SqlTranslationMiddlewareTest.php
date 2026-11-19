<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\DdlSavepointStatement;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\SqlTranslationMiddleware;

/**
 * @internal
 */
#[CoversClass(SqlTranslationMiddleware::class)]
#[CoversClass(DdlSavepointStatement::class)]
class SqlTranslationMiddlewareTest extends TestCase
{
    private const DDL = 'ALTER TABLE `a` ADD COLUMN `b` INT NULL';

    /**
     * @var list<string>
     */
    private array $log = [];

    protected function setUp(): void
    {
        $this->log = [];
        // the test bootstrap enables the per statement savepoints on PostgreSQL, these tests cover the production behaviour
        putenv('SHOPWARE_PG_STATEMENT_SAVEPOINTS');
    }

    public function testExecDdlInsideTransactionUsesSavepoint(): void
    {
        $connection = $this->connect();
        $connection->beginTransaction();
        $connection->exec(self::DDL);
        $connection->commit();

        static::assertSame([
            'begin',
            'exec SAVEPOINT sw_ddl',
            'exec ALTER TABLE "a" ADD COLUMN "b" integer',
            'exec RELEASE SAVEPOINT sw_ddl',
            'commit',
        ], $this->log);
    }

    public function testFailingExecDdlRollsBackToSavepointAndRethrows(): void
    {
        $connection = $this->connect(failOn: 'ALTER TABLE');
        $connection->beginTransaction();

        try {
            $connection->exec(self::DDL);
            static::fail('The exception of the failing DDL must be rethrown');
        } catch (\RuntimeException $e) {
            static::assertSame('failed: ALTER TABLE "a" ADD COLUMN "b" integer', $e->getMessage());
        }

        static::assertSame([
            'begin',
            'exec SAVEPOINT sw_ddl',
            'exec ALTER TABLE "a" ADD COLUMN "b" integer',
            'exec ROLLBACK TO SAVEPOINT sw_ddl',
            'exec RELEASE SAVEPOINT sw_ddl',
        ], $this->log);
    }

    public function testExecDdlOutsideTransactionUsesNoSavepoint(): void
    {
        $this->connect()->exec(self::DDL);

        static::assertSame(['exec ALTER TABLE "a" ADD COLUMN "b" integer'], $this->log);
    }

    public function testExecNonDdlInsideTransactionUsesNoSavepoint(): void
    {
        $connection = $this->connect();
        $connection->beginTransaction();
        $connection->exec('DELETE FROM `a`');

        static::assertSame(['begin', 'exec DELETE FROM "a"'], $this->log);
    }

    public function testTransactionStateIsResetByRollBack(): void
    {
        $connection = $this->connect();
        $connection->beginTransaction();
        $connection->rollBack();
        $connection->exec(self::DDL);

        static::assertSame(['begin', 'rollback', 'exec ALTER TABLE "a" ADD COLUMN "b" integer'], $this->log);
    }

    public function testPrepareDdlInsideTransactionUsesSavepointOnExecute(): void
    {
        $connection = $this->connect();
        $connection->beginTransaction();
        $statement = $connection->prepare(self::DDL);

        static::assertInstanceOf(DdlSavepointStatement::class, $statement);
        static::assertSame(['begin', 'prepare ALTER TABLE "a" ADD COLUMN "b" integer'], $this->log);

        $statement->execute();

        static::assertSame([
            'begin',
            'prepare ALTER TABLE "a" ADD COLUMN "b" integer',
            'exec SAVEPOINT sw_ddl',
            'execute ALTER TABLE "a" ADD COLUMN "b" integer',
            'exec RELEASE SAVEPOINT sw_ddl',
        ], $this->log);
    }

    public function testFailingPreparedDdlRollsBackToSavepointAndRethrows(): void
    {
        $connection = $this->connect(failOn: 'execute');
        $connection->beginTransaction();
        $statement = $connection->prepare(self::DDL);

        try {
            $statement->execute();
            static::fail('The exception of the failing DDL must be rethrown');
        } catch (\RuntimeException $e) {
            static::assertSame('failed: execute', $e->getMessage());
        }

        static::assertSame([
            'begin',
            'prepare ALTER TABLE "a" ADD COLUMN "b" integer',
            'exec SAVEPOINT sw_ddl',
            'execute ALTER TABLE "a" ADD COLUMN "b" integer',
            'exec ROLLBACK TO SAVEPOINT sw_ddl',
            'exec RELEASE SAVEPOINT sw_ddl',
        ], $this->log);
    }

    public function testPrepareDdlOutsideTransactionIsNotWrapped(): void
    {
        $statement = $this->connect()->prepare(self::DDL);
        $statement->execute();

        static::assertNotInstanceOf(DdlSavepointStatement::class, $statement);
        static::assertSame([
            'prepare ALTER TABLE "a" ADD COLUMN "b" integer',
            'execute ALTER TABLE "a" ADD COLUMN "b" integer',
        ], $this->log);
    }

    /**
     * @param string|null $failOn exec() and execute() throw when the statement starts with or equals this value
     */
    private function connect(?string $failOn = null): Connection
    {
        $log = &$this->log;

        $inner = $this->createStub(Connection::class);
        $inner->method('exec')->willReturnCallback(static function (string $sql) use (&$log, $failOn): int {
            $log[] = 'exec ' . $sql;
            if ($failOn !== null && str_starts_with($sql, $failOn)) {
                throw new \RuntimeException('failed: ' . $sql);
            }

            return 0;
        });
        $inner->method('prepare')->willReturnCallback(function (string $sql) use (&$log, $failOn): Statement {
            $log[] = 'prepare ' . $sql;
            $statement = $this->createStub(Statement::class);
            $statement->method('execute')->willReturnCallback(function () use (&$log, $sql, $failOn): Result {
                $log[] = 'execute ' . $sql;
                if ($failOn === 'execute') {
                    throw new \RuntimeException('failed: execute');
                }

                return $this->createStub(Result::class);
            });

            return $statement;
        });
        $inner->method('beginTransaction')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'begin';
        });
        $inner->method('commit')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'commit';
        });
        $inner->method('rollBack')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'rollback';
        });

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($inner);

        return (new SqlTranslationMiddleware())->wrap($driver)->connect([]);
    }
}
