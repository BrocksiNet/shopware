<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductSearchConfigField;

use Shopware\Core\Content\Product\Exception\DuplicateProductSearchConfigFieldException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
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
        $violation = DuplicateKeyViolation::matching($e, 'product_search_config_field', 'uniq.search_config_field.field__config_id');
        if ($violation !== null) {
            $field = $violation->columnValues[0] ?? substr($violation->entry, 0, (int) strpos($violation->entry, '-'));

            return new DuplicateProductSearchConfigFieldException($field, $e);
        }

        return null;
    }
}
