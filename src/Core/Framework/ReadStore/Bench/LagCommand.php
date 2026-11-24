<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\OrmSpike\Harness\StoreLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * R6 lag: `bin/console read-store:bench:lag --impl=store-sql-mysql [--writes=100] [--timeout=30]`.
 * Renames one product (the first id of R4) through the DAL `product.repository`, then polls {@see StoreLifecycle::currentName()} until the new name shows up.
 * Lag = time from the end of the DAL write to the first read that returns the new name (no flush in between, so a refresh interval or a queue shows).
 * The original name is restored at the end. Prints p50, p95 and max lag of all writes.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:bench:lag', description: 'R6: write a product through the DAL and poll the read store until the new value is readable')]
class LagCommand extends Command
{
    public function __construct(
        private readonly ImplementationRegistry $registry,
        private readonly ParamsFactory $paramsFactory,
        private readonly EntityRepository $productRepository,
        private readonly StatementCounter $counter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('impl', null, InputOption::VALUE_REQUIRED, 'Implementation id with a StoreLifecycle (store-*, dal-opensearch)')
            ->addOption('writes', null, InputOption::VALUE_REQUIRED, 'Number of writes', '100')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait per write before it counts as missed', '30');
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
        $params = $this->paramsFactory->create();
        $productId = $params->mgetIds[0];
        $context = new Context(new SystemSource(), [], Defaults::CURRENCY, [$params->languageId, $params->systemLanguageId]);

        $original = $implementation->currentName($params, $productId);
        $writes = (int) $input->getOption('writes');
        $timeout = (float) $input->getOption('timeout');
        $runId = substr(bin2hex(random_bytes(4)), 0, 8);

        $lags = [];
        $missed = 0;
        $this->counter->reset();
        for ($i = 0; $i < $writes; ++$i) {
            $name = \sprintf('lag %s %d', $runId, $i);
            $this->productRepository->update([['id' => $productId, 'name' => $name]], $context);
            $start = hrtime(true);
            $seen = false;
            while ((hrtime(true) - $start) / 1e9 < $timeout) {
                if ($implementation->currentName($params, $productId) === $name) {
                    $seen = true;

                    break;
                }
                usleep(1000);
            }
            if ($seen) {
                $lags[] = (hrtime(true) - $start) / 1e6;
            } else {
                ++$missed;
            }
        }
        $statements = $this->counter->reset();

        if ($original !== null) {
            $this->productRepository->update([['id' => $productId, 'name' => $original]], $context);
        }

        sort($lags);
        $count = \count($lags);
        $at = static fn (int $p): float => $count === 0 ? \NAN : $lags[(int) min($count - 1, max(0, ceil($p / 100 * $count) - 1))];
        $output->writeln(\sprintf(
            'R6 %s: %d writes, %d missed (timeout %.0f s), lag p50 %.1f ms, p95 %.1f ms, max %.1f ms (includes the polling reads, %d statements on the write database in total)',
            $key,
            $writes,
            $missed,
            $timeout,
            $at(50),
            $at(95),
            $count === 0 ? \NAN : $lags[$count - 1],
            $statements
        ));

        return $missed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
