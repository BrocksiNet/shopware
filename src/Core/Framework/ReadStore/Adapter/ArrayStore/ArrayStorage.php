<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\ArrayStore;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\AggregationAware;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * Reference adapter: documents in PHP arrays, every query answered by the {@see QueryEvaluator}.
 *
 * With a `$directory`, every generation is persisted as `array-<generation>.json` and the live generation in `array-live.txt`, so a rebuild
 * command and a bench process share the data (each process loads it once and reloads when the live pointer changed). Not for production:
 * it scans all documents per query.
 *
 * @internal
 */
#[Package('framework')]
final class ArrayStorage implements Storage, FilterAware, AggregationAware, SearchAware
{
    /**
     * @var array<string, array<string, array<string, mixed>>> generation => id => document
     */
    private array $generations = [];

    private ?string $live = null;

    private ?string $loadedPointer = null;

    public function __construct(
        private readonly ?string $directory = null,
        private readonly QueryEvaluator $evaluator = new QueryEvaluator(),
    ) {
    }

    public function setup(string $generation): void
    {
        $this->load();
        $this->generations[$generation] = [];
        $this->persist($generation);
    }

    public function swap(string $generation): void
    {
        $this->load();
        if (!isset($this->generations[$generation])) {
            throw ReadStoreException::unknownGeneration($generation);
        }

        $this->persist($generation);
        $old = $this->live;
        $this->live = $generation;
        $this->persistPointer();
        if ($old !== null && $old !== $generation) {
            unset($this->generations[$old]);
            $this->unlink($old);
            clearstatcache();
            $this->loadedPointer = $this->signature();
        }
    }

    public function liveGeneration(): ?string
    {
        $this->load();

        return $this->live;
    }

    public function store(array $documents, ?string $generation = null): void
    {
        $this->load();
        foreach ($this->targets($generation) as $target) {
            foreach ($documents as $document) {
                $this->generations[$target][(string) $document['id']] = $document;
            }
            // a rebuild fills a pending generation in many batches, it is written once at swap()
            if ($generation === null) {
                $this->persist($target);
            }
        }
    }

    public function remove(array $ids): void
    {
        $this->load();
        foreach ($this->targets(null) as $target) {
            foreach ($ids as $id) {
                unset($this->generations[$target][$id]);
            }
            $this->persist($target);
        }
    }

    public function get(string $id): ?array
    {
        return $this->live()[$id] ?? null;
    }

    public function mget(array $ids): array
    {
        $live = $this->live();
        $documents = [];
        foreach ($ids as $id) {
            if (isset($live[$id])) {
                $documents[$id] = $live[$id];
            }
        }

        return $documents;
    }

    public function clear(): void
    {
        $this->load();
        if ($this->live !== null) {
            $this->generations[$this->live] = [];
            $this->persist($this->live);
        }
    }

    public function destroy(): void
    {
        $this->load();
        foreach (array_keys($this->generations) as $generation) {
            $this->unlink((string) $generation);
        }
        $this->generations = [];
        $this->live = null;
        if ($this->directory !== null && is_file($this->pointerFile())) {
            unlink($this->pointerFile());
        }
        $this->loadedPointer = null;
    }

    public function filter(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search !== null) {
            throw NotSupportedByEngine::feature('array', 'a search term in filter(), use search()');
        }

        return $this->evaluator->evaluate($criteria, $context, $this->live());
    }

    public function search(StoreCriteria $criteria, StoreContext $context): StoreResult
    {
        if ($criteria->search === null) {
            throw ReadStoreException::invalidCriteria('search() needs a search term');
        }

        return $this->evaluator->evaluate($criteria, $context, $this->live());
    }

    public function aggregate(StoreCriteria $criteria, StoreContext $context): array
    {
        $aggregationsOnly = clone $criteria;
        $aggregationsOnly->postFilters = [];
        $aggregationsOnly->groupField = null;
        $aggregationsOnly->sortings = [];
        $aggregationsOnly->limit = 0;

        return $this->evaluator->evaluate($aggregationsOnly, $context, $this->live())->aggregations;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function live(): array
    {
        $this->load();

        return $this->live === null ? [] : ($this->generations[$this->live] ?? []);
    }

    /**
     * @return list<string>
     */
    private function targets(?string $generation): array
    {
        if ($generation !== null) {
            if (!isset($this->generations[$generation])) {
                throw ReadStoreException::unknownGeneration($generation);
            }

            return [$generation];
        }

        if ($this->live === null) {
            throw ReadStoreException::noLiveGeneration();
        }

        // the live and every pending generation
        return array_map(strval(...), array_keys($this->generations));
    }

    private function load(): void
    {
        if ($this->directory === null) {
            return;
        }

        $signature = $this->signature();
        if ($signature === $this->loadedPointer) {
            return;
        }

        $pointer = is_file($this->pointerFile()) ? trim((string) file_get_contents($this->pointerFile())) : '';
        $files = glob($this->directory . '/array-*.json') ?: [];

        $this->generations = [];
        foreach ($files as $file) {
            $generation = substr(basename($file, '.json'), \strlen('array-'));
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            $this->generations[$generation] = \is_array($data) ? $data : [];
        }
        $this->live = $pointer === '' ? null : $pointer;
        $this->loadedPointer = $signature;
    }

    private function persist(string $generation): void
    {
        if ($this->directory === null) {
            return;
        }

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }
        file_put_contents($this->file($generation), json_encode((object) ($this->generations[$generation] ?? []), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), \LOCK_EX);
        clearstatcache();
        // this process wrote the file, its memory is current
        $this->loadedPointer = $this->signature();
    }

    private function persistPointer(): void
    {
        if ($this->directory === null) {
            return;
        }

        file_put_contents($this->pointerFile(), (string) $this->live, \LOCK_EX);
        clearstatcache();
        $this->loadedPointer = $this->signature();
    }

    /**
     * Changes when another process swapped or wrote a generation.
     */
    private function signature(): string
    {
        $pointer = is_file($this->pointerFile()) ? trim((string) file_get_contents($this->pointerFile())) : '';
        $files = glob($this->directory . '/array-*.json') ?: [];

        return $pointer . '|' . implode(',', array_map(static fn (string $file): string => $file . '@' . filemtime($file) . ':' . filesize($file), $files));
    }

    private function unlink(string $generation): void
    {
        if ($this->directory !== null && is_file($this->file($generation))) {
            unlink($this->file($generation));
        }
    }

    private function file(string $generation): string
    {
        return $this->directory . '/array-' . $generation . '.json';
    }

    private function pointerFile(): string
    {
        return $this->directory . '/array-live.txt';
    }
}
