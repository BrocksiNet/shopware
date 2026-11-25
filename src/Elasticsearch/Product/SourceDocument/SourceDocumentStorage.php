<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product\SourceDocument;

use OpenSearch\Client;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;
use Shopware\Elasticsearch\Framework\Indexing\ElasticsearchIndexer;

/**
 * Wave 10 O1a: lets the read store projector keep the `_source` document ({@see SourceDocument::FIELD}) of the
 * Shopware product index current.
 *
 * `store` re-indexes the changed products through the Shopware indexer, which writes the search fields and the
 * document in one request.
 * A partial `update` of only the document field is not possible: the index keeps only `id`, `autoIncrement` and the
 * document in `_source`, and OpenSearch rebuilds an updated document from `_source`, so every search field of the
 * product would be lost (seen in wave 10: 516 of 4140 products dropped out of every listing after the miss probes).
 * The projector's own document is therefore only the trigger, the indexer builds the stored one.
 *
 * The index itself belongs to `es:index` (generations, alias swap, the search fields), so `setup`, `swap`,
 * `clear` and `destroy` do nothing here.
 * `get` and `mget` are realtime in OpenSearch (they read the translog), so the projector's write is visible to the
 * detail page at once; search hits see it after the next refresh.
 *
 * @internal proof of concept, not part of the public API
 */
#[Package('framework')]
final readonly class SourceDocumentStorage implements Storage
{
    public function __construct(
        private Client $client,
        private ElasticsearchHelper $helper,
        private ProductDefinition $productDefinition,
        private ElasticsearchIndexer $indexer,
    ) {
    }

    public function setup(string $generation): void
    {
    }

    public function swap(string $generation): void
    {
    }

    public function liveGeneration(): string
    {
        return $this->index();
    }

    public function store(array $documents, ?string $generation = null): void
    {
        $ids = array_values(array_map(static fn (array $document): string => strtolower((string) $document['id']), $documents));
        if ($ids !== []) {
            $this->indexer->updateIds($this->productDefinition, $ids);
        }
    }

    public function remove(array $ids): void
    {
        $index = $this->index();
        $lines = [];
        foreach ($ids as $id) {
            $lines[] = json_encode(['delete' => ['_index' => $index, '_id' => strtolower($id)]], \JSON_THROW_ON_ERROR);
        }

        $this->bulk($lines);
    }

    public function get(string $id): ?array
    {
        return $this->mget([$id])[strtolower($id)] ?? null;
    }

    public function mget(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $response = $this->client->mget([
            'index' => $this->index(),
            'body' => ['ids' => array_map(strtolower(...), $ids)],
            '_source_includes' => [SourceDocument::FIELD],
        ]);

        $documents = [];
        foreach ($response['docs'] ?? [] as $doc) {
            $document = $doc['_source'][SourceDocument::FIELD] ?? null;
            if (($doc['found'] ?? false) === true && \is_array($document)) {
                $documents[(string) $doc['_id']] = $document;
            }
        }

        return $documents;
    }

    public function clear(): void
    {
    }

    public function destroy(): void
    {
    }

    private function index(): string
    {
        return $this->helper->getIndexName($this->productDefinition);
    }

    /**
     * @param list<string> $lines
     */
    private function bulk(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $response = $this->client->bulk(['body' => implode("\n", $lines) . "\n"]);
        if (($response['errors'] ?? false) !== true) {
            return;
        }

        foreach ($response['items'] ?? [] as $item) {
            $result = reset($item);
            if (!\is_array($result) || !isset($result['error'])) {
                continue;
            }
            $type = (string) ($result['error']['type'] ?? 'error');
            // already deleted
            if ((int) ($result['status'] ?? 0) === 404) {
                continue;
            }

            throw ReadStoreException::invalidCriteria(\sprintf('OpenSearch rejected a source document: %s %s', $type, $result['error']['reason'] ?? ''));
        }
    }
}
