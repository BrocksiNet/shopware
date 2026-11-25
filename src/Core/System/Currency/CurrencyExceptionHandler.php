<?php declare(strict_types=1);

namespace Shopware\Core\System\Currency;

use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('fundamentals@framework')]
class CurrencyExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    public function matchException(\Throwable $e): ?\Throwable
    {
        $violation = DuplicateKeyViolation::matching($e, 'currency', 'uniq.currency.iso_code');
        if ($violation !== null) {
            return CurrencyException::isoCodeNotUnique($violation->entry, $e);
        }

        return null;
    }
}
