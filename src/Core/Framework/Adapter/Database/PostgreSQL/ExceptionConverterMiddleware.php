<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Exception\DriverException as ConvertedDriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Query;
use Shopware\Core\Framework\Log\Package;

/**
 * The DBAL converter of pdo_pgsql does not know SQLSTATE 55P03 (lock_not_available, raised by `lock_timeout`).
 * MySQL reports a lock wait timeout as `LockWaitTimeoutException`, the retry logic of the core relies on that class.
 *
 * @internal
 */
#[Package('framework')]
class ExceptionConverterMiddleware implements Middleware
{
    public const LOCK_NOT_AVAILABLE = '55P03';

    /**
     * PostgreSQL reports a violated `ON DELETE RESTRICT` foreign key with its own SQLSTATE, MySQL reports it as a foreign key violation (1451).
     */
    public const RESTRICT_VIOLATION = '23001';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function getExceptionConverter(): ExceptionConverter
            {
                $converter = parent::getExceptionConverter();

                return new class($converter) implements ExceptionConverter {
                    public function __construct(private readonly ExceptionConverter $converter)
                    {
                    }

                    public function convert(DriverException $exception, ?Query $query): ConvertedDriverException
                    {
                        if ($exception->getSQLState() === ExceptionConverterMiddleware::LOCK_NOT_AVAILABLE) {
                            return new LockWaitTimeoutException($exception, $query);
                        }

                        if ($exception->getSQLState() === ExceptionConverterMiddleware::RESTRICT_VIOLATION) {
                            return new ForeignKeyConstraintViolationException($exception, $query);
                        }

                        return $this->converter->convert($exception, $query);
                    }
                };
            }
        };
    }
}
