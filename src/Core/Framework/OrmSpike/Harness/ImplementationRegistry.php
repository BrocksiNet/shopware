<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Psr\Container\ContainerInterface;
use Shopware\Core\Framework\Log\Package;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Lazy lookup of the implementations tagged `orm_spike.implementation`, indexed by {@see Implementation::key()},
 * so an implementation that is not finished does not break the others.
 *
 * @internal
 */
#[Package('framework')]
final class ImplementationRegistry
{
    public function __construct(private readonly ContainerInterface $implementations)
    {
    }

    public function get(string $key): Implementation
    {
        if (!$this->implementations->has($key)) {
            throw new \InvalidArgumentException(\sprintf(
                'Implementation "%s" is not registered (registered: %s). Register it in OrmSpike/DependencyInjection/%s.php with the tag orm_spike.implementation.',
                $key,
                implode(', ', $this->keys()) ?: 'none',
                $key
            ));
        }

        $implementation = $this->implementations->get($key);
        \assert($implementation instanceof Implementation);

        return $implementation;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->implementations instanceof ServiceProviderInterface ? array_keys($this->implementations->getProvidedServices()) : [];
    }
}
