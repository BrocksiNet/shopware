<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\DataAbstractionLayer;

use Shopware\Core\Content\Product\Exception\DuplicateProductNumberException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
class ProductExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    public function matchException(\Throwable $e): ?\Throwable
    {
        $violation = DuplicateKeyViolation::matching($e, 'product', 'uniq.product.product_number__version_id');
        if ($violation !== null) {
            // MySQL joins the product number and the version id with `-`, PostgreSQL lists the columns
            $number = $violation->columnValues[0] ?? substr($violation->entry, 0, (int) strrpos($violation->entry, '-'));

            return new DuplicateProductNumberException($number, $e);
        }

        return null;
    }
}
