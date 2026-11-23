<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Fair;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\WriteCleaner;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Proof of the equivalence contract of docs/35 for the full write variants (S4):
 *
 * - `--mode=events`: a subscriber records the `EntityWrittenContainerEvent` of the DAL and of every full variant for the same write set;
 *   entity names, operations, primary keys and normalized payloads are compared with the DAL (dates and the random visibility ids are normalized).
 * - `--mode=reject`: invalid write sets must be rejected by the DAL and by every full variant and leave no row behind;
 *   a valid write set is the positive control. Also checks the write protection function against the DAL.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'orm-spike:fair-check', description: 'Checks that the full write variants of S4 do the same work as the DAL (events, rejection of invalid data)')]
final class FairCheckCommand extends Command
{
    private const FULL = [
        'dbal' => ['dbal', ['full' => '1']],
        'doctrine idiomatic' => ['doctrine', ['mode' => 'idiomatic', 'full' => '1']],
        'doctrine optimized' => ['doctrine', ['mode' => 'optimized', 'full' => '1']],
        'cycle idiomatic' => ['cycle', ['variant' => 'idiomatic', 'full' => '1']],
        'cycle optimized' => ['cycle', ['variant' => 'optimized', 'full' => '1']],
    ];

    /**
     * @var list<EntityWrittenContainerEvent>
     */
    private array $recorded = [];

    public function __construct(
        private readonly ImplementationRegistry $registry,
        private readonly ParamsFactory $paramsFactory,
        private readonly WriteCleaner $cleaner,
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly WriteContract $contract,
        private readonly EntityRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'events, reject or all', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = (string) $input->getOption('mode');
        $this->dispatcher->addListener(EntityWrittenContainerEvent::class, function (EntityWrittenContainerEvent $event): void {
            $this->recorded[] = $event;
        });
        $params = $this->paramsFactory->create();
        $failed = false;

        if ($mode === 'events' || $mode === 'all') {
            $failed = !$this->events($io, $params) || $failed;
        }
        if ($mode === 'reject' || $mode === 'all') {
            $failed = !$this->reject($io, $params) || $failed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function events(SymfonyStyle $io, Params $params): bool
    {
        $io->section('Events of S4 (create then stock update) compared with the DAL');
        $reference = $this->capture('dal', [], $params, WriteSet::forRun('chk'));
        $ok = true;
        $rows = [];
        $rows[] = ['dal', $this->summary($reference), '-', '-'];
        foreach (self::FULL as $label => [$key, $options]) {
            $actual = $this->capture($key, $options, $params, WriteSet::forRun('chk'));
            $names = $this->names($actual) === $this->names($reference);
            $diff = $this->firstDiff($reference, $actual);
            $ok = $ok && $names && $diff === null;
            $rows[] = [$label, $this->summary($actual), $names ? 'match' : 'DIFFERENT', $diff ?? 'identical'];
        }
        $io->table(['implementation', 'containers: entity=results', 'names + counts + ids', 'normalized payloads, existence'], $rows);

        return $ok;
    }

    private function reject(SymfonyStyle $io, Params $params): bool
    {
        $io->section('Invalid and valid S4 write sets');
        $cases = $this->cases($params);
        $ok = true;
        $rows = [];
        foreach ($cases as $name => [$caseParams, $set, $expectRejected]) {
            $row = [$name];
            foreach (array_merge(['dal' => ['dal', []]], self::FULL) as [$key, $options]) {
                $outcome = $this->attempt($key, $options, $caseParams, $set);
                $good = $expectRejected === null || ($expectRejected ? ($outcome['rejected'] && $outcome['rows'] === 0) : (!$outcome['rejected'] && $outcome['rows'] > 0));
                $ok = $ok && $good;
                $row[] = ($outcome['rejected'] ? 'rejected' : 'written') . ', ' . $outcome['rows'] . ' rows' . ($good ? '' : ' (UNEXPECTED)');
            }
            $rows[] = $row;
        }
        $io->table(array_merge(['case', 'dal'], array_keys(self::FULL)), $rows);

        $io->section('Write protection: DAL against WriteContract::assertWritable');
        $rows = [];
        foreach ([
            ['product', 'availableStock', Context::SYSTEM_SCOPE],
            ['product', 'availableStock', Context::USER_SCOPE],
            ['product_translation', 'descriptionTeaser', Context::SYSTEM_SCOPE],
            ['product_translation', 'descriptionTeaser', Context::USER_SCOPE],
            ['product', 'stock', Context::USER_SCOPE],
        ] as [$entity, $field, $scope]) {
            $dal = $this->dalProtected($entity, $field, $scope, $params);
            $own = $this->ownProtected($entity, $field, $scope);
            $ok = $ok && $dal === $own;
            $rows[] = [$entity . '.' . $field, $scope, $dal ? 'rejected' : 'allowed', $own ? 'rejected' : 'allowed', $dal === $own ? 'same' : 'DIFFERENT'];
        }
        $io->table(['field', 'scope', 'dal', 'contract', ''], $rows);

        return $ok;
    }

    /**
     * @return array<string, array{Params, WriteSet, bool|null}> case => [params, write set, expected to be rejected, null = informational]
     */
    private function cases(Params $params): array
    {
        $set = WriteSet::forRun('chk');
        $with = static fn (array $override): Params => new Params(...array_merge(get_object_vars($params), $override));
        $mutate = static function (callable $change) use ($set): WriteSet {
            $products = $set->products;
            $products[25] = $change($products[25]);

            return new WriteSet($set->runId, $products);
        };
        $long = str_repeat('x', 256);

        return [
            'valid (control)' => [$params, $set, false],
            'taxId missing' => [$with(['taxId' => '']), $set, true],
            'taxId not a uuid' => [$with(['taxId' => 'not-a-uuid']), $set, true],
            'productNumber 256 chars (product 25)' => [$params, $mutate(static fn (array $p): array => ['productNumber' => 'SPK-W-chk-' . $long] + $p), true],
            'productNumber blank (product 25)' => [$params, $mutate(static fn (array $p): array => ['productNumber' => '  '] + $p), true],
            'translation name blank (product 25)' => [$params, $mutate(static fn (array $p): array => ['nameEn' => ''] + $p), true],
            'translation name 256 chars (product 25)' => [$params, $mutate(static fn (array $p): array => ['nameDe' => $long] + $p), true],
            'product id not a uuid (product 25)' => [$params, $mutate(static fn (array $p): array => ['id' => 'xyz'] + $p), true],
            'category id not a uuid (product 25)' => [$params, $mutate(static fn (array $p): array => ['categoryIds' => ['zzz']] + $p), true],
            'currencyId not a uuid' => [$with(['currencyId' => 'nope']), $set, true],
            'salesChannelId not a uuid' => [$with(['salesChannelId' => 'bad']), $set, true],
            'language id blank (info only)' => [$with(['languageId' => '']), $set, null],
        ];
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{rejected: bool, rows: int, message: string}
     */
    private function attempt(string $key, array $options, Params $params, WriteSet $set): array
    {
        $this->cleanAll($set);
        $implementation = $this->registry->get($key);
        $implementation->configure($options);
        $rejected = false;
        $message = '';
        try {
            $implementation->s4Write($params, $set);
        } catch (\Throwable $e) {
            $rejected = true;
            $message = $e::class . ': ' . mb_substr($e->getMessage(), 0, 120);
        }
        $rows = $this->rows($set);
        $this->cleanAll($set);

        return ['rejected' => $rejected, 'rows' => $rows, 'message' => $message];
    }

    private function rows(WriteSet $set): int
    {
        $ids = IdBinding::values($this->validIds($set));
        $count = 0;
        foreach (['product' => 'id', 'product_translation' => 'product_id', 'product_category' => 'product_id', 'product_visibility' => 'product_id'] as $table => $column) {
            $count += (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' IN (:ids)', ['ids' => $ids], ['ids' => IdBinding::listType()]);
        }

        return $count;
    }

    /**
     * @return list<string> the ids of the set that are valid uuids (invalid ones can never be stored)
     */
    private function validIds(WriteSet $set): array
    {
        return array_values(array_filter(array_map(static fn (array $p): string => $p['id'], $set->products), static fn (string $id): bool => Uuid::isValid($id)));
    }

    private function cleanAll(WriteSet $set): void
    {
        $this->cleaner->clean();
        $ids = IdBinding::values($this->validIds($set));
        foreach (['product_translation' => 'product_id', 'product_category' => 'product_id', 'product_visibility' => 'product_id', 'product' => 'id'] as $table => $column) {
            $this->connection->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (:ids)', ['ids' => $ids], ['ids' => IdBinding::listType()]);
        }
    }

    private function dalProtected(string $entity, string $field, string $scope, Params $params): bool
    {
        // the DAL decides on the definition flags, a minimal write of one protected field shows it
        $context = Context::createDefaultContext();
        $context = $scope === Context::USER_SCOPE ? $context->scope(Context::USER_SCOPE, static fn (Context $c): Context => $c) : $context;
        $set = WriteSet::forRun('chk');
        $product = $set->products[0];
        $payload = [
            'id' => $product['id'],
            'productNumber' => $product['productNumber'],
            'stock' => 1,
            'taxId' => $params->taxId,
            'price' => [['currencyId' => $params->currencyId, 'gross' => 1.0, 'net' => 1.0, 'linked' => true]],
            'translations' => [$params->systemLanguageId => ['name' => $product['nameEn']]],
        ];
        if ($entity === 'product') {
            $payload[$field] = $field === 'stock' ? 2 : 5;
        } else {
            $payload['translations'][$params->systemLanguageId][$field] = 'x';
        }
        $this->cleanAll($set);
        $rejected = false;
        try {
            $context->scope($scope, fn (Context $c) => $this->productRepository->create([$payload], $c));
        } catch (\Throwable) {
            $rejected = true;
        }
        $this->cleanAll($set);

        return $rejected;
    }

    private function ownProtected(string $entity, string $field, string $scope): bool
    {
        $context = Context::createDefaultContext();
        $context->scope($scope, function (Context $c) use ($entity, $field, &$rejected): void {
            try {
                $this->contract->assertWritable($entity, [$field => 'x'], $c);
                $rejected = false;
            } catch (WriteRejected) {
                $rejected = true;
            }
        });

        return $rejected;
    }

    /**
     * @param array<string, string> $options
     *
     * @return list<EntityWrittenContainerEvent>
     */
    private function capture(string $key, array $options, Params $params, WriteSet $set): array
    {
        $this->cleanAll($set);
        $implementation = $this->registry->get($key);
        $implementation->configure($options);
        $this->recorded = [];
        $implementation->s4Write($params, $set);
        $events = $this->recorded;
        $this->cleanAll($set);

        return $events;
    }

    /**
     * @param list<EntityWrittenContainerEvent> $containers
     */
    private function summary(array $containers): string
    {
        $parts = [];
        foreach ($containers as $n => $container) {
            $entities = [];
            foreach ($container->getEvents() ?? [] as $event) {
                $entities[] = $event->getEntityName() . '=' . \count($event->getWriteResults());
            }
            $parts[] = $n . ': ' . implode(' ', $entities);
        }

        return implode(' | ', $parts);
    }

    /**
     * @param list<EntityWrittenContainerEvent> $containers
     *
     * @return list<string> container, entity, operation, count and sorted normalized primary keys
     */
    private function names(array $containers): array
    {
        $names = [];
        foreach ($containers as $n => $container) {
            foreach ($container->getEvents() ?? [] as $event) {
                $keys = [];
                foreach ($event->getWriteResults() as $result) {
                    $keys[] = json_encode($this->safe($this->normalize($result->getPrimaryKey(), $result->getPayload(), $event->getEntityName(), true)), \JSON_THROW_ON_ERROR);
                }
                sort($keys);
                $names[] = $n . '/' . $event->getEntityName() . '/' . $event->getWriteResults()[0]->getOperation() . '/' . \count($keys) . '/' . md5(implode(',', $keys));
            }
        }

        return $names;
    }

    /**
     * @param list<EntityWrittenContainerEvent> $expected
     * @param list<EntityWrittenContainerEvent> $actual
     */
    private function firstDiff(array $expected, array $actual): ?string
    {
        $a = $this->flat($expected);
        $b = $this->flat($actual);
        foreach ($a as $path => $value) {
            if (!\array_key_exists($path, $b)) {
                return 'missing ' . $path;
            }
            if ($b[$path] !== $value) {
                return 'differs ' . $path . ': ' . $this->fieldDiff(json_decode($value, true), json_decode($b[$path], true), '');
            }
        }
        foreach ($b as $path => $value) {
            if (!\array_key_exists($path, $a)) {
                return 'extra ' . $path;
            }
        }

        return null;
    }

    private function fieldDiff(mixed $dal, mixed $variant, string $path): string
    {
        if (\is_array($dal) && \is_array($variant)) {
            foreach (array_unique(array_merge(array_keys($dal), array_keys($variant))) as $key) {
                if (!\array_key_exists($key, $dal)) {
                    return $path . '/' . $key . ' only in variant=' . json_encode($variant[$key]);
                }
                if (!\array_key_exists($key, $variant)) {
                    return $path . '/' . $key . ' only in dal=' . json_encode($dal[$key]);
                }
                $diff = $this->fieldDiff($dal[$key], $variant[$key], $path . '/' . $key);
                if ($diff !== '') {
                    return $diff;
                }
            }

            return '';
        }

        return $dal === $variant ? '' : $path . ' dal=' . json_encode($dal) . ' variant=' . json_encode($variant);
    }

    /**
     * @param list<EntityWrittenContainerEvent> $containers
     *
     * @return array<string, string>
     */
    private function flat(array $containers): array
    {
        $flat = [];
        foreach ($containers as $n => $container) {
            foreach ($container->getEvents() ?? [] as $event) {
                $entries = [];
                foreach ($event->getWriteResults() as $result) {
                    $existence = $result->getExistence();
                    $payload = $this->normalize($result->getPrimaryKey(), $result->getPayload(), $event->getEntityName(), false);
                    $entries[] = json_encode([
                        'pk' => $this->safe($this->normalize($result->getPrimaryKey(), $result->getPayload(), $event->getEntityName(), true)),
                        'operation' => $result->getOperation(),
                        'payload' => $payload,
                        'existence' => $existence === null ? null : $this->safe([$existence->exists(), array_intersect_key($existence->getState(), ['exists' => true, 'parent_id' => true, 'parent' => true]), $this->visibilityId($existence->getPrimaryKey(), $event->getEntityName()), $existence->getEntityName(), $existence->isChild(), $existence->wasChild()]),
                        'changeSet' => $result->getChangeSet() === null,
                        'event' => $event::class,
                    ], \JSON_THROW_ON_ERROR);
                }
                sort($entries);
                foreach ($entries as $i => $entry) {
                    $flat[$n . '/' . $event->getEntityName() . '/' . $i] = $entry;
                }
            }
            $flat[$n . '/errors'] = (string) \count($container->getErrors());
            $flat[$n . '/order'] = implode(',', array_map(static fn ($e): string => $e->getEntityName(), iterator_to_array($container->getEvents() ?? [])));
        }

        return $flat;
    }

    /**
     * Dates become a marker, price structs become arrays and the random visibility id is replaced by the product id.
     *
     * @param array<string, mixed>|string $primaryKey
     * @param array<string, mixed> $payload
     *
     * @return mixed the primary key when $primaryKeyOnly, else the payload
     */
    private function normalize(array|string $primaryKey, array $payload, string $entity, bool $primaryKeyOnly): mixed
    {
        if ($primaryKeyOnly) {
            return $entity === 'product_visibility' ? 'visibility of ' . $payload['productId'] : $primaryKey;
        }
        $normalized = json_decode(json_encode($this->safe($payload), \JSON_THROW_ON_ERROR | \JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        foreach ($payload as $field => $value) {
            if ($value instanceof \DateTimeInterface) {
                $normalized[$field] = '<datetime ' . $value::class . ' ' . $value->getTimezone()->getName() . '>';
            }
        }
        if ($entity === 'product_visibility') {
            $normalized['id'] = '<visibility id>';
        }

        return $normalized;
    }

    /**
     * Binary strings are shown as hex so that they survive json_encode, byte differences between implementations stay visible.
     */
    private function safe(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map($this->safe(...), $value);
        }
        if (\is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return 'bin:' . bin2hex($value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $primaryKey
     *
     * @return array<string, mixed>
     */
    private function visibilityId(array $primaryKey, string $entity): array
    {
        if ($entity === 'product_visibility' && isset($primaryKey['id'])) {
            $primaryKey['id'] = '<visibility id>';
        }

        return $primaryKey;
    }
}
