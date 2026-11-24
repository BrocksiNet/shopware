<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Shopware\Core\Framework\Log\Package;

/**
 * Extension point for plugins: adds data to the product documents while they are built.
 * Register the service with the tag `read_store.document_extender`; what {@see self::extend()} returns for a product is stored in
 * `extensions.<name>` of its document and can be queried as `extensions.<name>.<key>`.
 *
 * The extender is called once per batch, for both builders and for the projector, so it should read its data for all ids with one query.
 * Data an extender reads from other tables is only re-projected when the product is written; an extender whose data changes on its own
 * has to trigger the projection of the affected products itself (`ReadStoreProjector::project()`).
 *
 * @internal
 */
#[Package('framework')]
interface DocumentExtender
{
    /**
     * Key under `extensions`, unique per extender.
     */
    public function name(): string;

    /**
     * @param array<string, array<string, mixed>> $documents the documents of the batch, keyed by id, already complete except `extensions`
     *
     * @return array<string, mixed> product id => value; products left out get no entry
     */
    public function extend(array $documents): array;
}
