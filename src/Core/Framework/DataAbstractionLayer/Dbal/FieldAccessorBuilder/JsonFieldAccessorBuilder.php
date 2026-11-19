<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class JsonFieldAccessorBuilder implements FieldAccessorBuilderInterface
{
    private ?SqlDialect $dialect;

    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        ?SqlDialect $dialect = null
    ) {
        $this->dialect = $dialect;
    }

    public function buildAccessor(string $root, Field $field, Context $context, string $accessor): ?string
    {
        if (!$field instanceof JsonField) {
            return null;
        }

        $jsonPath = preg_replace(
            '#^' . preg_quote($field->getPropertyName(), '#') . '#',
            '',
            $accessor
        );

        if ($jsonPath === null || $jsonPath === '') {
            return $this->quote($root) . '.' . $this->quote($field->getStorageName());
        }

        if (!EntityDefinitionQueryHelper::isValidJsonPath($jsonPath)) {
            throw DataAbstractionLayerException::invalidIdentifier($jsonPath);
        }

        // enquote hyphenated json keys in path
        if (str_contains($jsonPath, '-')) {
            $jsonPathParts = explode('.', $jsonPath);
            foreach ($jsonPathParts as $index => $jsonPathPart) {
                if ($index === 0) {
                    continue;
                }
                if (str_contains($jsonPathPart, '-')) {
                    $jsonPathParts[$index] = \sprintf('"%s"', $jsonPathPart);
                }
            }
            $jsonPath = implode('.', $jsonPathParts);
        }

        $embeddedField = $this->getField($jsonPath, $field->getPropertyMapping());

        return $this->dialect()->jsonValue(
            $this->quote($root) . '.' . $this->quote($field->getStorageName()),
            '$' . $jsonPath,
            $this->getJsonType($embeddedField)
        );
    }

    /**
     * @param Field[] $fields
     */
    private function getField(string $path, array $fields): ?Field
    {
        /** @var string $fieldName */
        $fieldName = preg_replace(
            '#^\.("([^"]*)"|([^.]*)).*#',
            '$2$3',
            $path
        );
        $subPath = mb_substr($path, mb_strlen($fieldName) + 1);

        foreach ($fields as $field) {
            if ($field->getPropertyName() !== $fieldName) {
                continue;
            }

            if ($field instanceof JsonField && $field->getPropertyMapping() !== []) {
                return $this->getField($subPath, $field->getPropertyMapping());
            }

            return $field;
        }

        return null;
    }

    private function quote(string $identifier): string
    {
        if (!EntityDefinitionQueryHelper::isValidIdentifier($identifier)) {
            throw DataAbstractionLayerException::invalidIdentifier($identifier);
        }

        return $this->dialect()->quoteIdentifier($identifier);
    }

    private function getJsonType(?Field $field): string
    {
        return match (true) {
            $field instanceof IntField, $field instanceof FloatField => SqlDialect::JSON_TYPE_FLOAT,
            $field instanceof BoolField => SqlDialect::JSON_TYPE_BOOL,
            $field instanceof DateTimeField => SqlDialect::JSON_TYPE_DATETIME,
            $field instanceof DateField => SqlDialect::JSON_TYPE_DATE,
            default => SqlDialect::JSON_TYPE_STRING,
        };
    }

    /**
     * Resolved on first use, the platform detection needs a database connection.
     */
    private function dialect(): SqlDialect
    {
        return $this->dialect ??= SqlDialectFactory::forConnection($this->connection);
    }
}
