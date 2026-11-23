<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\CriteriaFieldsResolver;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\CriteriaQueryBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityHydrator;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityReader;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEventFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntityAggregatorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Parser\SqlQueryParser;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Telemetry\DalSearchInstrumentor;
use Shopware\Core\Framework\DataAbstractionLayer\VersionManager;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Dal\DalImplementation;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\Probe;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\ProbeConnection;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\TimedAggregator;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\TimedDispatcher;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\TimedHydrator;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\TimedReader;
use Shopware\Core\Framework\OrmSpike\DalV2\Profile\TimedSearcher;
use Shopware\Core\Framework\OrmSpike\DalV2\Read\WindowCountSearcher;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * DAL v2 of wave 8 handoff V. Reuses the trunk DAL by composition: the repositories are rebuilt here from the same
 * collaborators, so levers can replace single parts (reader, hydrator, write gateway) and the profile mode can time them.
 * Options (`--option key=value`): `profile=1` hrtime probes with a dump at shutdown (`profile_dump=<file>`, `profile_skip=<warm-up calls>`).
 *
 * @internal
 */
#[Package('framework')]
final class DalV2Implementation implements Implementation
{
    /**
     * @var array<string, string>
     */
    private array $options = [];

    private ?DalImplementation $inner = null;

    private int $calls = 0;

    private int $wallNs = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContainerInterface $container,
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly EntityRepository $productRepository,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly RequestCriteriaBuilder $requestCriteriaBuilder,
        private readonly ProductDefinition $productDefinition,
        private readonly EntityDefinitionQueryHelper $queryHelper,
        private readonly SqlQueryParser $parser,
        private readonly CriteriaQueryBuilder $criteriaQueryBuilder,
        private readonly LoggerInterface $logger,
        private readonly CriteriaFieldsResolver $criteriaFieldsResolver,
        private readonly SqlDialect $dialect,
        private readonly EntitySearcherInterface $searcher,
        private readonly EntityAggregatorInterface $aggregator,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly EntityLoadedEventFactory $eventFactory,
        private readonly DalSearchInstrumentor $instrumentor,
        private readonly VersionManager $versionManager,
    ) {
    }

    public static function key(): string
    {
        return 'dalv2';
    }

    public function configure(array $options): void
    {
        $this->options = $options;
        $this->inner = null;
    }

    public function s1Listing(Params $params): array
    {
        $start = $this->begin();
        $result = $this->inner()->s1Listing($params);
        $this->end($start);

        return $result;
    }

    public function s2Detail(Params $params): array
    {
        $start = $this->begin();
        $result = $this->inner()->s2Detail($params);
        $this->end($start);

        return $result;
    }

    public function s3Search(Params $params): array
    {
        $start = $this->begin();
        $result = $this->inner()->s3Search($params);
        $this->end($start);

        return $result;
    }

    public function s4Write(Params $params, WriteSet $set): void
    {
        $start = $this->begin();
        $this->inner()->s4Write($params, $set);
        $this->end($start);
    }

    public function s5CriteriaFromJson(Params $params): array
    {
        $start = $this->begin();
        $result = $this->inner()->s5CriteriaFromJson($params);
        $this->end($start);

        return $result;
    }

    private function profile(): bool
    {
        return ($this->options['profile'] ?? '0') === '1';
    }

    private function begin(): int
    {
        if ($this->profile()) {
            ++$this->calls;
            if ($this->calls === (int) ($this->options['profile_skip'] ?? '0') + 1) {
                Probe::reset();
                $this->wallNs = 0;
            }
            Probe::$on = true;
        }

        return hrtime(true);
    }

    private function end(int $start): void
    {
        if ($this->profile()) {
            Probe::$on = false;
            $this->wallNs += hrtime(true) - $start;
        }
    }

    private function inner(): DalImplementation
    {
        if ($this->inner !== null) {
            return $this->inner;
        }

        $reader = new EntityReader(
            $this->connection,
            $this->profile() ? new TimedHydrator($this->container) : $this->container->get(EntityHydrator::class),
            $this->queryHelper,
            $this->parser,
            $this->criteriaQueryBuilder,
            $this->logger,
            $this->criteriaFieldsResolver,
            $this->dialect,
        );
        $searcher = $this->searcher;
        if (($this->options['count'] ?? '') === 'window') {
            $searcher = new WindowCountSearcher($this->connection, $this->queryHelper, $this->criteriaQueryBuilder);
        }
        $aggregator = $this->aggregator;
        $dispatcher = $this->dispatcher;

        if ($this->profile()) {
            $reader = new TimedReader($reader);
            $searcher = new TimedSearcher($searcher);
            $aggregator = new TimedAggregator($aggregator);
            $dispatcher = new TimedDispatcher($dispatcher);
            $this->wrapConnection();
            register_shutdown_function($this->dump(...));
        }

        $salesChannelRepository = new SalesChannelRepository(
            $this->salesChannelProductRepository->getDefinition(),
            $reader,
            $searcher,
            $aggregator,
            $dispatcher,
            $this->eventFactory,
            $this->instrumentor,
        );
        $productRepository = new EntityRepository(
            $this->productRepository->getDefinition(),
            $reader,
            $this->versionManager,
            $searcher,
            $aggregator,
            $dispatcher,
            $this->eventFactory,
            $this->instrumentor,
        );

        return $this->inner = new DalImplementation(
            $salesChannelRepository,
            $productRepository,
            $this->contextFactory,
            $this->requestCriteriaBuilder,
            $this->productDefinition,
        );
    }

    private function wrapConnection(): void
    {
        $native = $this->connection->getNativeConnection(); // opens the connection
        unset($native);

        $property = new \ReflectionProperty(Connection::class, '_conn');
        $current = $property->getValue($this->connection);
        if (!$current instanceof ProbeConnection) {
            $property->setValue($this->connection, new ProbeConnection($current));
        }
    }

    private function dump(): void
    {
        $file = $this->options['profile_dump'] ?? null;
        $measured = max(1, $this->calls - (int) ($this->options['profile_skip'] ?? '0'));
        $out = ['calls' => $measured, 'wallMs' => $this->wallNs / $measured / 1e6, 'categoriesMs' => [], 'categoryCalls' => [], 'statements' => []];
        foreach (Probe::$ns as $category => $ns) {
            $out['categoriesMs'][$category] = $ns / $measured / 1e6;
            $out['categoryCalls'][$category] = Probe::$calls[$category] / $measured;
        }
        foreach (Probe::$sql as $sql => $stat) {
            $out['statements'][] = ['sql' => $sql, 'perRun' => $stat['n'] / $measured, 'msPerRun' => $stat['ns'] / $measured / 1e6];
        }
        usort($out['statements'], static fn (array $a, array $b): int => $b['msPerRun'] <=> $a['msPerRun']);
        file_put_contents($file ?? '/tmp/dalv2-profile.json', json_encode($out, \JSON_PRETTY_PRINT));
    }
}
