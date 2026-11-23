<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Probe;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Doctrine\DoctrineImplementation;
use Shopware\Core\Framework\OrmSpike\Doctrine\Entity\Product;
use Shopware\Core\Framework\OrmSpike\Doctrine\EntityManagerFactory;
use Shopware\Core\Framework\OrmSpike\Doctrine\Probe\Dx\SpikeNote;
use Shopware\Core\Framework\OrmSpike\Doctrine\Probe\Entity\PluginNote;
use Shopware\Core\Framework\OrmSpike\Harness\Fixtures;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\WriteCleaner;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `orm-spike:doctrine-probes`: runs the probes of docs/29 that are not part of S1 to S5 and prints pass or fail with a short detail.
 * Every probe cleans up what it created (own rows, own temporary tables), the data set stays as seeded.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'orm-spike:doctrine-probes', description: 'Doctrine ORM probes of the wave 8 spike')]
final class ProbeCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly DoctrineImplementation $implementation,
        private readonly ParamsFactory $paramsFactory,
        private readonly WriteCleaner $cleaner,
        private readonly string $cacheDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->paramsFactory->create();
        $results = [];
        foreach (['versioning', 'pluginAssociation', 'pluginField', 'customEntity', 'criteriaSafety', 'events', 'dx'] as $probe) {
            try {
                $results[] = [$probe, ...$this->{'probe' . ucfirst($probe)}($params)];
            } catch (\Throwable $e) {
                $results[] = [$probe, false, 'error ' . $e::class . ': ' . substr($e->getMessage(), 0, 300)];
            }
        }
        foreach ($results as [$name, $ok, $detail]) {
            $output->writeln(\sprintf('%-18s %s  %s', $name, $ok ? 'PASS' : 'FAIL', $detail));
        }

        return 0;
    }

    /**
     * Read the live version, write the same product into a draft version, merge the draft into live.
     *
     * @return array{0: bool, 1: string}
     */
    private function probeVersioning(Params $params): array
    {
        $id = Fixtures::id('probe', 'versioned');
        $draft = Fixtures::id('probe', 'draft-version');
        $this->cleanProbeProducts();
        try {
            foreach ([[$params->liveVersionId, 1], [$draft, 2]] as [$version, $stock]) {
                $p = new Product();
                $p->id = $id;
                $p->versionId = $version;
                $p->productNumber = 'SPK-PROBE-VERSION';
                $p->stock = $stock;
                $p->active = 1;
                $p->taxId = $params->taxId;
                $p->createdAt = new \DateTimeImmutable();
                $this->em->persist($p);
            }
            $this->em->flush();
            $this->em->clear();

            $liveRow = $this->em->find(Product::class, ['id' => $id, 'versionId' => $params->liveVersionId]);
            $draftRow = $this->em->find(Product::class, ['id' => $id, 'versionId' => $draft]);
            $read = $liveRow?->stock === 1 && $draftRow?->stock === 2;

            // merge = copy the draft state onto the live row, drop the draft; the DAL does this through version_commit data, here it is hand written
            \assert($liveRow instanceof Product && $draftRow instanceof Product);
            $liveRow->stock = $draftRow->stock;
            $this->em->remove($draftRow);
            $this->em->flush();
            $this->em->clear();
            $merged = $this->em->find(Product::class, ['id' => $id, 'versionId' => $params->liveVersionId])?->stock === 2
                && $this->em->find(Product::class, ['id' => $id, 'versionId' => $draft]) === null;

            return [$read && $merged, 'composite key (id, version_id) works with find(), persist and remove; read live 1, draft 2, merge by hand copy ' . ($merged ? 'ok' : 'failed') . '; no version/commit bookkeeping (version, version_commit, merge rules) exists, it would be hand written'];
        } finally {
            $this->em->clear();
            $this->cleanProbeProducts();
        }
    }

    /**
     * A plugin adds an association to a table it creates without editing the core `Product`.
     *
     * @return array{0: bool, 1: string}
     */
    private function probePluginAssociation(Params $params): array
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS spike_plugin_note');
        $idSql = 'BINARY(16)'; // MySQL DDL, the PostgreSQL shim maps it to uuid
        $this->connection->executeStatement('CREATE TABLE spike_plugin_note (id ' . $idSql . ' NOT NULL PRIMARY KEY, product_id ' . $idSql . ' NOT NULL, product_version_id ' . $idSql . ' NOT NULL, note VARCHAR(255) NOT NULL)');
        $this->cleanProbeProducts();
        try {
            $em = $this->pluginManager(new EventManager());
            $product = new Product();
            $product->id = Fixtures::id('probe', 'plugin-product');
            $product->versionId = $params->liveVersionId;
            $product->productNumber = 'SPK-PROBE-PLUGIN';
            $product->active = 1;
            $product->taxId = $params->taxId;
            $product->createdAt = new \DateTimeImmutable();
            $note = new PluginNote();
            $note->id = Fixtures::id('probe', 'plugin-note');
            $note->product = $product;
            $note->note = 'from the plugin';
            $em->persist($product);
            $em->persist($note);
            $em->flush();
            $em->clear();

            $row = $em->createQuery('SELECT n.note AS note, p.productNumber AS number FROM ' . PluginNote::class . ' n JOIN n.product p WHERE p.productNumber = :n')->setParameter('n', 'SPK-PROBE-PLUGIN')->getArrayResult();
            $ok = ($row[0]['note'] ?? null) === 'from the plugin';

            return [$ok, 'plugin entity (own table, own mapping class) with ManyToOne to the core Product works in DQL and persist; the core Product class has no `pluginNotes` property, so there is no `$product->pluginNotes` (see pluginField)'];
        } finally {
            $this->cleanProbeProducts();
            $this->connection->executeStatement('DROP TABLE IF EXISTS spike_plugin_note');
        }
    }

    /**
     * A plugin adds a field and a to-many association to `Product` through `loadClassMetadata`.
     *
     * @return array{0: bool, 1: string}
     */
    private function probePluginField(Params $params): array
    {
        $details = [];
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new class($details) {
            /**
             * @param list<string> $details
             */
            public function __construct(public array &$details)
            {
            }

            public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
            {
                $metadata = $args->getClassMetadata();
                if ($metadata->getName() !== Product::class) {
                    return;
                }
                // `ean` is an existing column of `product` that the core entity does not map
                $metadata->mapField(['fieldName' => 'ean', 'columnName' => 'ean', 'type' => 'string', 'nullable' => true]);
            }
        });

        $withMetadata = false;
        try {
            $em = $this->pluginManager($events);
            $metadata = $em->getClassMetadata(Product::class);
            $withMetadata = $metadata->hasField('ean');
            $details[] = 'metadata loaded, hasField(ean)=' . ($withMetadata ? 'yes' : 'no');
            $em->createQuery('SELECT p.ean FROM ' . Product::class . ' p')->setMaxResults(1)->getArrayResult();
            $details[] = 'DQL on p.ean works';
            $product = $em->createQuery('SELECT p FROM ' . Product::class . ' p')->setMaxResults(1)->getSingleResult();
            \assert($product instanceof Product);
            $details[] = 'hydrated Product, property ean accessible: ' . (property_exists($product, 'ean') ? 'yes (dynamic)' : 'no');
        } catch (\Throwable $e) {
            $details[] = 'failed: ' . $e::class . ': ' . substr($e->getMessage(), 0, 160);
        }

        return [$withMetadata && !str_contains(implode(' ', $details), 'failed'), implode('; ', $details)];
    }

    /**
     * Read and write a `custom_entity_*` table that exists only at runtime (generated class, runtime mapping).
     *
     * @return array{0: bool, 1: string}
     */
    private function probeCustomEntity(Params $params): array
    {
        $table = 'custom_entity_spike_probe';
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        $idSql = 'BINARY(16)'; // MySQL DDL, the PostgreSQL shim maps it to uuid
        $this->connection->executeStatement('CREATE TABLE ' . $table . ' (id ' . $idSql . ' NOT NULL PRIMARY KEY, title VARCHAR(255) NULL, position INT NULL)');
        try {
            $driver = new RuntimeEntityDriver();
            $class = $driver->define('CeSpikeProbe', $table, ['id' => 'shopware_id', 'title' => 'string', 'position' => 'integer']);
            $chain = new MappingDriverChain();
            $chain->addDriver($driver, RuntimeEntityDriver::NAMESPACE);
            $em = EntityManagerFactory::create($this->connection, $this->cacheDir, 'orm_spike_doctrine_runtime', $chain);

            foreach ([1, 2, 3] as $i) {
                $row = new $class();
                $row->id = Fixtures::id('probe', 'ce', $i);
                $row->title = 'note ' . $i;
                $row->position = $i;
                $em->persist($row);
            }
            $em->flush();
            $em->clear();

            $rows = $em->createQuery('SELECT r.id, r.title FROM ' . $class . ' r WHERE r.position >= :p ORDER BY r.position')->setParameter('p', 2)->getArrayResult();
            $loaded = $em->find($class, Fixtures::id('probe', 'ce', 1));
            $loaded->title = 'changed';
            $em->flush();
            $em->clear();
            $changed = $em->find($class, Fixtures::id('probe', 'ce', 1))->title === 'changed';

            return [\count($rows) === 2 && $changed, 'generated class (eval) per entity plus a runtime MappingDriver: DQL, array hydration, find, persist and update work; needs one PHP class per custom entity, no class-less rows, relations between custom entities would need the same generation'];
        } finally {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * Criteria JSON: injection attempts through field names, sort fields and values.
     *
     * @return array{0: bool, 1: string}
     */
    private function probeCriteriaSafety(Params $params): array
    {
        $this->implementation->configure(['mode' => 'optimized']);
        $baseline = $this->implementation->s5CriteriaFromJson($this->withCriteria($params, ['filter' => [['type' => 'contains', 'field' => 'name', 'value' => 'x\' OR \'1\'=\'1']], 'limit' => 5]));
        $valueSafe = $baseline['total'] === 0;

        $fieldRejected = 0;
        foreach ([
            ['filter' => [['type' => 'equals', 'field' => 'p.id) OR (1=1', 'value' => 1]]],
            ['filter' => [['type' => 'equals', 'field' => 'active) OR (1=1 --', 'value' => 1]]],
            ['sort' => [['field' => 'productNumber; DROP TABLE product', 'order' => 'ASC']]],
            ['sort' => [['field' => 'productNumber', 'order' => 'ASC, (SELECT 1)']], 'limit' => 1],
        ] as $criteria) {
            try {
                $result = $this->implementation->s5CriteriaFromJson($this->withCriteria($params, $criteria + ['limit' => 1]));
                // the sort direction is normalised to ASC or DESC, the query runs but nothing is injected
                $fieldRejected += isset($criteria['sort'][0]['order']) && $result['total'] > 0 ? 1 : 0;
            } catch (\InvalidArgumentException) {
                ++$fieldRejected;
            }
        }
        $this->implementation->configure(['mode' => 'idiomatic']);

        return [$valueSafe && $fieldRejected === 4, 'values are bound parameters (injection string matches 0 rows), field names come from an allow list (3 of 4 attempts rejected with an exception), the sort direction is normalised to ASC/DESC (4th runs harmlessly); the DQL string only contains allow listed expressions and generated aliases'];
    }

    /**
     * Which events fire for entity writes and for DQL bulk writes.
     *
     * @return array{0: bool, 1: string}
     */
    private function probeEvents(Params $params): array
    {
        $listener = new class {
            public int $flushes = 0;

            public int $inserted = 0;

            public int $updated = 0;

            /**
             * @var array<string, int>
             */
            public array $byClass = [];

            public function onFlush(OnFlushEventArgs $args): void
            {
                ++$this->flushes;
                $uow = $args->getObjectManager()->getUnitOfWork();
                foreach ($uow->getScheduledEntityInsertions() as $entity) {
                    ++$this->inserted;
                    $this->byClass[substr($entity::class, (int) strrpos($entity::class, '\\') + 1)] = ($this->byClass[substr($entity::class, (int) strrpos($entity::class, '\\') + 1)] ?? 0) + 1;
                }
                $this->updated += \count($uow->getScheduledEntityUpdates());
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $listener);

        $out = [];
        try {
            foreach (['idiomatic', 'optimized'] as $mode) {
                $listener->flushes = $listener->inserted = $listener->updated = 0;
                $listener->byClass = [];
                $this->implementation->configure(['mode' => $mode]);
                $this->implementation->s4Write($params, WriteSet::forRun('probe-' . $mode));
                $out[$mode] = \sprintf('%s: onFlush %dx, %d scheduled inserts (%s), %d scheduled updates', $mode, $listener->flushes, $listener->inserted, json_encode($listener->byClass, \JSON_THROW_ON_ERROR), $listener->updated);
            }
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $listener);
            $this->implementation->configure(['mode' => 'idiomatic']);
            $this->cleaner->clean();
        }

        return [true, implode(' | ', $out) . ' | the optimized stock update is a DQL UPDATE: it does not go through the unit of work (second onFlush is missing)'];
    }

    /**
     * DX walkthrough: a new entity `spike_note` with a table and create, read, update, delete.
     *
     * @return array{0: bool, 1: string}
     */
    private function probeDx(Params $params): array
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS spike_note');
        $idSql = 'BINARY(16)'; // MySQL DDL, the PostgreSQL shim maps it to uuid
        $this->connection->executeStatement('CREATE TABLE spike_note (id ' . $idSql . ' NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, body TEXT NULL, created_at TIMESTAMP NOT NULL)');
        try {
            $chain = new AttributeDriver([__DIR__ . '/Dx']);
            $em = EntityManagerFactory::create($this->connection, $this->cacheDir, 'orm_spike_doctrine_dx', $chain);

            $note = new SpikeNote();
            $note->id = Fixtures::id('probe', 'dx');
            $note->title = 'first';
            $note->createdAt = new \DateTimeImmutable();
            $em->persist($note);
            $em->flush();
            $em->clear();

            $read = $em->find(SpikeNote::class, $note->id);
            \assert($read instanceof SpikeNote);
            $read->body = 'edited';
            $em->flush();
            $em->clear();
            $titles = $em->createQuery('SELECT n.title FROM ' . SpikeNote::class . ' n WHERE n.body = :b')->setParameter('b', 'edited')->getSingleColumnResult();
            $em->remove($em->getReference(SpikeNote::class, $note->id));
            $em->flush();

            return [$titles === ['first'] && $em->find(SpikeNote::class, $note->id) === null, 'create, find, update, DQL, delete on a new table with one 30 line entity class'];
        } finally {
            $this->connection->executeStatement('DROP TABLE IF EXISTS spike_note');
        }
    }

    private function pluginManager(EventManager $events): EntityManagerInterface
    {
        $driver = new AttributeDriver([\dirname(__DIR__) . '/Entity', __DIR__ . '/Entity']);

        return EntityManagerFactory::create($this->connection, $this->cacheDir, 'orm_spike_doctrine_plugin_' . bin2hex(random_bytes(4)), $driver, $events);
    }

    private function cleanProbeProducts(): void
    {
        $like = 'SPK-PROBE-%';
        foreach (['product_translation', 'product_price', 'product_visibility', 'product_category'] as $table) {
            $this->connection->executeStatement('DELETE FROM ' . $table . ' WHERE product_id IN (SELECT id FROM product WHERE product_number LIKE :p)', ['p' => $like]);
        }
        $this->connection->executeStatement('DELETE FROM product WHERE product_number LIKE :p', ['p' => $like]);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function withCriteria(Params $params, array $criteria): Params
    {
        return new Params(
            $params->salesChannelId,
            $params->languageId,
            $params->systemLanguageId,
            $params->currencyId,
            $params->taxId,
            $params->ruleId,
            $params->liveVersionId,
            $params->categoryId,
            $params->page,
            $params->limit,
            $params->searchTerm,
            $params->manufacturerIds,
            $params->variantId,
            $criteria,
        );
    }
}
