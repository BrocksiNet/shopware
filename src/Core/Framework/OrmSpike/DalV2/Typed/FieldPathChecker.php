<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Typed;

use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslationsAssociationField;
use Shopware\Core\Framework\Log\Package;

/**
 * X2 prototype: validates a criteria field path (`manufacturer.translations.name`) against the registered definitions.
 * This is the check a PHPStan rule would run on the string literal of a `Criteria` call; `suggest()` is what a generated constant list holds.
 *
 * @internal
 */
#[Package('framework')]
final class FieldPathChecker
{
    public function __construct(private readonly DefinitionInstanceRegistry $registry)
    {
    }

    /**
     * @return string|null null when the path is valid, otherwise the first unknown segment with the entity it was looked up on
     */
    public function check(string $entity, string $path): ?string
    {
        $definition = $this->registry->getByEntityName($entity);
        $segments = explode('.', $path);
        $count = \count($segments);
        foreach ($segments as $i => $segment) {
            $field = $definition->getFields()->get($segment);
            if ($field === null) {
                return \sprintf('"%s" is not a field of %s', $segment, $definition->getEntityName());
            }
            $last = $i === $count - 1;
            if (!$field instanceof AssociationField) {
                if (!$last && !$field instanceof TranslatedField) {
                    return \sprintf('"%s" of %s is not an association', $segment, $definition->getEntityName());
                }

                return null;
            }
            $definition = $field->getReferenceDefinition();
            if ($field instanceof ManyToManyAssociationField) {
                $definition = $field->getToManyReferenceDefinition();
            }
        }

        return null;
    }

    /**
     * Every path up to the given depth: the constant list a generator would write per entity.
     *
     * @return list<string>
     */
    public function paths(string $entity, int $depth = 2, string $prefix = ''): array
    {
        $paths = [];
        foreach ($this->registry->getByEntityName($entity)->getFields() as $field) {
            $path = $prefix . $field->getPropertyName();
            $paths[] = $path;
            $isAssociation = $field instanceof OneToManyAssociationField || $field instanceof ManyToOneAssociationField
                || $field instanceof OneToOneAssociationField || $field instanceof ManyToManyAssociationField || $field instanceof TranslationsAssociationField;
            if ($depth > 1 && $isAssociation) {
                $reference = $field instanceof ManyToManyAssociationField ? $field->getToManyReferenceDefinition() : $field->getReferenceDefinition();
                array_push($paths, ...$this->paths($reference->getEntityName(), $depth - 1, $path . '.'));
            }
        }

        return $paths;
    }
}
