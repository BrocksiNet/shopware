<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Probe;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\Fixtures;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `orm-spike:dalv2-probes`: the probes of docs/29 that are not part of S1 to S5, run against the DAL with attribute entities.
 * Every probe removes what it created.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'orm-spike:dalv2-probes', description: 'DAL v2 probes of the wave 8 spike')]
final class DalV2ProbeCommand extends Command
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $noteRepository
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $pluginNoteRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $noteRepository,
        private readonly EntityRepository $pluginNoteRepository,
        private readonly ParamsFactory $paramsFactory,
        private readonly \Shopware\Core\Framework\OrmSpike\DalV2\Typed\FieldPathChecker $pathChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->paramsFactory->create();
        foreach (['versioning', 'dx', 'pluginAssociation', 'fieldPaths'] as $probe) {
            try {
                [$ok, $detail] = $this->{'probe' . ucfirst($probe)}($params);
            } catch (\Throwable $e) {
                [$ok, $detail] = [false, 'error ' . $e::class . ': ' . substr($e->getMessage(), 0, 300)];
            }
            $output->writeln(\sprintf('%-18s %s  %s', $probe, $ok ? 'PASS' : 'FAIL', $detail));
        }

        return 0;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probeVersioning(object $params): array
    {
        $id = Fixtures::id('probe', 'v2-versioned');
        $context = Context::createDefaultContext();
        $this->productRepository->delete([['id' => $id]], $context);

        try {
            $this->productRepository->create([[
                'id' => $id,
                'productNumber' => 'SPK-V2-VERSION',
                'name' => 'v2 versioned',
                'stock' => 1,
                'active' => true,
                'taxId' => $params->taxId,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 1, 'net' => 1, 'linked' => false]],
            ]], $context);

            $versionId = $this->productRepository->createVersion($id, $context, 'probe');
            $draft = $context->createWithVersionId($versionId);
            $this->productRepository->update([['id' => $id, 'stock' => 2]], $draft);

            $live = $this->productRepository->search(new Criteria([$id]), $context)->first();
            $draftRow = $this->productRepository->search(new Criteria([$id]), $draft)->first();
            $read = $live?->getStock() === 1 && $draftRow?->getStock() === 2;

            $this->productRepository->merge($versionId, $context);
            $merged = $this->productRepository->search(new Criteria([$id]), $context)->first()?->getStock() === 2;

            return [$read && $merged, 'createVersion, update in the draft, read live 1 and draft 2, merge, live is 2: ' . ($merged ? 'ok' : 'failed')];
        } finally {
            $this->productRepository->delete([['id' => $id]], $context);
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probeDx(object $params): array
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS spike_note');
        $this->connection->executeStatement('CREATE TABLE spike_note (id BINARY(16) NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, body TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NULL)');
        $context = Context::createDefaultContext();

        try {
            $id = Fixtures::id('probe', 'v2-note');
            $this->noteRepository->create([['id' => $id, 'title' => 'first']], $context);
            $first = $this->noteRepository->search(new Criteria([$id]), $context)->first();
            $this->noteRepository->update([['id' => $id, 'title' => 'second']], $context);
            $second = $this->noteRepository->search(new Criteria([$id]), $context)->first();
            $this->noteRepository->delete([['id' => $id]], $context);
            $gone = $this->noteRepository->search(new Criteria([$id]), $context)->first() === null;

            return [$first?->get('title') === 'first' && $second?->get('title') === 'second' && $gone, 'create, search, update, delete through the service spike_note.repository, entity class ' . ($first !== null ? $first::class : 'none')];
        } finally {
            $this->connection->executeStatement('DROP TABLE IF EXISTS spike_note');
        }
    }

    /**
     * New table and association to the core product; read it with partial loading (the R3 mechanism).
     *
     * @return array{0: bool, 1: string}
     */
    private function probePluginAssociation(object $params): array
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS spike_plugin_note');
        $this->connection->executeStatement('CREATE TABLE spike_plugin_note (id BINARY(16) NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, product_id BINARY(16) NULL, product_version_id BINARY(16) NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NULL)');
        $context = Context::createDefaultContext();
        $productId = $this->productRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();

        try {
            $id = Fixtures::id('probe', 'v2-plugin-note');
            $this->pluginNoteRepository->create([['id' => $id, 'title' => 'plugin', 'productId' => $productId]], $context);

            $full = new Criteria([$id]);
            $full->addAssociation('product.manufacturer');
            $entity = $this->pluginNoteRepository->search($full, $context)->first();
            $join = $entity?->get('product')?->getProductNumber() !== null;

            $partial = new Criteria([$id]);
            $partial->addFields(['id', 'title', 'product.productNumber']);
            $row = $this->pluginNoteRepository->search($partial, $context)->first();
            $projected = $row?->get('title') === 'plugin' && $row->get('product')?->get('productNumber') === $entity?->get('product')?->getProductNumber();

            $filtered = new Criteria();
            $filtered->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('product.id', $productId));
            $filtered->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('id', $id));
            $found = $this->pluginNoteRepository->searchIds($filtered, $context)->getTotal() === 1;

            return [$join && $projected && $found, 'association product, filter on product.id, partial loading with product.productNumber: join ' . ($join ? 'ok' : 'failed') . ', projection ' . ($projected ? 'ok' : 'failed') . ', filter ' . ($found ? 'ok' : 'failed')];
        } finally {
            $this->pluginNoteRepository->delete([['id' => Fixtures::id('probe', 'v2-plugin-note')]], $context);
            $this->connection->executeStatement('DROP TABLE IF EXISTS spike_plugin_note');
        }
    }

    /**
     * X2: the checker accepts the paths of the scenarios and rejects typos.
     *
     * @return array{0: bool, 1: string}
     */
    private function probeFieldPaths(object $params): array
    {
        $valid = ['manufacturer.name', 'manufacturer.translations.name', 'cover.media.path', 'prices.quantityStart', 'categories.id', 'options.group.name', 'name'];
        $invalid = ['manufacturer.nam', 'manufactorer.name', 'prices.quantity', 'productNumber.x', 'options.grup.name'];
        $bad = array_filter($valid, fn (string $p): bool => $this->pathChecker->check('product', $p) !== null);
        $missed = array_filter($invalid, fn (string $p): bool => $this->pathChecker->check('product', $p) === null);
        $count = \count($this->pathChecker->paths('product', 2));

        return [$bad === [] && $missed === [], \sprintf('%d valid paths accepted, %d typos rejected (e.g. %s), %d generated paths at depth 2; wrongly rejected [%s], wrongly accepted [%s]', \count($valid) - \count($bad), \count($invalid) - \count($missed), $this->pathChecker->check('product', 'manufacturer.nam'), $count, implode(',', $bad), implode(',', $missed))];
    }
}
