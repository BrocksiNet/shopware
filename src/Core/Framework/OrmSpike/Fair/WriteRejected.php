<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Fair;

use Shopware\Core\Framework\Log\Package;

/**
 * Thrown by the full write variants before any row is written.
 *
 * @internal
 */
#[Package('framework')]
final class WriteRejected extends \RuntimeException
{
    /**
     * @param list<string> $violations `path: message`
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Write rejected: ' . implode('; ', \array_slice($violations, 0, 5)) . (\count($violations) > 5 ? ' (+' . (\count($violations) - 5) . ' more)' : ''));
    }
}
