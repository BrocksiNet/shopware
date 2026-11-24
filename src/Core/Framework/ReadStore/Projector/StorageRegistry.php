<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Builder\DocumentBuilder;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Lazy lookup of the stores (tag `read_store.storage`, attribute `key`) and the document builders (tag `read_store.document_builder`, attribute `key`).
 *
 * @internal
 */
#[Package('framework')]
final readonly class StorageRegistry
{
    /**
     * @param ServiceProviderInterface<Storage> $storages
     * @param ServiceProviderInterface<DocumentBuilder> $builders
     */
    public function __construct(
        private ServiceProviderInterface $storages,
        private ServiceProviderInterface $builders,
    ) {
    }

    public function storage(string $key): Storage
    {
        if (!$this->storages->has($key)) {
            throw ReadStoreException::notRegistered('Read store', $key, $this->storageKeys());
        }

        return $this->storages->get($key);
    }

    public function builder(string $key): DocumentBuilder
    {
        if (!$this->builders->has($key)) {
            throw ReadStoreException::notRegistered('Document builder', $key, array_map(strval(...), array_keys($this->builders->getProvidedServices())));
        }

        return $this->builders->get($key);
    }

    /**
     * @return list<string>
     */
    public function storageKeys(): array
    {
        return array_map(strval(...), array_keys($this->storages->getProvidedServices()));
    }
}
