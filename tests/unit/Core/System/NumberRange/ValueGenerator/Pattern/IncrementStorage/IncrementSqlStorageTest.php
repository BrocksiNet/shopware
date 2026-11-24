<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\System\NumberRange\ValueGenerator\Pattern\IncrementStorage;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\Pattern\IncrementStorage\IncrementSqlStorage;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(IncrementSqlStorage::class)]
class IncrementSqlStorageTest extends TestCase
{
    public function testReturningWriteSwitchesAReadReplicaConnectionToThePrimary(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $calls = [];
        $connection->expects($this->once())->method('ensureConnectedToPrimary')
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'primary';

                return true;
            });
        $connection->expects($this->once())->method('fetchOne')
            ->willReturnCallback(static function (string $sql) use (&$calls): int {
                $calls[] = str_contains($sql, 'RETURNING') ? 'returning' : 'other';

                return 10001;
            });

        $storage = new IncrementSqlStorage($connection, new MockClock());

        static::assertSame(10001, $storage->reserve(['id' => Uuid::randomHex(), 'start' => 10000, 'pattern' => '{n}']));
        static::assertSame(['primary', 'returning'], $calls);
    }
}
