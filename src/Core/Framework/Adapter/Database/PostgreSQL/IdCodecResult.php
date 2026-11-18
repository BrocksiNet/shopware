<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class IdCodecResult extends AbstractResultMiddleware
{
    public function fetchNumeric(): array|false
    {
        $row = parent::fetchNumeric();

        return $row === false ? false : array_map(IdCodecMiddleware::decode(...), $row);
    }

    public function fetchAssociative(): array|false
    {
        $row = parent::fetchAssociative();

        return $row === false ? false : array_map(IdCodecMiddleware::decode(...), $row);
    }

    public function fetchOne(): mixed
    {
        return IdCodecMiddleware::decode(parent::fetchOne());
    }

    public function fetchAllNumeric(): array
    {
        return array_map(static fn (array $row): array => array_map(IdCodecMiddleware::decode(...), $row), parent::fetchAllNumeric());
    }

    public function fetchAllAssociative(): array
    {
        return array_map(static fn (array $row): array => array_map(IdCodecMiddleware::decode(...), $row), parent::fetchAllAssociative());
    }

    public function fetchFirstColumn(): array
    {
        return array_map(IdCodecMiddleware::decode(...), parent::fetchFirstColumn());
    }
}
