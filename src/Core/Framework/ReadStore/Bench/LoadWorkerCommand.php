<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\ReadStoreScenarios;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One long-lived worker of the R5 load command (started by {@see LoadCommand}, not meant to be run by hand).
 * Boots the kernel and the implementation once, runs one warm-up request per scenario, prints `READY`, waits for the `--go-file` to appear,
 * then sends requests in a fixed cycle (R1 x5, R2 x3, R3b x2, see {@see self::CYCLE}) for `--duration` seconds and prints one JSON line with the latencies.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:load-worker', description: 'R5 worker process, started by read-store:load')]
class LoadWorkerCommand extends Command
{
    /**
     * The fixed R5 mix: 50 percent R1 detail (S2), 30 percent R2 listing (S1), 20 percent R3b storefront search.
     */
    public const CYCLE = ['R1', 'R1', 'R2', 'R1', 'R3b', 'R1', 'R2', 'R3b', 'R1', 'R2'];

    public function __construct(
        private readonly ImplementationRegistry $registry,
        private readonly ParamsFactory $paramsFactory,
        private readonly StatementCounter $counter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('impl', null, InputOption::VALUE_REQUIRED, 'Implementation id')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Seconds to send requests after go', '60')
            ->addOption('go-file', null, InputOption::VALUE_REQUIRED, 'Path the parent creates to start all workers at once')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Offset into the request cycle so workers do not run in lockstep', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $implementation = $this->registry->get((string) $input->getOption('impl'));
        $implementation->configure([]);
        if (!$implementation instanceof ReadStoreScenarios) {
            $output->writeln('ERROR ' . $implementation::class . ' does not implement ReadStoreScenarios');

            return self::FAILURE;
        }
        $base = $this->paramsFactory->create();
        $duration = (float) $input->getOption('duration');
        $seed = (int) $input->getOption('seed');

        // warm-up: every scenario once, with failures reported before the run starts
        foreach (['R1', 'R2', 'R3b'] as $scenario) {
            $this->request($implementation, $scenario, $base, 0);
        }
        $this->counter->reset();
        $output->writeln('READY');
        $goFile = (string) $input->getOption('go-file');
        while (!is_file($goFile)) {
            usleep(200);
        }

        $latencies = ['R1' => [], 'R2' => [], 'R3b' => []];
        $errors = 0;
        $firstError = null;
        $deadline = hrtime(true) + (int) ($duration * 1e9);
        for ($i = $seed; hrtime(true) < $deadline; ++$i) {
            $scenario = self::CYCLE[$i % \count(self::CYCLE)];
            $start = hrtime(true);

            try {
                $this->request($implementation, $scenario, $base, $i);
                $latencies[$scenario][] = round((hrtime(true) - $start) / 1e6, 2);
            } catch (\Throwable $e) {
                ++$errors;
                $firstError ??= $e::class . ': ' . $e->getMessage();
            }
        }

        $output->writeln('RESULT ' . json_encode([
            'latencies' => $latencies,
            'errors' => $errors,
            'firstError' => $firstError,
            'statements' => $this->counter->reset(),
            'peakMemory' => memory_get_peak_usage(true),
        ], \JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function request(object $implementation, string $scenario, Params $base, int $i): void
    {
        /** @var Implementation&ReadStoreScenarios $implementation */
        match ($scenario) {
            // R1 detail: one product of the fixed R4 id list per request (variants with inherited data are among them)
            'R1' => $implementation->s2Detail($base->with(['variantId' => $base->mgetIds[$i % \count($base->mgetIds)]])),
            // R2 listing: page 1 to 5 of the S1 category
            'R2' => $implementation->s1Listing($base->with(['page' => 1 + ($i % 5)])),
            default => $implementation->r3bStorefrontSearch($base),
        };
    }
}
