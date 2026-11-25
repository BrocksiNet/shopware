<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * R5 load: `bin/console read-store:load --impl=dal --concurrency=1,8,32 --duration=60s [--markers]`.
 * Per concurrency level it starts that many pre-booted long-lived worker processes ({@see LoadWorkerCommand}, one kernel boot each, outside the
 * measured window), waits until all print READY, creates the go file all workers wait for and collects one JSON line each after `--duration`.
 * Mix per request cycle: 50 percent R1 detail, 30 percent R2 listing, 20 percent R3b search.
 * Prints per level: requests, throughput, p50 and p95 (all requests and per scenario), errors and the statements on the write database (sum of all workers).
 * With `--markers` it prints `MARK start c=<n>` and `MARK end c=<n>` right before `go` and right after the last result, so the host script
 * `scripts/read-store-load.sh` can read the cgroup CPU counter of the write database container at both marks (the container is not reachable from here).
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:load', description: 'R5: mixed R1/R2/R3b load with pre-booted worker processes')]
class LoadCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('impl', null, InputOption::VALUE_REQUIRED, 'Implementation id')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Comma separated worker counts', '1,8,32')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Duration of one level, for example 60s', '60s')
            ->addOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'key=value passed to the implementation, repeatable')
            ->addOption('markers', null, InputOption::VALUE_NONE, 'Print MARK lines for the host side CPU sampler');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $impl = (string) $input->getOption('impl');
        $seconds = (int) rtrim((string) $input->getOption('duration'), 's');
        $markers = (bool) $input->getOption('markers');
        $optionArgs = [];
        foreach ((array) $input->getOption('option') as $pair) {
            $optionArgs[] = '--option=' . (string) $pair;
        }
        $failed = false;

        foreach (array_map('intval', explode(',', (string) $input->getOption('concurrency'))) as $concurrency) {
            $workers = [];
            $goFile = sys_get_temp_dir() . '/read-store-load-go-' . getmypid() . '-' . $concurrency;
            @unlink($goFile);
            for ($w = 0; $w < $concurrency; ++$w) {
                $process = new Process(
                    [\PHP_BINARY, '-d', 'memory_limit=-1', $this->projectDir . '/bin/console', 'read-store:load-worker', '--impl=' . $impl, '--duration=' . $seconds, '--seed=' . $w, '--go-file=' . $goFile, '--no-interaction', ...$optionArgs],
                    $this->projectDir,
                    null,
                    null,
                    null
                );
                $process->start();
                $workers[$w] = $process;
            }

            $ready = false;
            $bootDeadline = microtime(true) + 300;
            while (!$ready && microtime(true) < $bootDeadline) {
                $ready = true;
                foreach ($workers as $process) {
                    if (!$process->isRunning() && !str_contains($process->getOutput(), 'READY')) {
                        $output->writeln('<error>worker failed to boot: ' . trim($process->getOutput() . $process->getErrorOutput()) . '</error>');
                        foreach ($workers as $other) {
                            $other->stop(0);
                        }

                        return self::FAILURE;
                    }
                    $ready = $ready && str_contains($process->getOutput(), 'READY');
                }
                usleep(100000);
            }
            if (!$ready) {
                $output->writeln('<error>workers not ready after 300 s</error>');

                return self::FAILURE;
            }

            if ($markers) {
                $output->writeln('MARK start c=' . $concurrency);
            }
            $start = hrtime(true);
            touch($goFile);
            foreach ($workers as $process) {
                $process->wait();
            }
            $elapsed = (hrtime(true) - $start) / 1e9;
            @unlink($goFile);
            if ($markers) {
                $output->writeln('MARK end c=' . $concurrency);
            }

            $all = ['R1' => [], 'R2' => [], 'R3b' => []];
            $errors = 0;
            $statements = 0;
            $firstError = null;
            $peak = 0;
            foreach ($workers as $process) {
                $line = null;
                foreach (explode("\n", $process->getOutput()) as $candidate) {
                    if (str_starts_with($candidate, 'RESULT ')) {
                        $line = substr($candidate, 7);
                    }
                }
                if ($line === null) {
                    $output->writeln('<error>worker returned no result: ' . trim($process->getOutput() . $process->getErrorOutput()) . '</error>');
                    $failed = true;

                    continue;
                }
                $result = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                foreach ($result['latencies'] as $scenario => $values) {
                    $all[$scenario] = [...$all[$scenario], ...$values];
                }
                $errors += $result['errors'];
                $firstError ??= $result['firstError'];
                $statements += $result['statements'];
                $peak = max($peak, $result['peakMemory']);
            }

            $total = array_merge(...array_values($all));
            sort($total);
            $count = \count($total);
            $output->writeln(\sprintf(
                'R5 %s c=%d duration=%.1fs requests=%d throughput=%.1f/s p50=%.1fms p95=%.1fms errors=%d statements=%d (%.1f per request) worker peak memory=%.0f MiB',
                $impl,
                $concurrency,
                $elapsed,
                $count,
                $count / $elapsed,
                self::percentile($total, 50),
                self::percentile($total, 95),
                $errors,
                $statements,
                $count > 0 ? $statements / $count : 0,
                $peak / 1048576
            ));
            foreach ($all as $scenario => $values) {
                sort($values);
                $output->writeln(\sprintf('  %s n=%d p50=%.1fms p95=%.1fms', $scenario, \count($values), self::percentile($values, 50), self::percentile($values, 95)));
            }
            if ($firstError !== null) {
                $output->writeln('  first error: ' . $firstError);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, int $p): float
    {
        $count = \count($sorted);

        return $count === 0 ? \NAN : $sorted[(int) min($count - 1, max(0, ceil($p / 100 * $count) - 1))];
    }
}
