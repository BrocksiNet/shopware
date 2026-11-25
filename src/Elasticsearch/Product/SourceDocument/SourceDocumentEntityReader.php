<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product\SourceDocument;

use OpenSearch\Client;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Read\EntityReaderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Integration\DocumentHydrator;
use Shopware\Core\Framework\ReadStore\Integration\SourceDocumentReaderSwitch;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;

/**
 * Wave 10 O1a: answers the `cloneForRead($ids)` read of sales channel products from the full product document
 * in OpenSearch `_source` (field {@see SourceDocument::FIELD}) instead of the SQL entity reader.
 *
 * Documents come from the search hits of the same request ({@see SourceDocumentBuffer}) or, for a read without a
 * search (detail by id), from one `_mget` on the product index.
 * `SalesChannelRepository::read()` dispatches the loaded events on the returned entities as before,
 * so live prices and purchase limits are computed at display time as in wave 9 shape (a).
 *
 * Anything the document cannot answer goes to the inner (SQL) reader unchanged: criteria with filters, sortings,
 * aggregations or partial fields, a non-live version, associations the document does not hold, ids without a document.
 *
 * @internal proof of concept, not part of the public API
 */
#[Package('framework')]
final class SourceDocumentEntityReader implements EntityReaderInterface, SourceDocumentReaderSwitch
{
    /**
     * Associations the wave 9 `DocumentHydrator` fills from the document (with their nested paths).
     */
    private const DOCUMENT_ASSOCIATIONS = [
        'manufacturer' => [],
        'tax' => [],
        'prices' => [],
        'unit' => [],
        'deliveryTime' => [],
        'cover' => ['media' => []],
        'media' => ['media' => []],
        'options' => ['group' => []],
        'properties' => ['group' => []],
    ];

    /**
     * Many-to-many associations whose ids are in the document: the referenced entities are read by id (no product SQL).
     */
    private const ID_ASSOCIATIONS = [
        'categories' => 'categoryIds',
        'tags' => 'tagIds',
    ];

    private bool $runtimeEnabled = true;

    /**
     * @var array<string, int>
     */
    private array $stats = [];

    public function __construct(
        private readonly EntityReaderInterface $inner,
        private readonly SourceDocumentBuffer $buffer,
        private readonly Client $client,
        private readonly ElasticsearchHelper $helper,
        private readonly ProductDefinition $productDefinition,
        private readonly ?DocumentHydrator $hydrator,
        private readonly bool $enabled,
    ) {
    }

    /**
     * Bench switch: compare O1 (SQL read) and O1a (document read) in one process.
     */
    public function setRuntimeEnabled(bool $enabled): void
    {
        $this->runtimeEnabled = $enabled;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = [];
    }

    public function read(EntityDefinition $definition, Criteria $criteria, Context $context): EntityCollection
    {
        if (!$this->enabled || !$this->runtimeEnabled || $this->hydrator === null || !$definition instanceof SalesChannelProductDefinition) {
            return $this->inner->read($definition, $criteria, $context);
        }

        $reason = $this->unsupported($criteria, $context);
        if ($reason !== null) {
            return $this->fallback($reason, $definition, $criteria, $context);
        }

        $ids = array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), $criteria->getIds()));
        $documents = $this->buffer->take($ids);
        $missing = array_values(array_diff($ids, array_keys($documents)));
        if ($missing !== []) {
            $this->count('mget');
            $documents += $this->mget($missing);
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (!isset($documents[$id])) {
                // e.g. a product without visibilities, which the product indexer does not index
                return $this->fallback('missing-document', $definition, $criteria, $context);
            }
            $ordered[] = $documents[$id];
        }

        $this->count('document');
        $entities = $this->hydrator->hydrateEntitiesWithoutEvents($ordered, $context->getLanguageId(), $criteria);

        foreach (self::ID_ASSOCIATIONS as $association => $idField) {
            if ($criteria->hasAssociation($association)) {
                $this->loadByIds($definition, $criteria, $context, $association, $idField, $ordered, $entities);
            }
        }

        foreach ($entities as $entity) {
            $this->stamp($entity, $definition);
        }

        $collection = $definition->getCollectionClass();
        /** @var EntityCollection<Entity> $result */
        $result = new $collection($entities);

        return $result;
    }

    private function unsupported(Criteria $criteria, Context $context): ?string
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return 'version';
        }
        if ($criteria->getIds() === []) {
            return 'no-ids';
        }
        foreach ($criteria->getIds() as $id) {
            if (!\is_string($id)) {
                return 'mapping-ids';
            }
        }
        if ($criteria->getFilters() !== [] || $criteria->getPostFilters() !== [] || $criteria->getQueries() !== []
            || $criteria->getTerm() !== null || $criteria->getSorting() !== [] || $criteria->getGroupFields() !== []
            || $criteria->getAggregations() !== [] || $criteria->getFields() !== [] || $criteria->getLimit() !== null) {
            return 'criteria';
        }

        $supported = self::DOCUMENT_ASSOCIATIONS + array_fill_keys(array_keys(self::ID_ASSOCIATIONS), null);
        foreach ($criteria->getAssociations() as $name => $nested) {
            if (!\array_key_exists($name, $supported)) {
                return 'association:' . $name;
            }
            if (\is_array($supported[$name]) && !$this->plain($nested, $supported[$name])) {
                return 'association:' . $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $allowed
     */
    private function plain(Criteria $criteria, array $allowed): bool
    {
        if ($criteria->getFilters() !== [] || $criteria->getPostFilters() !== [] || $criteria->getSorting() !== []
            || $criteria->getLimit() !== null || $criteria->getAggregations() !== [] || $criteria->getFields() !== []) {
            return false;
        }
        foreach ($criteria->getAssociations() as $name => $nested) {
            if (!\array_key_exists($name, $allowed) || !\is_array($allowed[$name]) || !$this->plain($nested, $allowed[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return EntityCollection<Entity>
     */
    private function fallback(string $reason, EntityDefinition $definition, Criteria $criteria, Context $context): EntityCollection
    {
        $this->count('fallback:' . $reason);

        return $this->inner->read($definition, $criteria, $context);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function mget(array $ids): array
    {
        $response = $this->client->mget([
            'index' => $this->helper->getIndexName($this->productDefinition),
            'body' => ['ids' => $ids],
            '_source_includes' => [SourceDocument::FIELD],
        ]);

        $documents = [];
        foreach ($response['docs'] ?? [] as $doc) {
            $document = $doc['_source'][SourceDocument::FIELD] ?? null;
            if (($doc['found'] ?? false) === true && \is_array($document)) {
                $documents[strtolower((string) $doc['_id'])] = $document;
            }
        }

        return $documents;
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param list<Entity> $entities
     */
    private function loadByIds(
        EntityDefinition $definition,
        Criteria $criteria,
        Context $context,
        string $association,
        string $idField,
        array $documents,
        array $entities,
    ): void {
        $field = $definition->getFields()->get($association);
        $nested = $criteria->getAssociation($association);
        if (!$field instanceof ManyToManyAssociationField) {
            return;
        }

        $ids = [];
        foreach ($documents as $document) {
            foreach ($document[$idField] ?? [] as $id) {
                $ids[strtolower((string) $id)] = true;
            }
        }

        $referenceDefinition = $field->getToManyReferenceDefinition();
        $read = clone $nested;
        $read->setIds(array_keys($ids));
        $referenced = $ids === [] ? null : $this->inner->read($referenceDefinition, $read, $context);
        $this->count('read:' . $association);

        $collectionClass = $referenceDefinition->getCollectionClass();
        foreach ($entities as $index => $entity) {
            /** @var EntityCollection<Entity> $collection */
            $collection = new $collectionClass();
            foreach ($documents[$index][$idField] ?? [] as $id) {
                $item = $referenced?->get(strtolower((string) $id));
                if ($item instanceof Entity) {
                    $collection->add($item);
                }
            }
            $entity->assign([$association => $collection]);
        }
    }

    /**
     * Sets entity name and field visibility on the hydrated tree, as the DAL hydrator does:
     * `EntityLoadedEventFactory` groups the nested entities by that name for the loaded events,
     * and the Store API serializer hides fields by that visibility.
     */
    private function stamp(Entity $entity, EntityDefinition $definition): void
    {
        if ($entity->getInternalEntityName() !== null) {
            return;
        }
        $entity->internalSetEntityData($definition->getEntityName(), $definition->getFieldVisibility());

        $vars = $entity->getVars();
        foreach ($definition->getFields() as $field) {
            if (!$field instanceof AssociationField) {
                continue;
            }
            $value = $vars[$field->getPropertyName()] ?? null;
            if ($value === null) {
                continue;
            }
            $reference = $field instanceof ManyToManyAssociationField ? $field->getToManyReferenceDefinition() : $field->getReferenceDefinition();
            if ($value instanceof Entity) {
                $this->stamp($value, $reference);
            } elseif ($value instanceof EntityCollection) {
                foreach ($value as $item) {
                    $this->stamp($item, $reference);
                }
            }
        }
    }

    private function count(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }
}
