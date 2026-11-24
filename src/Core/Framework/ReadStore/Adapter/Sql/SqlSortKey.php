<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;

/**
 * One term of an ORDER BY. The expression is the plain value, `mode` says how it is compared:
 * `num` numerically, `bin` byte wise after lower casing (ids, dates) and `ci` with the case insensitive collation (strings).
 *
 * @internal
 */
#[Package('framework')]
final readonly class SqlSortKey
{
    /**
     * @param 'num'|'bin'|'ci' $mode
     * @param 'ASC'|'DESC' $direction
     */
    public function __construct(
        public string $expression,
        public string $mode,
        public string $direction,
        public FieldType $type,
        public bool $takesMinimum,
    ) {
    }
}
