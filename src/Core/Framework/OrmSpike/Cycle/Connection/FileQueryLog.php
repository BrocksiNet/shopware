<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Connection;

use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\Log\Package;

/**
 * Debug aid: appends every statement Cycle executes (level `info` and `error`) to a file (`--option sqllog=<file>`).
 *
 * @internal
 */
#[Package('framework')]
final class FileQueryLog extends AbstractLogger
{
    public function __construct(private readonly string $file)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (\in_array($level, ['info', 'error'], true)) {
            file_put_contents($this->file, $level . ': ' . $message . "\n", \FILE_APPEND);
        }
    }
}
