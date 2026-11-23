<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\OrmSpike\DalV2\Write;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\OrmSpike\DalV2\Write\SwitchableWriteGateway;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class WriteGatewayV2BatchOrderTest extends TestCase
{
    use IntegrationTestBehaviour;

    protected function tearDown(): void
    {
        SwitchableWriteGateway::$mode = null;
    }

    public function testUpdatesOfTheSameRowKeepTheirOrderWhenBatched(): void
    {
        $ids = new IdsCollection();
        static::getContainer()->get('product.repository')->create([
            (new ProductBuilder($ids, 'p1'))->price(10)->stock(1)->active(false)->build(),
        ], Context::createDefaultContext());

        SwitchableWriteGateway::$mode = ['key' => false, 'upd' => true];
        static::getContainer()->get('product.repository')->update([
            ['id' => $ids->get('p1'), 'stock' => 1],
            ['id' => $ids->get('p1'), 'stock' => 2, 'active' => true],
            ['id' => $ids->get('p1'), 'stock' => 3],
        ], Context::createDefaultContext());

        $row = static::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT stock, active FROM product WHERE id = ?',
            [Uuid::fromHexToBytes($ids->get('p1'))]
        );

        static::assertIsArray($row);
        static::assertSame(3, (int) $row['stock']);
        static::assertTrue((bool) $row['active']);
    }

    public function testConsecutiveUpdatesOfDifferentRowsWithTheSameColumnsStillLand(): void
    {
        $ids = new IdsCollection();
        static::getContainer()->get('product.repository')->create([
            (new ProductBuilder($ids, 'a'))->price(10)->stock(1)->build(),
            (new ProductBuilder($ids, 'b'))->price(10)->stock(1)->build(),
        ], Context::createDefaultContext());

        SwitchableWriteGateway::$mode = ['key' => false, 'upd' => true];
        static::getContainer()->get('product.repository')->update([
            ['id' => $ids->get('a'), 'stock' => 5],
            ['id' => $ids->get('b'), 'stock' => 7],
            ['id' => $ids->get('a'), 'stock' => 9],
        ], Context::createDefaultContext());

        $connection = static::getContainer()->get(Connection::class);
        $stock = static fn (string $id): int => (int) $connection->fetchOne('SELECT stock FROM product WHERE id = ?', [Uuid::fromHexToBytes($id)]);

        static::assertSame(9, $stock($ids->get('a')));
        static::assertSame(7, $stock($ids->get('b')));
    }
}
