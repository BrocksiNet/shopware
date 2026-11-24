<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product\SourceDocument;

use Shopware\Core\Framework\Log\Package;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands the documents of the search hits from {@see \Shopware\Elasticsearch\Framework\DataAbstractionLayer\ElasticsearchEntitySearchHydrator}
 * to {@see SourceDocumentEntityReader}: the repository searches ids first and reads the entities in a second call,
 * so the documents of the hits are kept here for the read that follows in the same request.
 * Reset after every request (`kernel.reset`), bounded so a long worker cannot grow it.
 *
 * @internal
 */
#[Package('framework')]
final class SourceDocumentBuffer implements ResetInterface
{
    private const MAX = 2000;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $documents = [];

    /**
     * @param array<string, mixed> $document
     */
    public function add(string $id, array $document): void
    {
        if (\count($this->documents) >= self::MAX) {
            $this->documents = [];
        }

        $this->documents[strtolower($id)] = $document;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>> keyed by lower case id, ids without a buffered document are left out
     */
    public function take(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $key = strtolower($id);
            if (isset($this->documents[$key])) {
                $found[$key] = $this->documents[$key];
            }
        }

        return $found;
    }

    public function reset(): void
    {
        $this->documents = [];
    }
}
