<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer;

use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Shipping\ShippingException;
use Shopware\Core\Content\ImportExport\ImportExportException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('checkout')]
class TechnicalNameExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    public function matchException(\Throwable $e): ?\Throwable
    {
        $violation = DuplicateKeyViolation::fromThrowable($e);
        if ($violation === null) {
            return null;
        }

        if ($violation->isKey('payment_method', 'uniq.technical_name')) {
            return PaymentException::duplicateTechnicalName($violation->entry);
        }

        if ($violation->isKey('shipping_method', 'uniq.technical_name')) {
            return ShippingException::duplicateTechnicalName($violation->entry);
        }

        if ($violation->isKey('import_export_profile', 'uniq.import_export_profile.technical_name')) {
            return ImportExportException::duplicateTechnicalName($violation->entry);
        }

        return null;
    }
}
