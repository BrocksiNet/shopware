<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Shopware\Core\Framework\Log\Package;

/**
 * The tables of the generation a query reads and the alias of its document table.
 *
 * @internal
 */
#[Package('framework')]
final readonly class SqlScope
{
    public function __construct(
        public string $documents,
        public string $keywords,
        public string $alias = 'd',
    ) {
    }
}
