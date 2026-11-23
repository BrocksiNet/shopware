<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Probe;

use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Heap\State;
use Cycle\ORM\Mapper\Mapper;
use Shopware\Core\Framework\Log\Package;

/**
 * Mapper that reports every queued and every written insert, one hook for single and bulk writes.
 *
 * @internal
 */
#[Package('framework')]
class EventMapper extends Mapper
{
    public static int $queued = 0;

    public static int $written = 0;

    public function queueCreate(object $entity, Node $node, State $state): CommandInterface
    {
        ++self::$queued;

        return new EventCommand(parent::queueCreate($entity, $node, $state), static function (): void {
            ++self::$written;
        });
    }
}
