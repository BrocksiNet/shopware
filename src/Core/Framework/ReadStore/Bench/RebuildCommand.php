<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\OrmSpike\Harness\StoreLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * R7 rebuild: `bin/console read-store:bench:rebuild --impl=store-sql-mysql`.
 * Calls {@see StoreLifecycle::rebuild()} once and prints total time, documents per second, store size and the statements on the write database
 * (of this process; a child process such as the `es:index` of `dal-opensearch` is not counted).
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:bench:rebuild', description: 'R7: rebuild a read store from the write database and print time, documents per second and size')]
class RebuildCommand extends Command
{
    public function __construct(private readonly ImplementationRegistry $registry, private readonly StatementCounter $counter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('impl', null, InputOption::VALUE_REQUIRED, 'Implementation id with a StoreLifecycle (store-*, dal-opensearch)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getOption('impl');
        $implementation = $this->registry->get($key);
        if (!$implementation instanceof StoreLifecycle) {
            $output->writeln(\sprintf('<error>%s has no StoreLifecycle</error>', $key));

            return self::FAILURE;
        }
        $implementation->configure([]);

        $this->counter->reset();
        $start = hrtime(true);
        $result = $implementation->rebuild();
        $seconds = (hrtime(true) - $start) / 1e9;
        $statements = $this->counter->reset();

        $output->writeln(\sprintf(
            'R7 %s: total %.1f s, %d documents, %.0f documents/s, store size %s, statements on the write database (this process) %d',
            $key,
            $seconds,
            $result['documents'],
            $seconds > 0 ? $result['documents'] / $seconds : 0,
            $result['bytes'] === null ? 'n/a' : \sprintf('%.1f MiB', $result['bytes'] / 1048576),
            $statements
        ));

        return self::SUCCESS;
    }
}
