<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Shopware\Core\Framework\Log\Package;

/**
 * Builds the product documents ({@see \Shopware\Core\Framework\ReadStore\Document\ProductDocument}) of the live version from the write database.
 * Two implementations exist so their cost can be compared (Q3 of docs/38-read-store-plan.md): {@see DalDocumentBuilder} reads through the
 * repository, {@see SqlDocumentBuilder} with hand-written SQL. Both give the same documents for the same rows.
 *
 * @internal
 */
#[Package('framework')]
interface DocumentBuilder
{
    /**
     * `dal` or `sql`, the value of `read-store:rebuild --builder=`.
     */
    public static function key(): string;

    /**
     * Forgets the languages and currencies read by the first build (a rebuild calls it first, so a long-running worker sees new languages).
     */
    public function reset(): void;

    /**
     * @param list<string> $ids lower case hex product ids, parents and variants
     *
     * @return array<string, array<string, mixed>> documents as {@see \Shopware\Core\Framework\ReadStore\Document\ProductDocument::toArray()}, keyed by id,
     *                                             in the order of `$ids`; ids without a product row are left out (deleted products)
     */
    public function build(array $ids): array;
}
