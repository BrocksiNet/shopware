<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;

/**
 * @internal
 */
#[CoversClass(IdBinding::class)]
class IdBindingTest extends TestCase
{
    private const HEX = '0190a3b2c4d57e6f8091a2b3c4d5e6f7';

    protected function tearDown(): void
    {
        IdBinding::useHexBinding(null);
    }

    public function testBinaryBinding(): void
    {
        IdBinding::useHexBinding(false);

        static::assertSame(hex2bin(self::HEX), IdBinding::value(strtoupper(self::HEX)));
        static::assertSame([hex2bin(self::HEX)], IdBinding::values(['a' => self::HEX]));
        static::assertSame(ParameterType::BINARY, IdBinding::type());
        static::assertSame(ArrayParameterType::BINARY, IdBinding::listType());
    }

    public function testHexBinding(): void
    {
        IdBinding::useHexBinding(true);

        static::assertSame(self::HEX, IdBinding::value(strtoupper(self::HEX)));
        static::assertSame([self::HEX], IdBinding::values(['a' => self::HEX]));
        static::assertSame(ParameterType::STRING, IdBinding::type());
        static::assertSame(ArrayParameterType::STRING, IdBinding::listType());
    }

    public function testTextExpression(): void
    {
        IdBinding::useHexBinding(false);
        static::assertSame('LOWER(HEX(p.id))', IdBinding::text('p.id'));

        IdBinding::useHexBinding(true);
        static::assertSame('REPLACE(p.id::text, \'-\', \'\')', IdBinding::text('p.id'));
    }

    public function testToHexAcceptsBytesUuidTextAndHex(): void
    {
        $uuid = '0190a3b2-c4d5-7e6f-8091-a2b3c4d5e6f7';

        static::assertSame(self::HEX, IdBinding::toHex((string) hex2bin(self::HEX)));
        static::assertSame(self::HEX, IdBinding::toHex($uuid));
        static::assertSame(self::HEX, IdBinding::toHex(strtoupper(self::HEX)));
        static::assertSame([self::HEX, self::HEX], IdBinding::toHexList([$uuid, self::HEX]));
    }
}
