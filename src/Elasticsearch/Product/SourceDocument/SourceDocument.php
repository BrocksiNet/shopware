<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product\SourceDocument;

use Shopware\Core\Framework\Log\Package;

/**
 * O1a (wave 10, docs/47-o1a-source-hydration.md): the product index stores the full read store product document
 * ({@see \Shopware\Core\Framework\ReadStore\Document\ProductDocument}) in `_source` next to the query fields, so the
 * search hits carry the documents and the storefront reads no product rows from SQL.
 *
 * @internal
 */
#[Package('framework')]
final class SourceDocument
{
    /**
     * Object field with `enabled: false`: kept in `_source`, not indexed, not counted against `total_fields.limit`.
     */
    public const FIELD = 'rs_document';
}
