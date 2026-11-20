<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductSearchConfigField;

use Shopware\Core\Content\Product\Exception\DuplicateProductSearchConfigFieldException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class ProductSearchConfigFieldExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    public function matchException(\Throwable $e): ?\Throwable
    {
        if (preg_match('/SQLSTATE\[23000\]:.*1062 Duplicate.*uniq.search_config_field.field__config_id\'/', $e->getMessage())) {
            $field = [];
            preg_match('/Duplicate entry \'(.*)\' for key/', $e->getMessage(), $field);
            $fieldNameMatch = $field[1] ?? '';
            $field = substr($fieldNameMatch, 0, (int) strpos($fieldNameMatch, '-'));

            return new DuplicateProductSearchConfigFieldException($field, $e);
        }

        // PostgreSQL: the constraint name is shortened to 63 characters with a hash, the key is listed as "Key (field, product_search_config_id)=(test, <uuid>)"
        if (preg_match('/SQLSTATE\[23505\].*"[^"]*\.uniq\.search_config_field\.[^"]*".*Key \(field, product_search_config_id\)=\(([^,]*),/s', $e->getMessage(), $match)) {
            return new DuplicateProductSearchConfigFieldException($match[1], $e);
        }

        return null;
    }
}
