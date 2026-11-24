<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Shopware\Core\Framework\Log\Package;

/**
 * MariaDB 10.11. `JSON` is an alias of `LONGTEXT` with a validity check there, so the document keeps its key order.
 *
 * @internal
 */
#[Package('framework')]
final class MariaDbJsonDialect extends MySqlJsonDialect
{
    public function name(): string
    {
        return 'mariadb';
    }

    protected function documentType(): string
    {
        return 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
    }

    protected function documentConstraint(): string
    {
        return ' CHECK (JSON_VALID(doc))';
    }
}
