<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Probe;

use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Mapper\StdMapper;
use Cycle\ORM\ORM;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface as S;
use Cycle\ORM\Select;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Cycle\CycleBootstrap;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\Product;
use Shopware\Core\Framework\OrmSpike\Cycle\HexIdTypecast;
use Shopware\Core\Framework\OrmSpike\Cycle\SchemaFactory;
use Shopware\Core\Framework\OrmSpike\Harness\Fixtures;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Probes of doc 29 that need their own code: versioning, plugin extension, app custom entity, events.
 * Creates the table `spike_probe_note` in the orm_spike database and drops it again, writes a draft version and removes it.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'orm-spike:cycle-probe', description: 'Run the Cycle ORM probes of wave 8 on the current orm_spike database')]
class ProbeCommand extends Command
{
    private const TABLE = 'spike_probe_note';

    public function __construct(private readonly Connection $connection, private readonly StatementCounter $counter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = CycleBootstrap::database($this->connection, $this->counter);
        $productId = Fixtures::variantId(15, 0);
        $draft = md5('cycle-probe-draft');
        $ok = true;
        try {
            $this->createTable($manager);
            foreach (['versioning' => fn () => $this->versioning($manager, $productId, $draft), 'plugin extension' => fn () => $this->plugin($manager, $productId), 'app custom entity' => fn () => $this->custom($manager), 'events' => fn () => $this->events($manager, $productId), 'attribute schema' => fn () => $this->attributes($manager)] as $name => $probe) {
                try {
                    $output->writeln(\sprintf('%s: PASS %s', $name, $probe()));
                } catch (\Throwable $e) {
                    $ok = false;
                    $output->writeln(\sprintf('%s: FAIL %s: %s', $name, $e::class, substr($e->getMessage(), 0, 300) . ' @ ' . implode(' < ', array_map(static fn (array $t): string => basename((string) ($t['file'] ?? '')) . ':' . ($t['line'] ?? ''), \array_slice($e->getTrace(), 0, 6)))));
                }
            }
        } finally {
            $this->connection->executeStatement('DELETE FROM product WHERE version_id = ' . IdBinding::literal($draft));
            $this->connection->executeStatement('DELETE FROM version WHERE id = ' . IdBinding::literal($draft));
            $manager->database()->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The table of the app custom entity is defined at runtime through the schema builder of `cycle/database`.
     */
    private function createTable(DatabaseManager $manager): void
    {
        $manager->database()->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $schema = $manager->database()->table(self::TABLE)->getSchema();
        $schema->string('id', 32)->nullable(false);
        if (SqlDialectFactory::forConnection($this->connection)->getName() === 'postgresql') {
            $schema->uuid('product_id')->nullable(true);
        } else {
            $schema->binary('product_id', 16)->nullable(true);
        }
        $schema->string('note', 100)->nullable(true);
        $schema->setPrimaryKeys(['id']);
        $schema->save();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function noteRoles(): array
    {
        $columns = ['id' => 'id', 'productId' => 'product_id', 'note' => 'note'];
        $typecast = ['productId' => HexIdTypecast::RULE];
        $common = [S::DATABASE => 'default', S::TABLE => self::TABLE, S::PRIMARY_KEY => 'id', S::COLUMNS => $columns, S::TYPECAST => $typecast, S::TYPECAST_HANDLER => [HexIdTypecast::class, Typecast::class], S::RELATIONS => [], S::SCHEMA => []];

        return [
            // app custom entity: no PHP class, StdMapper hands out \stdClass
            'note' => [S::ENTITY => \stdClass::class, S::MAPPER => StdMapper::class] + $common,
            // events: a class with a mapper that reports writes
            'noteEvent' => [S::ENTITY => ProbeNote::class, S::MAPPER => EventMapper::class] + $common,
        ];
    }

    private function orm(DatabaseManager $manager, array $extra = [], bool $extendProduct = false): ORM
    {
        $schema = SchemaFactory::build();
        if ($extendProduct) {
            // plugin extension: a field and an association added to the schema array at runtime, the entity class is not touched
            $schema['product'][S::COLUMNS]['ean'] = 'ean';
            $schema['product'][S::RELATIONS]['notes'] = [
                Relation::TYPE => Relation::HAS_MANY,
                Relation::TARGET => 'note',
                Relation::SCHEMA => [Relation::CASCADE => false, Relation::INNER_KEY => ['id'], Relation::OUTER_KEY => ['productId']],
            ];
        }

        return CycleBootstrap::orm($manager, $schema + $this->noteRoles() + $extra)->with(heap: new Heap());
    }

    private function versioning(DatabaseManager $manager, string $productId, string $draft): string
    {
        $orm = $this->orm($manager);
        $live = (new Select($orm, 'product'))->where('id', IdBinding::value($productId))->where('versionId', IdBinding::value(Defaults::LIVE_VERSION))->fetchOne();
        \assert($live instanceof Product);
        $this->connection->executeStatement('INSERT INTO version (id, name, created_at) VALUES (' . IdBinding::literal($draft) . ', \'cycle probe\', \'2026-01-01 00:00:00\')');

        // draft = copy of the live row under the same id with another version_id (composite primary key)
        $copy = clone $live;
        $copy->versionId = $draft;
        $copy->stock = 1;
        $copy->translations = [];
        $copy->categories = [];
        $copy->visibilities = [];
        $manager2 = new EntityManager($this->orm($manager));
        $manager2->persist($copy)->run();

        $orm = $this->orm($manager);
        $read = static fn (string $v): ?Product => (new Select($orm, 'product'))->where('id', IdBinding::value($productId))->where('versionId', IdBinding::value($v))->fetchOne();
        $d = $read($draft);
        $l = $read(Defaults::LIVE_VERSION);
        if ($d === null || $l === null || $d->stock !== 1 || $l->stock === 1) {
            throw new \RuntimeException('draft and live not independent');
        }
        // merge is hand written: copy the changed fields to the live version inside one transaction, then drop the draft (no merge API in Cycle)
        $before = $l->stock;
        $manager->database()->begin();
        $l->stock = $d->stock;
        (new EntityManager($orm))->persist($l)->run();
        $manager->database()->rollback();

        return \sprintf('draft row with same id read by (id, version_id), live stock %d vs draft 1, merge hand written (tested inside a rolled back transaction)', $before);
    }

    private function plugin(DatabaseManager $manager, string $productId): string
    {
        $setup = new EntityManager($this->orm($manager));
        foreach ([1, 2] as $i) {
            $note = new \stdClass();
            $note->id = md5('plugin' . $i);
            $note->productId = $productId;
            $note->note = 'plugin note ' . $i;
            $setup->persist($note);
        }
        $setup->run();

        $orm = $this->orm($manager, [], true);
        $product = (new Select($orm, 'product'))->where('id', IdBinding::value($productId))->where('versionId', IdBinding::value(Defaults::LIVE_VERSION))->load('notes')->fetchOne();
        $data = iterator_to_array((new Select($orm, 'product'))->where('id', IdBinding::value($productId))->where('versionId', IdBinding::value(Defaults::LIVE_VERSION))->limit(1)->fetchData(false), false);
        $props = array_keys(get_object_vars($product));
        $notes = $product->notes ?? null;
        if (!\is_iterable($notes) || \count(iterator_to_array($notes)) !== 2) {
            throw new \RuntimeException('association not loaded, properties: ' . implode(',', $props));
        }

        return \sprintf('association "notes" to a new table and field "ean" added to the schema array, class %s unchanged, loaded %d notes, ean on the entity object: %s, in fetchData() array: %s', Product::class, \count(iterator_to_array($notes)), property_exists($product, 'ean') ? 'yes' : 'no', \array_key_exists('ean', $data[0] ?? []) ? 'yes' : 'no');
    }

    private function custom(DatabaseManager $manager): string
    {
        $orm = $this->orm($manager);
        $em = new EntityManager($orm);
        $e = new \stdClass();
        $e->id = md5('custom1');
        $e->note = 'created through StdMapper';
        $em->persist($e)->run();
        $read = (new Select($this->orm($manager), 'note'))->where('id', md5('custom1'))->fetchOne();
        if (!$read instanceof \stdClass || $read->note !== 'created through StdMapper') {
            throw new \RuntimeException('read back failed');
        }

        return 'table created by the schema builder of cycle/database, role without PHP class (StdMapper), insert and read back as stdClass';
    }

    private function attributes(DatabaseManager $manager): string
    {
        $finder = (new \Symfony\Component\Finder\Finder())->files()->in(__DIR__ . '/Attr');
        $locator = new \Spiral\Tokenizer\ClassLocator($finder);
        $compiled = (new \Cycle\Schema\Compiler())->compile(new \Cycle\Schema\Registry($manager), [
            new \Cycle\Annotated\Embeddings(new \Cycle\Annotated\Locator\TokenizerEmbeddingLocator($locator)),
            new \Cycle\Annotated\Entities(new \Cycle\Annotated\Locator\TokenizerEntityLocator($locator)),
            new \Cycle\Annotated\MergeColumns(),
            new \Cycle\Schema\Generator\GenerateRelations(),
            new \Cycle\Schema\Generator\ValidateEntities(),
            new \Cycle\Schema\Generator\GenerateTypecast(),
        ]);
        // no RenderTables / SyncTables: the Shopware tables are never altered
        $orm = CycleBootstrap::orm($manager, $compiled);
        $id = $this->connection->fetchOne('SELECT ' . IdBinding::text('id') . ' FROM product_manufacturer LIMIT 1');
        $row = (new Select($orm, 'attrManufacturer'))->where('id', IdBinding::value((string) $id))->fetchOne();
        if (!$row instanceof Attr\AttrManufacturer || IdBinding::toHex($row->id) !== IdBinding::toHex((string) $id)) {
            throw new \RuntimeException('attribute entity not hydrated');
        }

        return 'schema compiled from #[Entity]/#[Column] attributes via cycle/annotated + schema-builder (without Render/Sync generators), entity read with hex id typecast, ' . \count($compiled) . ' role(s)';
    }

    private function events(DatabaseManager $manager, string $productId): string
    {
        EventMapper::$queued = EventMapper::$written = 0;
        $em = new EntityManager($this->orm($manager));
        for ($i = 0; $i < 50; ++$i) {
            $n = new ProbeNote();
            $n->id = md5('event' . $i);
            $n->productId = $productId;
            $n->note = 'e' . $i;
            $em->persist($n);
        }
        $this->counter->reset();
        $em->run();
        $statements = $this->counter->reset();
        if (EventMapper::$queued !== 50 || EventMapper::$written !== 50) {
            throw new \RuntimeException(\sprintf('queued %d written %d', EventMapper::$queued, EventMapper::$written));
        }

        return \sprintf('custom Mapper wraps queueCreate: 50 queued and 50 written callbacks for one bulk run (%d insert statements)', $statements);
    }
}
