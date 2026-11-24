<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Probe;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Adapter\Valkey\ValkeyStorage;
use Shopware\Core\Framework\ReadStore\Builder\ProductIds;
use Shopware\Core\Framework\ReadStore\Builder\SqlDocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wave 10 handoff B (docs/48): how large does the full product document get with many custom fields and languages, how many mapped fields
 * would today's custom field mapping need, and what does the same document set cost in Valkey.
 *
 * - `documents`: builds every product once with {@see SqlDocumentBuilder}, augments it for every cell (custom fields x languages) with
 *   {@see CustomFieldDocumentSet} and records the JSON size of each document (the `_source` of A's `rs_document` field), plus the number of
 *   mapped fields of the product index (real mapping of the Elasticsearch product definition, extrapolated per language and custom field).
 * - `valkey`: stores a sample of each cell under the `rs_w10_b` prefix in database 5, measures `MEMORY USAGE` per key, the `used_memory`
 *   delta and `MGET` of 24, then deletes the keys again.
 *
 * Writes nothing to the database. One JSON line per cell on stdout.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:probe:source-limits', description: 'Wave 10 B: document size, mapped fields and Valkey cost per custom field and language count')]
final class SourceLimitsProbeCommand extends Command
{
    private const PREFIX = 'rs_w10_b';

    private const MAPPING_HELPER = 'Shopware\Elasticsearch\Product\ElasticsearchCustomFieldsMappingHelper';

    public function __construct(
        private readonly Connection $connection,
        private readonly SqlDocumentBuilder $builder,
        private readonly ProductIds $productIds,
        private readonly ?object $elasticsearchDefinition,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'documents|valkey|mapping', 'documents')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'custom field counts', '0,100,1000,5000')
            ->addOption('languages', null, InputOption::VALUE_REQUIRED, 'language counts', '1,5,20')
            ->addOption('fill', null, InputOption::VALUE_REQUIRED, 'share of the custom fields a product fills', '0.1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'products (0 = all)', '0')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'valkey: documents per cell at most', '2000')
            ->addOption('sample-bytes', null, InputOption::VALUE_REQUIRED, 'valkey: JSON bytes per cell at most', (string) (200 * 1024 * 1024))
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'valkey: MGET iterations', '50')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'valkey: MGET warm-up iterations', '5')
            ->addOption('valkey-host', null, InputOption::VALUE_REQUIRED, 'valkey host', 'instance-valkey-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fieldCounts = array_map('intval', explode(',', (string) $input->getOption('fields')));
        $languageCounts = array_map('intval', explode(',', (string) $input->getOption('languages')));
        $fill = (float) $input->getOption('fill');
        $baseLanguages = $this->baseLanguages();

        $cells = [];
        foreach ($fieldCounts as $fields) {
            foreach ($languageCounts as $languages) {
                $cells[] = new CustomFieldDocumentSet($fields, $languages, $fill, $baseLanguages);
            }
        }

        $this->emit($output, [
            'event' => 'start',
            'mode' => $input->getOption('mode'),
            'database' => $this->connection->getDatabase(),
            'products' => $this->productIds->count(),
            'base_languages' => \count($baseLanguages),
            'fill' => $fill,
            'load' => sys_getloadavg(),
            'php' => \PHP_VERSION,
        ]);

        return match ($input->getOption('mode')) {
            'documents' => $this->documents($output, $cells, (int) $input->getOption('limit')),
            'mapping' => $this->mapping($output, $fieldCounts, $languageCounts, $baseLanguages),
            'valkey' => $this->valkey($input, $output, $cells),
            default => self::INVALID,
        };
    }

    /**
     * @param list<CustomFieldDocumentSet> $cells
     */
    private function documents(OutputInterface $output, array $cells, int $limit): int
    {
        $sizes = array_fill(0, \count($cells), []);
        $seconds = array_fill(0, \count($cells), 0.0);
        $worst = array_fill(0, \count($cells), 0);
        $buildSeconds = 0.0;
        $built = 0;
        $start = self::now();

        foreach ($this->productIds->batches(500) as $ids) {
            if ($limit > 0 && $built >= $limit) {
                break;
            }
            $t = self::now();
            $documents = $this->builder->build($ids);
            $buildSeconds += self::now() - $t;
            $built += \count($ids);

            foreach ($cells as $c => $cell) {
                $t = self::now();
                foreach ($documents as $document) {
                    $sizes[$c][] = \strlen($this->encode($cell->augment($document)));
                }
                $seconds[$c] += self::now() - $t;
                if ($worst[$c] === 0 && $documents !== []) {
                    $worst[$c] = \strlen($this->encode($cell->augment(reset($documents), true)));
                }
            }
        }

        foreach ($cells as $c => $cell) {
            $this->emit($output, [
                'event' => 'cell',
                'fields' => \count($cell->fields()),
                'languages' => \count($cell->languageIds()),
                'filled_per_product' => $cell->filledPerProduct(),
            ] + $this->stats($sizes[$c]) + [
                'all_fields_filled_bytes' => $worst[$c],
                'augment_encode_s' => round($seconds[$c], 1),
            ]);
        }

        $this->emit($output, [
            'event' => 'done',
            'documents' => $built,
            'build_s' => round($buildSeconds, 1),
            'total_s' => round(self::now() - $start, 1),
            'load' => sys_getloadavg(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Mapped fields counted like OpenSearch does for `index.mapping.total_fields.limit`: every mapped property (objects included) and every
     * multi-field counts one.
     *
     * @param list<int> $fieldCounts
     * @param list<int> $languageCounts
     * @param list<string> $baseLanguages
     */
    private function mapping(OutputInterface $output, array $fieldCounts, array $languageCounts, array $baseLanguages): int
    {
        if ($this->elasticsearchDefinition === null || !method_exists($this->elasticsearchDefinition, 'getMapping')) {
            $this->emit($output, ['event' => 'error', 'reason' => 'no Elasticsearch product definition in the container']);

            return self::FAILURE;
        }

        /** @var array{properties: array<string, mixed>} $mapping */
        $mapping = $this->elasticsearchDefinition->getMapping(Context::createCLIContext());
        $total = $this->countFields($mapping['properties']);
        $perLanguage = $this->languageFields($mapping['properties'], $baseLanguages);
        $languageTotal = array_sum($perLanguage);
        $mappedLanguages = \count($baseLanguages);
        $perOneLanguage = intdiv($languageTotal, max(1, $mappedLanguages));
        $fixed = $total - $languageTotal;

        $perType = [];
        $helper = self::MAPPING_HELPER;
        foreach (array_keys(CustomFieldDocumentSet::TYPE_MIX) as $type) {
            $field = \call_user_func([$helper, 'getTypeFromCustomFieldType'], $type);
            $perType[$type] = $this->countFields(['f' => $field]);
        }

        $this->emit($output, [
            'event' => 'mapping',
            'mapped_languages' => $mappedLanguages,
            'total' => $total,
            'fixed' => $fixed,
            'per_language' => $perOneLanguage,
            'rs_document_mapped' => isset($mapping['properties']['rs_document']),
            'source' => $mapping['_source'] ?? null,
            'fields_per_custom_field_type' => $perType,
        ]);

        foreach ($fieldCounts as $fields) {
            foreach ($languageCounts as $languages) {
                $set = new CustomFieldDocumentSet($fields, $languages, 0.0, $baseLanguages);
                $customFieldFields = 0;
                foreach ($set->fields() as $field) {
                    $customFieldFields += $perType[$field['type']];
                }
                $base = $fixed + $languages * $perOneLanguage;
                $this->emit($output, [
                    'event' => 'mapping_cell',
                    'fields' => $fields,
                    'languages' => $languages,
                    'base_fields' => $base,
                    'custom_field_fields_per_language' => $customFieldFields,
                    'all_custom_fields_mapped' => $base + $languages * $customFieldFields,
                    'custom_fields_only_in_rs_document' => $base,
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param list<CustomFieldDocumentSet> $cells
     */
    private function valkey(InputInterface $input, OutputInterface $output, array $cells): int
    {
        $sample = (int) $input->getOption('sample');
        $sampleBytes = (int) $input->getOption('sample-bytes');
        $iterations = (int) $input->getOption('iterations');
        $warmup = (int) $input->getOption('warmup');
        $host = (string) $input->getOption('valkey-host');

        $ids = [];
        foreach ($this->productIds->batches(500) as $batch) {
            array_push($ids, ...$batch);
            if (\count($ids) >= $sample) {
                break;
            }
        }
        $ids = \array_slice($ids, 0, $sample);
        $base = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $base += $this->builder->build($chunk);
        }

        $redis = new \Redis();
        $redis->connect($host, 6379);
        $redis->select(ValkeyStorage::DATABASE);
        $storage = new ValkeyStorage($host, 6379, self::PREFIX);
        $storage->destroy();

        foreach ($cells as $cell) {
            $documents = [];
            $bytes = 0;
            foreach ($base as $document) {
                $augmented = $cell->augment($document);
                $bytes += \strlen($this->encode($augmented));
                $documents[] = $augmented;
                if ($bytes >= $sampleBytes) {
                    break;
                }
            }
            $half = intdiv(\count($documents), 2);

            $before = $this->usedMemory($redis);
            $storage->setup('g1');
            $storage->store(\array_slice($documents, 0, $half), 'g1');
            $atHalf = $this->usedMemory($redis);
            $storage->store(\array_slice($documents, $half), 'g1');
            $atFull = $this->usedMemory($redis);
            $storage->swap('g1');

            $keyBytes = [];
            foreach ($documents as $document) {
                $usage = $redis->rawcommand('MEMORY', 'USAGE', self::PREFIX . ':doc:g1:' . $document['id'], 'SAMPLES', '0');
                $keyBytes[] = \is_int($usage) ? $usage : 0;
            }

            $storedIds = array_map(static fn (array $d): string => (string) $d['id'], $documents);
            if ($storedIds === []) {
                continue;
            }
            $raw = [];
            $decoded = [];
            for ($i = 0; $i < $warmup + $iterations; ++$i) {
                $pick = [];
                foreach ((array) array_rand($storedIds, max(1, min(24, \count($storedIds)))) as $k) {
                    $pick[] = $storedIds[$k];
                }
                $keys = array_map(static fn (string $id): string => self::PREFIX . ':doc:g1:' . $id, $pick);

                $t = self::now();
                $redis->mget($keys);
                $rawMs = (self::now() - $t) * 1000;

                $t = self::now();
                $storage->mget($pick);
                $decodedMs = (self::now() - $t) * 1000;

                if ($i >= $warmup) {
                    $raw[] = $rawMs;
                    $decoded[] = $decodedMs;
                }
            }

            $n = \count($documents);
            $keyStats = $this->stats($keyBytes);
            $this->emit($output, [
                'event' => 'valkey',
                'fields' => \count($cell->fields()),
                'languages' => \count($cell->languageIds()),
                'documents' => $n,
                'json_bytes' => $bytes,
                'memory_usage_p50' => $keyStats['p50'],
                'memory_usage_p95' => $keyStats['p95'],
                'memory_usage_mean' => $keyStats['mean'],
                'used_memory_per_doc_first_half' => $half > 0 ? (int) round(($atHalf - $before) / $half) : null,
                'used_memory_per_doc_second_half' => $n - $half > 0 ? (int) round(($atFull - $atHalf) / ($n - $half)) : null,
                'extrapolated_150k_mib' => round($keyStats['mean'] * 150000 / 1048576, 1),
                'mget24_raw_ms_p50' => $this->percentile($raw, 50),
                'mget24_raw_ms_p95' => $this->percentile($raw, 95),
                'mget24_decoded_ms_p50' => $this->percentile($decoded, 50),
                'mget24_decoded_ms_p95' => $this->percentile($decoded, 95),
                'iterations' => \count($raw),
                'load' => sys_getloadavg(),
            ]);

            $storage->destroy();
            unset($documents);
        }

        $this->emit($output, ['event' => 'done', 'used_memory_after' => $this->usedMemory($redis), 'keys_left' => \count($redis->keys(self::PREFIX . ':*'))]);

        return self::SUCCESS;
    }

    /**
     * @return list<string> default language first
     */
    private function baseLanguages(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM language ORDER BY created_at, id');
        $ids = array_values(array_diff($ids, [Defaults::LANGUAGE_SYSTEM]));
        array_unshift($ids, Defaults::LANGUAGE_SYSTEM);

        return $ids;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function countFields(array $properties): int
    {
        $count = 0;
        foreach ($properties as $property) {
            if (!\is_array($property)) {
                continue;
            }
            ++$count;
            if (isset($property['fields']) && \is_array($property['fields'])) {
                $count += \count($property['fields']);
            }
            if (isset($property['properties']) && \is_array($property['properties'])) {
                $count += $this->countFields($property['properties']);
            }
        }

        return $count;
    }

    /**
     * Fields below language-keyed objects (one entry per language), summed over all languages.
     *
     * @param array<string, mixed> $properties
     * @param list<string> $languages
     *
     * @return list<int>
     */
    private function languageFields(array $properties, array $languages): array
    {
        $counts = [];
        foreach ($properties as $property) {
            if (!\is_array($property) || !isset($property['properties']) || !\is_array($property['properties'])) {
                continue;
            }
            $children = $property['properties'];
            $keys = array_map('strval', array_keys($children));
            if ($keys !== [] && array_diff($keys, $languages) === []) {
                $counts[] = $this->countFields($children);

                continue;
            }
            array_push($counts, ...$this->languageFields($children, $languages));
        }

        return $counts;
    }

    private function usedMemory(\Redis $redis): int
    {
        /** @var array<string, mixed> $info */
        $info = $redis->info('memory');

        return (int) ($info['used_memory'] ?? 0);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function encode(array $document): string
    {
        return json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param list<int> $values
     *
     * @return array{n: int, p50: int, p95: int, max: int, mean: int, total_mib: float}
     */
    private function stats(array $values): array
    {
        if ($values === []) {
            return ['n' => 0, 'p50' => 0, 'p95' => 0, 'max' => 0, 'mean' => 0, 'total_mib' => 0.0];
        }
        sort($values);
        $n = \count($values);
        $sum = array_sum($values);

        return [
            'n' => $n,
            'p50' => $values[(int) floor(0.5 * ($n - 1))],
            'p95' => $values[(int) floor(0.95 * ($n - 1))],
            'max' => $values[$n - 1],
            'mean' => (int) round($sum / $n),
            'total_mib' => round($sum / 1048576, 1),
        ];
    }

    /**
     * @param list<float> $values
     */
    private function percentile(array $values, int $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);

        return round($values[(int) floor($p / 100 * (\count($values) - 1))], 3);
    }

    /**
     * Monotonic seconds for the probe timings (not domain time, nothing to freeze).
     */
    private static function now(): float
    {
        // @phpstan-ignore shopware.noNativeTimeRead
        return hrtime(true) / 1e9;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function emit(OutputInterface $output, array $line): void
    {
        $output->writeln(json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }
}
