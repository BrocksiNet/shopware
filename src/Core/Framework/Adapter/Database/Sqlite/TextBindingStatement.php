<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * SQLite compares a BLOB never equal to TEXT. The DAL binds ids as bytes with different types (plain string on write, BINARY in IN lists on read),
 * so every binary parameter is bound the same way, as text of the raw bytes (pdo_sqlite stores them unchanged).
 *
 * @internal
 */
#[Package('framework')]
class TextBindingStatement extends AbstractStatementMiddleware
{
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if ($type === ParameterType::BINARY || $type === ParameterType::LARGE_OBJECT) {
            $type = ParameterType::STRING;
        }

        parent::bindValue($param, $value, $type);
    }
}
