<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/console orm-spike:bench --impl=dal --scenario=all --iterations=50 --warmup=5 [--verify] [--option key=value ...]`
 *
 * Per scenario: warm-up runs, then the measured iterations (wall time with hrtime, statements, peak memory), written to
 * `var/orm-spike/<engine>-<impl>.json` (scenarios already in the file stay, the ones of this run are replaced).
 * Run it with `APP_ENV=prod` and a warm cache, see docs/30-orm-spike-baseline.md.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'orm-spike:bench', description: 'Measures one implementation of the ORM spike scenarios S1 to S5')]
final class BenchCommand extends Command
{
    private const SCENARIOS = ['S1', 'S2', 'S3', 'S4', 'S5'];

    private int $lastNs = 0;

    private int $lastStatements = 0;

    /**
     * @internal
     */
    public function __construct(
        private readonly ImplementationRegistry $registry,
        private readonly ParamsFactory $paramsFactory,
        private readonly StatementCounter $counter,
        private readonly WriteCleaner $cleaner,
        private readonly WriteSnapshot $snapshot,
        private readonly Verifier $verifier,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('impl', null, InputOption::VALUE_REQUIRED, 'dal, dbal, doctrine, cycle or dalv2', 'dal');
        $this->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'S1 to S5 (comma separated) or all', 'all');
        $this->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Measured iterations per scenario', '50');
        $this->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Warm-up iterations per scenario', '5');
        $this->addOption('verify', null, InputOption::VALUE_NONE, 'Compare the result of the implementation with the DAL result and print the first difference (no timing)');
        $this->addOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'key=value passed to the implementation, repeatable');
        $this->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory of the JSON result, default <project>/var/orm-spike');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getOption('impl');
        $scenarios = $input->getOption('scenario') === 'all' ? self::SCENARIOS : array_map('strtoupper', explode(',', (string) $input->getOption('scenario')));
        foreach ($scenarios as $scenario) {
            if (!\in_array($scenario, self::SCENARIOS, true)) {
                $io->error('Unknown scenario ' . $scenario);

                return Command::INVALID;
            }
        }

        $options = [];
        foreach ((array) $input->getOption('option') as $pair) {
            [$k, $v] = array_pad(explode('=', (string) $pair, 2), 2, '1');
            $options[$k] = $v;
        }

        $implementation = $this->registry->get($key);
        $implementation->configure($options);
        $params = $this->paramsFactory->create();
        $engine = SqlDialectFactory::getDefault()->getName();

        if ($input->getOption('verify')) {
            return $this->verify($io, $key, $scenarios, $params, $options);
        }

        $iterations = max(1, (int) $input->getOption('iterations'));
        $warmup = max(0, (int) $input->getOption('warmup'));

        $results = [];
        $rows = [];
        foreach ($scenarios as $scenario) {
            $results[$scenario] = $this->measure($implementation, $scenario, $params, $warmup, $iterations);
            $r = $results[$scenario];
            $rows[] = $r['status'] === 'ok'
                ? [$scenario, 'ok', \sprintf('%.2f', $r['medianMs']), \sprintf('%.2f', $r['p95Ms']), $r['statementsMedian'], \sprintf('%.1f', $r['peakMemoryMb'])]
                : [$scenario, $r['status'], '-', '-', '-', mb_substr((string) $r['message'], 0, 60)];
        }

        $io->table(['scenario', 'status', 'median ms', 'p95 ms', 'statements', 'peak MB'], $rows);

        $dir = (string) ($input->getOption('output-dir') ?? $this->projectDir . '/var/orm-spike');
        $this->write($dir, $engine, $key, $options, $iterations, $warmup, $results);
        $io->writeln(\sprintf('written to %s/%s-%s.json', $dir, $engine, $key));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(Implementation $implementation, string $scenario, Params $params, int $warmup, int $iterations): array
    {
        $run = 0;
        // S4 needs the set before the call and a clean up after it, both outside of the timed part
        $timed = function () use ($implementation, $scenario, $params, &$run): void {
            if ($scenario === 'S4') {
                $set = WriteSet::forRun('m' . $run);
                $this->cleaner->clean();
                $this->counter->reset();
                $t = hrtime(true);
                $implementation->s4Write($params, $set);
                $this->lastNs = hrtime(true) - $t;
                $this->lastStatements = $this->counter->reset();
                $this->cleaner->clean();

                return;
            }
            $this->counter->reset();
            $t = hrtime(true);
            match ($scenario) {
                'S1' => $implementation->s1Listing($params),
                'S2' => $implementation->s2Detail($params),
                'S3' => $implementation->s3Search($params),
                'S5' => $implementation->s5CriteriaFromJson($params),
            };
            $this->lastNs = hrtime(true) - $t;
            $this->lastStatements = $this->counter->reset();
        };

        try {
            for ($i = 0; $i < $warmup; ++$i) {
                ++$run;
                $timed();
            }
            gc_collect_cycles();
            memory_reset_peak_usage();
            $baseline = memory_get_usage();
            $times = [];
            $statements = [];
            for ($i = 0; $i < $iterations; ++$i) {
                ++$run;
                $timed();
                $times[] = $this->lastNs / 1e6;
                $statements[] = $this->lastStatements;
            }
            $peak = memory_get_peak_usage();
        } catch (ScenarioNotSupported $e) {
            return ['status' => 'unsupported', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e::class . ': ' . $e->getMessage()];
        }

        sort($times);
        sort($statements);

        return [
            'status' => 'ok',
            'iterations' => $iterations,
            'warmup' => $warmup,
            'medianMs' => $this->percentile($times, 50),
            'p95Ms' => $this->percentile($times, 95),
            'minMs' => $times[0],
            'maxMs' => $times[\count($times) - 1],
            'statementsMedian' => $statements[intdiv(\count($statements), 2)],
            'statementsMin' => $statements[0],
            'statementsMax' => $statements[\count($statements) - 1],
            'statementsCounted' => $this->counter->isAvailable(),
            'peakMemoryMb' => $peak / 1048576,
            'peakMemoryDeltaMb' => ($peak - $baseline) / 1048576,
        ];
    }

    /**
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, int $p): float
    {
        $index = (int) ceil(\count($sorted) * $p / 100) - 1;

        return $sorted[max(0, min($index, \count($sorted) - 1))];
    }

    /**
     * @param list<string> $scenarios
     * @param array<string, string> $options
     */
    private function verify(SymfonyStyle $io, string $key, array $scenarios, Params $params, array $options): int
    {
        $implementation = $this->registry->get($key);
        $reference = $this->registry->get('dal');
        $reference->configure([]);
        $failed = false;

        foreach ($scenarios as $scenario) {
            try {
                if ($scenario === 'S4') {
                    $diff = $this->verifyWrite($reference, $implementation, $params);
                } else {
                    $method = ['S1' => 's1Listing', 'S2' => 's2Detail', 'S3' => 's3Search', 'S5' => 's5CriteriaFromJson'][$scenario];
                    $expected = $reference->$method($params);
                    $actual = $implementation->$method($params);
                    $diff = $this->verifier->diff($expected, $actual);
                    if ($diff === null && ($expected['items'] ?? [1]) === [] && $scenario !== 'S2') {
                        $diff = 'reference result is empty, the scenario would verify nothing';
                    }
                }
            } catch (ScenarioNotSupported $e) {
                $io->writeln(\sprintf('%s: unsupported (%s)', $scenario, $e->getMessage()));
                continue;
            } catch (\Throwable $e) {
                $diff = 'error ' . $e::class . ': ' . $e->getMessage();
            }

            if ($diff === null) {
                $io->writeln(\sprintf('%s: OK (%s equals dal)', $scenario, $key));
            } else {
                $failed = true;
                $io->writeln(\sprintf('%s: DIFFERENT, first difference: %s', $scenario, $diff));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function verifyWrite(Implementation $reference, Implementation $implementation, Params $params): ?string
    {
        $set = WriteSet::forRun('verify');
        $this->cleaner->clean();
        $reference->s4Write($params, $set);
        $expected = $this->snapshot->read($set);
        $this->cleaner->clean();
        $implementation->s4Write($params, $set);
        $actual = $this->snapshot->read($set);
        $this->cleaner->clean();

        if ($expected === [] || \count($expected) !== Fixtures::WRITE_PRODUCTS) {
            return \sprintf('the dal wrote %d products, expected %d', \count($expected), Fixtures::WRITE_PRODUCTS);
        }

        return $this->verifier->diff($expected, $actual);
    }

    /**
     * @param array<string, string> $options
     * @param array<string, array<string, mixed>> $results
     */
    private function write(string $dir, string $engine, string $key, array $options, int $iterations, int $warmup, array $results): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $file = \sprintf('%s/%s-%s.json', $dir, $engine, $key);
        $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $scenarios = $existing['scenarios'] ?? [];
        foreach ($results as $scenario => $result) {
            $scenarios[$scenario] = $result;
        }
        ksort($scenarios);

        $load = sys_getloadavg() ?: [];
        file_put_contents($file, json_encode([
            'engine' => $engine,
            'impl' => $key,
            'options' => $options,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'php' => \PHP_VERSION,
            'env' => $_SERVER['APP_ENV'] ?? null,
            'date' => date('c'),
            'loadAverage' => $load,
            'scenarios' => $scenarios,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    }
}
