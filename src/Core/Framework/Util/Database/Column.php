<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\Util\Database;

use Doctrine\DBAL\Schema\Column as DbalColumn;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\Type;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length,
        public bool $unsigned,
        public bool $isNotNull,
        public mixed $defaultValue,
    ) {
    }

    public static function createFromDbalColumn(DbalColumn $dbalColumn): self
    {
        return new Column(
            name: $dbalColumn->getObjectName()->getIdentifier()->getValue(),
            type: Type::lookupName($dbalColumn->getType()),
            length: $dbalColumn->getLength(),
            unsigned: $dbalColumn->getUnsigned(),
            isNotNull: $dbalColumn->getNotnull(),
            defaultValue: $dbalColumn->getType() instanceof BooleanType ? self::normalizeDefault($dbalColumn->getDefault()) : $dbalColumn->getDefault(),
        );
    }

    /**
     * MySQL reports the default of a boolean column (TINYINT(1), a SmallIntType in DBAL) as 0 / 1, PostgreSQL as false / true.
     * Only real boolean columns are normalised, so a string column with the default 'true' keeps it.
     */
    private static function normalizeDefault(mixed $default): mixed
    {
        return match (true) {
            $default === true || $default === 'true' => '1',
            $default === false || $default === 'false' => '0',
            default => $default,
        };
    }
}
