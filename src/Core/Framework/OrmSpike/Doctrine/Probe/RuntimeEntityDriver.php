<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Probe;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Shopware\Core\Framework\Log\Package;

/**
 * Probe "app custom entity": mapping metadata for a table that is only known at runtime (a `custom_entity_*` table defined by an app).
 * Doctrine needs a PHP class per entity, so the class is generated with `eval` and mapped from a column list.
 *
 * @internal
 */
#[Package('framework')]
final class RuntimeEntityDriver implements MappingDriver
{
    public const NAMESPACE = 'Shopware\Core\Framework\OrmSpike\Doctrine\Probe\Runtime';

    /**
     * @var array<class-string, array{table: string, columns: array<string, string>}>
     */
    private array $definitions = [];

    /**
     * @param array<string, string> $columns field name => doctrine type, `id` is the key
     *
     * @return class-string
     */
    public function define(string $name, string $table, array $columns): string
    {
        /** @var class-string $class */
        $class = self::NAMESPACE . '\\' . $name;
        if (!class_exists($class, false)) {
            $properties = '';
            foreach (array_keys($columns) as $field) {
                $properties .= 'public mixed $' . $field . ' = null; ';
            }
            eval('namespace ' . self::NAMESPACE . '; class ' . $name . ' { ' . $properties . '}'); // phpcs:ignore
        }
        $this->definitions[$class] = ['table' => $table, 'columns' => $columns];

        return $class;
    }

    public function loadMetadataForClass(string $className, PersistenceClassMetadata $metadata): void
    {
        \assert($metadata instanceof ClassMetadata);
        $definition = $this->definitions[$className];
        $metadata->setPrimaryTable(['name' => $definition['table']]);
        foreach ($definition['columns'] as $field => $type) {
            $metadata->mapField(['fieldName' => $field, 'columnName' => $field, 'type' => $type, 'id' => $field === 'id', 'nullable' => $field !== 'id']);
        }
    }

    public function getAllClassNames(): array
    {
        return array_keys($this->definitions);
    }

    public function isTransient(string $className): bool
    {
        return !isset($this->definitions[$className]);
    }
}
