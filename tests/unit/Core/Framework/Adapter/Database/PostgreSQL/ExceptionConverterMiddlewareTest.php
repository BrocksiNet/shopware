<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter as PostgreSQLExceptionConverter;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\ExceptionConverterMiddleware;

/**
 * @internal
 */
#[CoversClass(ExceptionConverterMiddleware::class)]
class ExceptionConverterMiddlewareTest extends TestCase
{
    public function testLockNotAvailableIsConvertedToLockWaitTimeout(): void
    {
        $original = self::driverException('55P03');

        $converted = $this->converter()->convert($original, null);

        static::assertInstanceOf(LockWaitTimeoutException::class, $converted);
        static::assertSame($original, $converted->getPrevious());
        static::assertSame('55P03', $converted->getSQLState());
    }

    public function testRestrictViolationIsConvertedToForeignKeyViolation(): void
    {
        $converted = $this->converter()->convert(self::driverException('23001'), null);

        static::assertInstanceOf(ForeignKeyConstraintViolationException::class, $converted);
    }

    public function testStockConverterWithoutTheMiddlewareDoesNotMapLockNotAvailable(): void
    {
        static::assertNotInstanceOf(LockWaitTimeoutException::class, (new PostgreSQLExceptionConverter())->convert(self::driverException('55P03'), null));
    }

    public function testOtherErrorsStillGoThroughTheDriverConverter(): void
    {
        static::assertInstanceOf(DeadlockException::class, $this->converter()->convert(self::driverException('40P01'), null));
        static::assertInstanceOf(UniqueConstraintViolationException::class, $this->converter()->convert(self::driverException('23505'), null));
    }

    private function converter(): Driver\API\ExceptionConverter
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('getExceptionConverter')->willReturn(new PostgreSQLExceptionConverter());

        return (new ExceptionConverterMiddleware())->wrap($driver)->getExceptionConverter();
    }

    private static function driverException(string $sqlState): DriverException
    {
        return new class($sqlState) extends \Exception implements DriverException {
            public function __construct(private readonly string $sqlState)
            {
                parent::__construct('driver error ' . $sqlState);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}
