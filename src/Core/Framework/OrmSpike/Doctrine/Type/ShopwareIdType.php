<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

/**
 * Keeps ids as 32 character lower case hex strings in PHP and binds them per database through {@see IdBinding}
 * (`uuid` text on PostgreSQL, `binary(16)` elsewhere). The database to PHP direction also accepts the dashed form PostgreSQL returns.
 *
 * @internal
 */
#[Package('framework')]
final class ShopwareIdType extends Type
{
    public const NAME = 'shopware_id';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return IdBinding::isHexBinding() ? 'UUID' : 'BINARY(16)';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : IdBinding::value((string) $value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return IdBinding::toHex((string) $value);
    }

    public function getBindingType(): ParameterType
    {
        return IdBinding::type();
    }
}
