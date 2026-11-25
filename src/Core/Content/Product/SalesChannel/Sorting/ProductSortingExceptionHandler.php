<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\Sorting;

use Shopware\Core\Content\Product\Exception\DuplicateProductSortingKeyException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class ProductSortingExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    public function matchException(\Throwable $e): ?\Throwable
    {
        $violation = DuplicateKeyViolation::matching($e, 'product_sorting', 'uniq.product_sorting.url_key');
        if ($violation !== null) {
            return new DuplicateProductSortingKeyException($violation->entry, $e);
        }

        return null;
    }
}
