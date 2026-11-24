<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Dal\DalImplementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\StoreLifecycle;
use Symfony\Component\Process\Process;

/**
 * O1: the DAL with `Criteria::STATE_ELASTICSEARCH_AWARE` (registered as `dal-opensearch` in `bench.php`): ids and aggregations come from the
 * lane's OpenSearch product index, the entities are loaded from the SQL database.
 * Needs `SHOPWARE_ES_ENABLED=1` and `SHOPWARE_ES_INDEX_PREFIX=rs_o1_<dataset>_` in the environment of the process (`scripts/read-store-env.sh`).
 * The index is built from the same database with `es:index --no-queue --only=product` (R7), with `SHOPWARE_ES_INDEXING_ENABLED=1` for that child process only.
 *
 * @internal
 */
#[Package('framework')]
class DalOpenSearchImplementation extends DalImplementation implements StoreLifecycle
{
    public function rebuild(): array
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'memory_limit=-1', $this->projectDir() . '/bin/console', 'es:index', '--no-queue', '--no-progress', '--only=product', '--no-interaction'],
            $this->projectDir(),
            ['SHOPWARE_ES_ENABLED' => '1', 'SHOPWARE_ES_INDEXING_ENABLED' => '1'],
            null,
            null
        );
        $process->mustRun();
        $this->refresh();

        // `_stats` docs.count would include the nested documents of the product mapping, `_count` is the number of products
        $documents = (int) ($this->request('GET', '/' . $this->prefix() . '_product/_count')['count'] ?? 0);
        $bytes = 0;
        foreach ($this->request('GET', '/' . $this->prefix() . '_product/_stats/store')['indices'] ?? [] as $index) {
            $bytes += (int) ($index['primaries']['store']['size_in_bytes'] ?? 0);
        }

        return ['documents' => $documents, 'bytes' => $bytes];
    }

    public function flush(): void
    {
        $this->refresh();
    }

    public function currentName(Params $params, string $productId): ?string
    {
        // entities come from SQL in O1, so the name is current immediately, the lag of O1 is the search index (see the R6 command, which also polls R3b ids)
        $items = $this->r4Mget($params->with(['mgetIds' => [$productId]]))['items'] ?? [];

        return $items[0]['name'] ?? null;
    }

    private function refresh(): void
    {
        $this->request('POST', '/' . $this->prefix() . '*/_refresh');
    }

    private function prefix(): string
    {
        return (string) (getenv('SHOPWARE_ES_INDEX_PREFIX') ?: 'sw');
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 120]]);
        $body = file_get_contents(rtrim((string) getenv('OPENSEARCH_URL'), '/') . $path, false, $context);

        return json_decode((string) $body, true) ?: [];
    }
}
