<?php declare(strict_types=1);

namespace Shopware\Core\Content\ProductExport\DataAbstractionLayer;

use Shopware\Core\Content\ProductExport\Exception\DuplicateFileNameException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class ProductExportExceptionHandler implements ExceptionHandlerInterface
{
    public function matchException(\Throwable $e): ?\Throwable
    {
        $violation = DuplicateKeyViolation::matching($e, 'product_export', 'file_name');
        if ($violation !== null) {
            return new DuplicateFileNameException($violation->entry, $e);
        }

        return null;
    }

    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }
}
