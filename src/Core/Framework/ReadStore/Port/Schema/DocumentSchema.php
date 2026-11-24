<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Schema;

use Shopware\Core\Framework\Log\Package;

/**
 * The fields of a document class, read once from its `#[Field]` attributes.
 *
 * @internal
 */
#[Package('framework')]
final class DocumentSchema
{
    /**
     * @var array<class-string, array<string, Field>>
     */
    private static array $cache = [];

    /**
     * @param class-string $class
     *
     * @return array<string, Field>
     */
    public static function fields(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $attribute = $property->getAttributes(Field::class)[0] ?? null;
            if ($attribute !== null) {
                $fields[$property->getName()] = $attribute->newInstance();
            }
        }

        return self::$cache[$class] = $fields;
    }
}
