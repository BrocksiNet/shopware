<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Framework\Log\Package;

/**
 * Key-value side of a read store: documents are plain arrays with a lower case hex `id`, the layout is {@see \Shopware\Core\Framework\ReadStore\Document\ProductDocument}.
 *
 * Generations make blue/green rebuilds possible: `setup()` creates an empty, not yet live generation, the rebuild fills it with
 * `store($documents, $generation)`, `swap()` makes it live and drops the old one. While a generation is pending, `store()` and
 * `remove()` without a generation write to the live and the pending generation, so the projector keeps both current.
 * Every adapter persists which generation is live (SQL meta table, OpenSearch alias, Valkey pointer key, ...), so a second process sees the swap.
 *
 * @internal
 */
#[Package('framework')]
interface Storage
{
    /**
     * Creates an empty generation that is not live yet. Creates the store itself (tables, indices, collections) if it does not exist.
     */
    public function setup(string $generation): void;

    /**
     * Makes the generation live and drops the previously live one.
     */
    public function swap(string $generation): void;

    /**
     * The live generation, `null` before the first `swap()`.
     */
    public function liveGeneration(): ?string;

    /**
     * @param list<array<string, mixed>> $documents upsert by `id`
     * @param string|null $generation `null` writes to the live and any pending generation
     */
    public function store(array $documents, ?string $generation = null): void;

    /**
     * @param list<string> $ids
     */
    public function remove(array $ids): void;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array;

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>> keyed by id, in the order of `$ids`, missing ids are left out
     */
    public function mget(array $ids): array;

    /**
     * Removes all documents of the live generation.
     */
    public function clear(): void;

    /**
     * Drops every generation and the store itself.
     */
    public function destroy(): void;
}
