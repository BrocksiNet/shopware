<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Probe;

use Cycle\Database\DatabaseInterface;
use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Command\CompleteMethodInterface;
use Shopware\Core\Framework\Log\Package;

/**
 * Decorates a Cycle command so a callback runs after the command was executed, the closest thing to `EntityWrittenEvent` without a package.
 *
 * @internal
 */
#[Package('framework')]
final class EventCommand implements CommandInterface, CompleteMethodInterface
{
    public function __construct(private readonly CommandInterface $inner, private readonly \Closure $onWritten)
    {
    }

    public function isReady(): bool
    {
        return $this->inner->isReady();
    }

    public function isExecuted(): bool
    {
        return $this->inner->isExecuted();
    }

    public function execute(): void
    {
        $this->inner->execute();
    }

    public function getDatabase(): ?DatabaseInterface
    {
        return $this->inner->getDatabase();
    }

    public function complete(): void
    {
        if ($this->inner instanceof CompleteMethodInterface) {
            $this->inner->complete();
        }
        ($this->onWritten)();
    }
}
