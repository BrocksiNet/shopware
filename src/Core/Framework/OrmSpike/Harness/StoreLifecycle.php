<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * Optional second interface of a read store {@see Implementation} (id `store-*`), used by `read-store:bench:rebuild` (R7) and
 * `read-store:bench:lag` (R6) in `ReadStore/Bench/`.
 *
 * @internal
 */
#[Package('framework')]
interface StoreLifecycle
{
    /**
     * Rebuilds the whole store from the write database (the one command every store must have) and returns what the R7 table prints.
     *
     * @return array{documents: int, bytes: ?int}
     */
    public function rebuild(): array;

    /**
     * Makes everything written so far visible to readers (for example an OpenSearch `_refresh`), a no-op for stores that are read-your-writes.
     */
    public function flush(): void;

    /**
     * The translated name (language chain of the {@see Params}) the store currently returns for the product, null if it holds no document.
     * R6 polls this until a written name shows up.
     */
    public function currentName(Params $params, string $productId): ?string;
}
