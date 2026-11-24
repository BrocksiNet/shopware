<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Valkey;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * Key-value adapter on Valkey (or Redis) with the `redis` PHP extension. It implements {@see Storage} only: Valkey has no query language
 * for the document layout, so every `filter()`, `aggregate()` and `search()` goes to another engine (the composed OpenSearch + Valkey store
 * resolves ids there and hydrates with {@see self::mget()}) or to the DAL.
 *
 * Keys (all below the prefix, which always starts with `rs_`):
 *
 * - `<prefix>:live`: the live generation (pointer key, so every process sees a swap)
 * - `<prefix>:gens`: set of all generations
 * - `<prefix>:doc:<generation>:<id>`: the document as JSON
 * - `<prefix>:ids:<generation>`: set of the ids of the generation, to clear and drop it without a keyspace scan
 *
 * The database index is fixed to 5, the lane uses the other ones. Nothing here flushes a database, only keys below the prefix are deleted.
 *
 * @internal
 */
#[Package('framework')]
class ValkeyStorage implements Storage
{
    public const DATABASE = 5;

    private const BATCH = 500;

    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $host = 'instance-valkey-1',
        private readonly int $port = 6379,
        private readonly string $prefix = 'rs_store',
    ) {
        if (!str_starts_with($prefix, 'rs_')) {
            throw ReadStoreException::invalidCriteria('the Valkey key prefix must start with rs_');
        }
    }

    public function setup(string $generation): void
    {
        $this->client()->sAdd($this->key('gens'), $generation);
    }

    public function swap(string $generation): void
    {
        $redis = $this->client();
        if (!$redis->sismember($this->key('gens'), $generation)) {
            throw ReadStoreException::unknownGeneration($generation);
        }

        $old = $this->liveGeneration();
        $redis->set($this->key('live'), $generation);

        if ($old !== null && $old !== $generation) {
            $this->dropGeneration($old);
        }
    }

    public function liveGeneration(): ?string
    {
        $live = $this->client()->get($this->key('live'));

        return \is_string($live) ? $live : null;
    }

    public function store(array $documents, ?string $generation = null): void
    {
        if ($documents === []) {
            return;
        }

        $targets = $generation !== null ? [$generation] : $this->writeTargets();
        $redis = $this->client();

        foreach (array_chunk($documents, self::BATCH) as $chunk) {
            $pipeline = $redis->multi(\Redis::PIPELINE);
            \assert($pipeline instanceof \Redis);
            foreach ($targets as $target) {
                $ids = [];
                foreach ($chunk as $document) {
                    $id = (string) $document['id'];
                    $ids[] = $id;
                    $pipeline->set($this->documentKey($target, $id), json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
                }
                $pipeline->sAdd($this->key('ids:' . $target), ...$ids);
            }
            $pipeline->exec();
        }
    }

    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $targets = $this->writeTargets();
        $pipeline = $this->client()->multi(\Redis::PIPELINE);
        \assert($pipeline instanceof \Redis);
        foreach ($targets as $target) {
            $pipeline->unlink(...array_map(fn (string $id): string => $this->documentKey($target, $id), $ids));
            $pipeline->srem($this->key('ids:' . $target), ...$ids);
        }
        $pipeline->exec();
    }

    public function get(string $id): ?array
    {
        $value = $this->client()->get($this->documentKey($this->requireLive(), $id));

        return \is_string($value) ? $this->decode($value) : null;
    }

    public function mget(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $live = $this->requireLive();
        $values = $this->client()->mget(array_map(fn (string $id): string => $this->documentKey($live, $id), $ids));

        $documents = [];
        foreach ($ids as $position => $id) {
            $value = \is_array($values) ? ($values[$position] ?? false) : false;
            if (\is_string($value)) {
                $documents[$id] = $this->decode($value);
            }
        }

        return $documents;
    }

    public function clear(): void
    {
        $live = $this->requireLive();
        $this->deleteDocuments($live);
    }

    public function destroy(): void
    {
        $redis = $this->client();
        foreach ($this->generations() as $generation) {
            $this->deleteDocuments($generation);
            $redis->unlink($this->key('ids:' . $generation));
        }
        $redis->unlink($this->key('gens'), $this->key('live'));
    }

    private function client(): \Redis
    {
        if ($this->redis === null) {
            $redis = new \Redis();
            $redis->connect($this->host, $this->port);
            $redis->select(self::DATABASE);
            $this->redis = $redis;
        }

        return $this->redis;
    }

    private function key(string $name): string
    {
        return $this->prefix . ':' . $name;
    }

    private function documentKey(string $generation, string $id): string
    {
        return $this->prefix . ':doc:' . $generation . ':' . $id;
    }

    private function requireLive(): string
    {
        return $this->liveGeneration() ?? throw ReadStoreException::noLiveGeneration();
    }

    /**
     * @return list<string> the live generation and every pending one
     */
    private function writeTargets(): array
    {
        $live = $this->requireLive();
        $targets = [$live];
        foreach ($this->generations() as $generation) {
            if ($generation !== $live) {
                $targets[] = $generation;
            }
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    private function generations(): array
    {
        $members = $this->client()->sMembers($this->key('gens'));

        return \is_array($members) ? array_values(array_map(strval(...), $members)) : [];
    }

    private function dropGeneration(string $generation): void
    {
        $this->deleteDocuments($generation);
        $redis = $this->client();
        $redis->unlink($this->key('ids:' . $generation));
        $redis->srem($this->key('gens'), $generation);
    }

    private function deleteDocuments(string $generation): void
    {
        $redis = $this->client();
        $idsKey = $this->key('ids:' . $generation);

        $iterator = null;
        while (($ids = $redis->sscan($idsKey, $iterator, null, 1000)) !== false) {
            if ($ids !== []) {
                $redis->unlink(...array_map(fn (string $id): string => $this->documentKey($generation, $id), $ids));
                $redis->srem($idsKey, ...$ids);
            }
            if ($iterator === 0) {
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $document;
    }
}
