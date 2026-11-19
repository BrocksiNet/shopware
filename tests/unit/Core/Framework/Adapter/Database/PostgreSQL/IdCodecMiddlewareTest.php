<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\IdCodecMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(IdCodecMiddleware::class)]
class IdCodecMiddlewareTest extends TestCase
{
    private const UTF8_ID = '0123456789abcdef';

    protected function tearDown(): void
    {
        putenv('SHOPWARE_PG_ID_CODEC');
        unset($_SERVER['SHOPWARE_PG_ID_CODEC'], $_ENV['SHOPWARE_PG_ID_CODEC']);
    }

    public function testGuessModeConvertsTypedAndNonUtf8Values(): void
    {
        $bytes = "\xff" . str_repeat("\x01", 15);

        static::assertFalse(IdCodecMiddleware::isStrict());
        static::assertSame(bin2hex($bytes), IdCodecMiddleware::encode($bytes, false));
        static::assertSame(bin2hex(self::UTF8_ID), IdCodecMiddleware::encode(self::UTF8_ID, true));
    }

    public function testGuessModeLeavesUntypedUtf8ValuesAlone(): void
    {
        static::assertSame(self::UTF8_ID, IdCodecMiddleware::encode(self::UTF8_ID, false));
    }

    public function testStrictModeConvertsOnlyTypedValues(): void
    {
        $_SERVER['SHOPWARE_PG_ID_CODEC'] = 'strict';
        $bytes = "\xff" . str_repeat("\x01", 15);

        static::assertTrue(IdCodecMiddleware::isStrict());
        static::assertSame($bytes, IdCodecMiddleware::encode($bytes, false));
        static::assertSame(bin2hex($bytes), IdCodecMiddleware::encode($bytes, true));
        static::assertSame(bin2hex(self::UTF8_ID), IdCodecMiddleware::encode(self::UTF8_ID, true));
    }

    public function testOtherValuesAreNeverConverted(): void
    {
        static::assertSame('short', IdCodecMiddleware::encode('short', true));
        static::assertSame(5, IdCodecMiddleware::encode(5, true));
    }

    public function testDecodeConvertsOnlyCanonicalUuids(): void
    {
        static::assertSame(hex2bin('0123456789abcdef0123456789abcdef'), IdCodecMiddleware::decode('01234567-89ab-cdef-0123-456789abcdef'));
        static::assertSame('not-a-uuid', IdCodecMiddleware::decode('not-a-uuid'));
    }
}
