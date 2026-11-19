<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Shopware\Core\Framework\Log\Package;

/**
 * Same SQL as MySQL for the methods of the interface, kept separate for the first difference that shows up.
 *
 * @internal
 */
#[Package('framework')]
class MariaDbDialect extends MySqlDialect
{
    public function getName(): string
    {
        return self::MARIADB;
    }
}
