<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Psr\Clock\ClockInterface;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console read-store:rebuild --store=sql-mysql --builder=sql`: blue/green rebuild of one store, see {@see Rebuilder}.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:rebuild', description: 'Rebuilds a read store from the write database into a new generation and swaps it live')]
class RebuildCommand extends Command
{
    public function __construct(
        private readonly StorageRegistry $registry,
        private readonly Rebuilder $rebuilder,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store key (tag read_store.storage), e.g. array, sql-mysql, opensearch');
        $this->addOption('builder', null, InputOption::VALUE_REQUIRED, 'Document builder: sql or dal', 'sql');
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Products per build batch', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = (string) $input->getOption('store');
        if ($store === '') {
            $output->writeln(\sprintf('<error>--store is required, registered: %s</error>', implode(', ', $this->registry->storageKeys())));

            return self::FAILURE;
        }
        $storage = $this->registry->storage($store);
        $builder = $this->registry->builder((string) $input->getOption('builder'));
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $start = (float) $this->clock->now()->format('U.u');
        $result = $this->rebuilder->rebuild($storage, $builder, $batchSize, static function (int $documents) use ($output): void {
            if ($output->isVerbose()) {
                $output->writeln(\sprintf('%d documents', $documents));
            }
        });
        $seconds = (float) $this->clock->now()->format('U.u') - $start;

        $output->writeln(\sprintf(
            'Rebuilt %s with the %s builder: generation %s live, %d documents of %d products in %.1F s',
            $store,
            $builder::key(),
            $result['generation'],
            $result['documents'],
            $result['products'],
            $seconds
        ));

        return self::SUCCESS;
    }
}
