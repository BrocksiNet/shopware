<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product\SourceDocument;

use OpenSearch\Client;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;

/**
 * Wave 10 O1a: lets the read store projector keep the `_source` document ({@see SourceDocument::FIELD}) of the
 * Shopware product index current, with a partial `update` of that one field per product.
 *
 * The index itself belongs to `es:index` (generations, alias swap, the search fields), so `setup`, `swap`,
 * `clear` and `destroy` do nothing here.
 * A product the index does not hold yet (`document_missing`) is skipped: the search fields would be missing, the
 * Shopware indexer adds it with its document.
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
        $index = $this->index();
        $lines = [];
        foreach ($documents as $document) {
            $lines[] = json_encode(['update' => ['_index' => $index, '_id' => strtolower((string) $document['id'])]], \JSON_THROW_ON_ERROR);
            $lines[] = json_encode(['doc' => [SourceDocument::FIELD => $document]], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        }

        $this->bulk($lines);
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
            // not in the index yet (the Shopware indexer adds it) or already deleted
            if ($type === 'document_missing_exception' || (int) ($result['status'] ?? 0) === 404) {
                continue;
            }

            throw ReadStoreException::invalidCriteria(\sprintf('OpenSearch rejected a source document: %s %s', $type, $result['error']['reason'] ?? ''));
        }
    }
}
