<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class IdCodecStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        // PDO sends false as empty string, which is neither a valid boolean nor a valid integer on PostgreSQL
        if (\is_bool($value)) {
            parent::bindValue($param, $value ? '1' : '0', ParameterType::STRING);

            return;
        }

        // Pagination starts with an empty binary id (`WHERE id > :lastId`), the nil uuid sorts before every id as well
        if ($value === '' && $type === ParameterType::BINARY) {
            parent::bindValue($param, '00000000-0000-0000-0000-000000000000', ParameterType::STRING);

            return;
        }

        $boundAsBinary = $type === ParameterType::BINARY || $type === ParameterType::LARGE_OBJECT;
        $encoded = IdCodecMiddleware::encode($value, $boundAsBinary);

        if ($encoded !== $value) {
            $type = ParameterType::STRING;
        } elseif ($type === ParameterType::STRING && \is_string($value) && (str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8'))) {
            // A text parameter ends at the first NUL byte, the rest would be dropped without an error (e.g. PHP serialize() output)
            $type = ParameterType::BINARY;
        }

        parent::bindValue($param, $encoded, $type);
    }

    public function execute(): Result
    {
        return new IdCodecResult(parent::execute(), $this->sql);
    }
}
