<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Probe;

use Doctrine\DBAL\Connection;
use OpenSearch\Client;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
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
 * - `opensearch`: one cell per run. Creates `rs_w10_b_c<fields>x<languages>__product_<suffix>` with the real product mapping (languages
 *   expanded), indexes what `es:index` would send (`ElasticsearchProductDefinition::fetch()`, 100 products per bulk request) with the
 *   `rs_document` augmented for the cell, and reports index size, rebuild time, bulk request size and heap. Puts the alias
 *   `rs_w10_b_c<fields>x<languages>__product` on it, so the routes can run with `SHOPWARE_ES_INDEX_PREFIX=rs_w10_b_c<fields>x<languages>_`.
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

    /**
     * @param array<string, mixed> $indexConfig
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SqlDocumentBuilder $builder,
        private readonly ProductIds $productIds,
        private readonly ?object $elasticsearchDefinition,
        private readonly ?Client $client = null,
        private readonly ?object $mappingProvider = null,
        private readonly array $indexConfig = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'documents|valkey|mapping|opensearch', 'documents')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'custom field counts', '0,100,1000,5000')
            ->addOption('languages', null, InputOption::VALUE_REQUIRED, 'language counts', '1,5,20')
            ->addOption('fill', null, InputOption::VALUE_REQUIRED, 'share of the custom fields a product fills', '0.1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'products (0 = all)', '0')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'valkey: documents per cell at most', '2000')
            ->addOption('sample-bytes', null, InputOption::VALUE_REQUIRED, 'valkey: JSON bytes per cell at most', (string) (200 * 1024 * 1024))
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'valkey: MGET iterations', '50')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'valkey: MGET warm-up iterations', '5')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'opensearch: products per bulk request', '100')
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
            'opensearch' => $this->opensearch($output, $cells[0], (int) $input->getOption('limit'), (int) $input->getOption('batch')),
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

    private function opensearch(OutputInterface $output, CustomFieldDocumentSet $cell, int $limit, int $batchSize): int
    {
        $definition = $this->elasticsearchDefinition;
        $client = $this->client;
        $provider = $this->mappingProvider;
        if ($definition === null || $client === null || $provider === null || !method_exists($definition, 'fetch') || !method_exists($provider, 'build')) {
            $this->emit($output, ['event' => 'error', 'reason' => 'Elasticsearch services missing']);

            return self::FAILURE;
        }

        $context = Context::createCLIContext();
        $alias = \sprintf('%s_c%dx%d__product', self::PREFIX, \count($cell->fields()), \count($cell->languageIds()));
        $index = $alias . '_' . bin2hex(random_bytes(4));

        /** @var array<string, mixed> $mapping */
        $mapping = $cell->expandLanguages($provider->build($definition, $context));
        // same as IndexCreator: unset shard and replica counts take the cluster default
        $config = $this->indexConfig;
        foreach (['number_of_shards', 'number_of_replicas'] as $key) {
            if (\is_array($config['settings'] ?? null) && \is_array($config['settings']['index'] ?? null) && ($config['settings']['index'][$key] ?? null) === null) {
                unset($config['settings']['index'][$key]);
            }
        }
        $client->indices()->create(['index' => $index, 'body' => $config + ['mappings' => $mapping]]);
        $heapBefore = $this->heap($client);
        $heapMax = $heapBefore;

        $sourceSizes = [];
        $bulkSizes = [];
        $fetchSeconds = 0.0;
        $augmentSeconds = 0.0;
        $bulkSeconds = 0.0;
        $scanned = 0;
        $errors = 0;
        $firstError = null;
        $start = self::now();

        foreach ($this->productIds->batches($batchSize) as $batch => $ids) {
            if ($limit > 0 && $scanned >= $limit) {
                break;
            }
            $scanned += \count($ids);

            $t = self::now();
            /** @var array<string, array<string, mixed>> $data */
            $data = $definition->fetch(IdBinding::values($ids), $context);
            $fetchSeconds += self::now() - $t;
            if ($data === []) {
                continue;
            }

            $t = self::now();
            $body = [];
            $bytes = 0;
            foreach ($data as $id => $document) {
                $source = \is_array($document['rs_document'] ?? null) ? $document['rs_document'] : [];
                unset($document['rs_document']);
                /** @var array<string, mixed> $document */
                $document = $cell->expandLanguages($document);
                $document['rs_document'] = $cell->augment($source);
                $sourceSizes[] = \strlen($this->encode($document['rs_document']));
                $action = ['index' => ['_id' => $id]];
                $bytes += \strlen($this->encode($action)) + \strlen($this->encode($document)) + 2;
                $body[] = $action;
                $body[] = $document;
            }
            $bulkSizes[] = $bytes;
            $augmentSeconds += self::now() - $t;

            $t = self::now();
            try {
                /** @var array{errors?: bool, items?: list<array<string, array{error?: mixed}>>} $result */
                $result = $client->bulk(['index' => $index, 'body' => $body]);
            } catch (\Throwable $e) {
                ++$errors;
                $firstError ??= substr($e->getMessage(), 0, 300);
                $result = [];
            }
            $bulkSeconds += self::now() - $t;
            if (($result['errors'] ?? false) === true) {
                foreach ($result['items'] ?? [] as $item) {
                    $error = current($item)['error'] ?? null;
                    if ($error !== null) {
                        ++$errors;
                        $firstError ??= substr($this->encode(['e' => $error]), 0, 300);
                    }
                }
            }
            unset($body, $data);

            if ($batch % 10 === 0) {
                $heapMax = max($heapMax, $this->heap($client));
            }
        }

        $client->indices()->refresh(['index' => $index]);
        $client->indices()->flush(['index' => $index]);
        $total = self::now() - $start;
        $heapMax = max($heapMax, $this->heap($client));

        /** @var array{indices: array<string, array{primaries: array{store: array{size_in_bytes: int}, docs: array{count: int}}}>} $stats */
        $stats = $client->indices()->stats(['index' => $index]);
        $primaries = $stats['indices'][$index]['primaries'];
        /** @var array{count: int} $count */
        $count = $client->count(['index' => $index]);
        $client->indices()->updateAliases(['body' => ['actions' => [['add' => ['index' => $index, 'alias' => $alias]]]]]);

        $indexed = $count['count'];
        $storeBytes = $primaries['store']['size_in_bytes'];
        $source = $this->stats($sourceSizes);
        $bulk = $this->stats($bulkSizes);
        $this->emit($output, [
            'event' => 'opensearch',
            'index' => $index,
            'alias' => $alias,
            'fields' => \count($cell->fields()),
            'languages' => \count($cell->languageIds()),
            'products_scanned' => $scanned,
            'products_indexed' => $indexed,
            'lucene_docs' => $primaries['docs']['count'],
            'errors' => $errors,
            'first_error' => $firstError,
            'store_bytes' => $storeBytes,
            'store_bytes_per_product' => $indexed > 0 ? (int) round($storeBytes / $indexed) : null,
            'source_p50' => $source['p50'],
            'source_p95' => $source['p95'],
            'source_max' => $source['max'],
            'source_mean' => $source['mean'],
            'bulk_bytes_p50' => $bulk['p50'],
            'bulk_bytes_max' => $bulk['max'],
            'bulk_requests' => $bulk['n'],
            'rebuild_s' => round($total, 1),
            'fetch_s' => round($fetchSeconds, 1),
            'augment_s' => round($augmentSeconds, 1),
            'bulk_s' => round($bulkSeconds, 1),
            'rebuild_s_per_1k_scanned' => $scanned > 0 ? round($total / $scanned * 1000, 3) : null,
            'mapped_fields' => $this->countFields(\is_array($mapping['properties'] ?? null) ? $mapping['properties'] : []),
            'heap_before' => $heapBefore,
            'heap_max_sampled' => $heapMax,
            'heap_limit' => $this->heap($client, 'heap_max_in_bytes'),
            'load' => sys_getloadavg(),
        ]);

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function heap(Client $client, string $key = 'heap_used_in_bytes'): int
    {
        /** @var array{nodes: array<string, array{jvm: array{mem: array<string, int>}}>} $stats */
        $stats = $client->nodes()->stats(['metric' => 'jvm']);
        $max = 0;
        foreach ($stats['nodes'] as $node) {
            $max = max($max, (int) ($node['jvm']['mem'][$key] ?? 0));
        }

        return $max;
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
