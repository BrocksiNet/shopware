<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
#[Package('framework')]
final class TimedDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly EventDispatcherInterface $inner)
    {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        Probe::enter('events');

        try {
            return $this->inner->dispatch($event, $eventName);
        } finally {
            Probe::leave();
        }
    }

    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    public function removeListener(string $eventName, callable|array $listener): void
    {
        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }

    public function getListenerPriority(string $eventName, callable|array $listener): ?int
    {
        return $this->inner->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->inner->hasListeners($eventName);
    }
}
