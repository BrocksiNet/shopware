<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductSearchConfig;

use Shopware\Core\Content\Product\Exception\DuplicateProductSearchConfigLanguageException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class ProductSearchConfigExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    public function matchException(\Throwable $e): ?\Throwable
    {
        if (DuplicateKeyViolation::matching($e, 'product_search_config', 'uniq.product_search_config.language_id') !== null) {
            return new DuplicateProductSearchConfigLanguageException('', $e);
        }

        return null;
    }
}
