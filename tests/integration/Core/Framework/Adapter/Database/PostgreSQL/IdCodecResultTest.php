<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\IdCodecResult;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * @internal
 */
class IdCodecResultTest extends TestCase
{
    use KernelTestBehaviour;

    private const UUID_TEXT = '0190f3b0-1111-7000-8000-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            static::markTestSkipped('The id codec only runs on PostgreSQL');
        }
        IdCodecResult::resetCache();
    }

    public function testUuidLookingTextStaysTextOnEveryFetchMethod(): void
    {
        $sql = 'SELECT CAST(? AS text) AS t';

        static::assertSame(self::UUID_TEXT, $this->connection->fetchOne($sql, [self::UUID_TEXT]));
        static::assertSame(['t' => self::UUID_TEXT], $this->connection->fetchAssociative($sql, [self::UUID_TEXT]));
        static::assertSame([self::UUID_TEXT], $this->connection->fetchNumeric($sql, [self::UUID_TEXT]));
        static::assertSame([['t' => self::UUID_TEXT]], $this->connection->fetchAllAssociative($sql, [self::UUID_TEXT]));
        static::assertSame([[self::UUID_TEXT]], $this->connection->fetchAllNumeric($sql, [self::UUID_TEXT]));
        static::assertSame([self::UUID_TEXT], $this->connection->fetchFirstColumn($sql, [self::UUID_TEXT]));
    }

    public function testUuidLookingTextStaysTextWithoutParameters(): void
    {
        static::assertSame(self::UUID_TEXT, $this->connection->fetchOne('SELECT CAST(\'' . self::UUID_TEXT . '\' AS text)'));
    }

    public function testUuidColumnBecomesBytesNextToItsTextForm(): void
    {
        $row = $this->connection->fetchAssociative('SELECT id, CAST(id AS text) AS id_text FROM language ORDER BY id LIMIT 1');
        static::assertIsArray($row);

        static::assertSame(16, \strlen($row['id']));
        static::assertSame(36, \strlen($row['id_text']));
        static::assertSame(bin2hex($row['id']), str_replace('-', '', $row['id_text']));

        // cached decision for the same statement
        $again = $this->connection->fetchAssociative('SELECT id, CAST(id AS text) AS id_text FROM language ORDER BY id LIMIT 1');
        static::assertSame($row, $again);
    }

    public function testDuplicateColumnNameFollowsTheLastColumnLikePdo(): void
    {
        $row = $this->connection->fetchAssociative('SELECT id AS x, CAST(id AS text) AS x FROM language ORDER BY id LIMIT 1');
        static::assertIsArray($row);
        static::assertSame(36, \strlen($row['x']));

        $row = $this->connection->fetchAssociative('SELECT CAST(id AS text) AS x, id AS x FROM language ORDER BY id LIMIT 1');
        static::assertIsArray($row);
        static::assertSame(16, \strlen($row['x']));
    }

    public function testUuidCastResultIsDecoded(): void
    {
        $value = $this->connection->fetchOne('SELECT CAST(? AS uuid)', [self::UUID_TEXT]);

        static::assertSame(hex2bin(str_replace('-', '', self::UUID_TEXT)), $value);
    }
}
