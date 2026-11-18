<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Util\Database;

use Doctrine\DBAL\Schema\Column as DbalColumn;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Util\Database\Column;

/**
 * @internal
 */
#[CoversClass(Column::class)]
class ColumnTest extends TestCase
{
    #[DataProvider('defaultProvider')]
    public function testDefaultIsNormalized(mixed $default, mixed $expected): void
    {
        $dbalColumn = new DbalColumn('active', Type::getType(Types::BOOLEAN), ['default' => $default]);

        static::assertSame($expected, Column::createFromDbalColumn($dbalColumn)->defaultValue);
    }

    public function testStringDefaultOfNonBooleanColumnIsKept(): void
    {
        $dbalColumn = new DbalColumn('mode', Type::getType(Types::STRING), ['default' => 'true']);

        static::assertSame('true', Column::createFromDbalColumn($dbalColumn)->defaultValue);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function defaultProvider(): iterable
    {
        yield 'false string' => ['false', '0'];
        yield 'true string' => ['true', '1'];
        yield 'mysql zero is kept' => ['0', '0'];
        yield 'mysql one is kept' => ['1', '1'];
        yield 'no default' => [null, null];
    }
}
