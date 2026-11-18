<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class IdCodecStatement extends AbstractStatementMiddleware
{
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $boundAsBinary = $type === ParameterType::BINARY || $type === ParameterType::LARGE_OBJECT;
        $encoded = IdCodecMiddleware::encode($value, $boundAsBinary);

        if ($encoded !== $value) {
            $type = ParameterType::STRING;
        }

        parent::bindValue($param, $encoded, $type);
    }

    public function execute(): Result
    {
        return new IdCodecResult(parent::execute());
    }
}
