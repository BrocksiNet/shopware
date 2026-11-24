<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[Package('framework')]
#[AsMessageHandler]
final readonly class ProjectProductsHandler
{
    public function __construct(private ReadStoreProjector $projector)
    {
    }

    public function __invoke(ProjectProductsMessage $message): void
    {
        $this->projector->project($message->ids);
    }
}
