<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Fair;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The checks the DAL runs before it writes S4 data, for the full variants of the ORMs and of DBAL (docs/35):
 *
 * - Validation: `validate()` runs the Symfony validator over entities that carry the constraints as attributes (Doctrine, Cycle),
 *   `plain()` runs the same rules as plain PHP checks (DBAL). The rules are the constraints of the field serializers of
 *   `ProductDefinition`, `ProductTranslationDefinition`, `ProductVisibilityDefinition` and `ProductCategoryDefinition`.
 * - Write protection: `assertWritable()` reads the `WriteProtected` flags of the entity definition and checks the scope of the context
 *   for every property that is set, like `WriteCommandExtractor::validateContextHasPermission()`.
 * Both throw {@see WriteRejected} before any statement runs.
 *
 * @internal
 */
#[Package('framework')]
final class WriteContract
{
    /**
     * @var array<string, array<string, WriteProtected>>
     */
    private array $protected = [];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly DefinitionInstanceRegistry $registry,
    ) {
    }

    /**
     * @param iterable<object> $entities
     */
    public function validate(iterable $entities, ?string $property = null): void
    {
        $violations = [];
        foreach ($entities as $i => $entity) {
            $list = $property === null ? $this->validator->validate($entity) : $this->validator->validateProperty($entity, $property);
            foreach ($list as $violation) {
                $violations[] = $i . '/' . $violation->getPropertyPath() . ': ' . $violation->getMessage();
            }
        }
        if ($violations !== []) {
            throw new WriteRejected($violations);
        }
    }

    /**
     * Write protection of the properties that are set on an entity or row.
     *
     * @param array<string, mixed> $values property name => value, null values and empty associations count as not written
     */
    public function assertWritable(string $entityName, array $values, Context $context): void
    {
        $flags = $this->protected[$entityName] ??= $this->flags($entityName);
        $violations = [];
        foreach ($values as $property => $value) {
            if ($value === null || $value === [] || ($value instanceof \Countable && \count($value) === 0) || !isset($flags[$property])) {
                continue;
            }
            if (!$flags[$property]->isAllowed($context->getScope())) {
                $violations[] = $entityName . '.' . $property . ': This field is write-protected.' . (\is_object($value) ? ' [' . $value::class . ']' : ' [' . json_encode($value) . ']');
            }
        }
        if ($violations !== []) {
            throw new WriteRejected($violations);
        }
    }

    /**
     * Property names that are set on an object (public properties), input of {@see assertWritable()}.
     *
     * @return array<string, mixed>
     */
    public static function properties(object $entity): array
    {
        return get_object_vars($entity);
    }

    /**
     * The same rules as the attributes on the Doctrine and Cycle entities, as plain checks for the DBAL implementation.
     *
     * @param list<WrittenProduct> $products
     */
    public static function plain(array $products): void
    {
        $violations = [];
        $uuid = static function (mixed $value, string $path) use (&$violations): void {
            if ($value === null || $value === '') {
                $violations[] = $path . ': This value should not be blank.';
            } elseif (!\is_string($value) || !Uuid::isValid($value)) {
                $violations[] = $path . ': The string is not a valid uuid.';
            }
        };
        $string = static function (mixed $value, string $path) use (&$violations): void {
            if (!\is_string($value)) {
                $violations[] = $path . ': This value should be of type string.';

                return;
            }
            if (trim($value) === '') {
                $violations[] = $path . ': This value should not be blank.';
            }
            if (mb_strlen(trim($value)) > 255) {
                $violations[] = $path . ': This value is too long.';
            }
        };
        $numeric = static function (mixed $value, string $path) use (&$violations): void {
            if ($value === null || $value === '') {
                $violations[] = $path . ': This value should not be blank.';
            } elseif (!is_numeric($value)) {
                $violations[] = $path . ': This value should be of type numeric.';
            }
        };

        foreach ($products as $i => $p) {
            $uuid($p->id, $i . '/id');
            $uuid($p->taxId, $i . '/taxId');
            $string($p->productNumber, $i . '/productNumber');
            foreach ([['stock', $p->stock], ['newStock', $p->newStock]] as [$name, $stock]) {
                if (!\is_int($stock)) {
                    $violations[] = $i . '/' . $name . ': This value should be of type int.';
                }
            }
            $uuid($p->currencyId, $i . '/price/currencyId');
            $numeric($p->gross, $i . '/price/gross');
            $numeric($p->net, $i . '/price/net');
            foreach ($p->translations as $n => $t) {
                $uuid($t['languageId'], $i . '/translations/' . $n . '/languageId');
                $string($t['name'], $i . '/translations/' . $n . '/name');
            }
            foreach ($p->categoryIds as $n => $categoryId) {
                $uuid($categoryId, $i . '/categories/' . $n);
            }
            foreach ($p->visibilities as $n => $v) {
                $uuid($v['id'], $i . '/visibilities/' . $n . '/id');
                $uuid($v['salesChannelId'], $i . '/visibilities/' . $n . '/salesChannelId');
                if (!\in_array($v['visibility'], [10, 20, 30], true)) {
                    $violations[] = $i . '/visibilities/' . $n . '/visibility: The value you selected is not a valid choice.';
                }
            }
        }
        if ($violations !== []) {
            throw new WriteRejected($violations);
        }
    }

    /**
     * @return array<string, WriteProtected>
     */
    private function flags(string $entityName): array
    {
        $flags = [];
        foreach ($this->registry->getByEntityName($entityName)->getFields() as $field) {
            $flag = $field->getFlag(WriteProtected::class);
            if ($flag instanceof WriteProtected) {
                $flags[$field->getPropertyName()] = $flag;
            }
        }

        return $flags;
    }
}
