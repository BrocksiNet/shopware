<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\MongoDb;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Document\ProductDocument;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\Schema\DocumentSchema;

/**
 * The BSON document one read store document is stored as:
 *
 * - `_id`: the document id (lower case hex)
 * - `d`: the document as the builders wrote it, returned unchanged by `get()` and `mget()`
 * - `f`: the queryable fields of `d` with every string folded like `utf8mb4_unicode_ci` (case and accents, {@see QueryEvaluator::fold()}),
 *   filters compare, match and group on it, so MongoDB needs no collation for equality and regular expressions cannot do the accent folding
 * - `s`: the search keywords per language as a list of `{k: folded keyword, r: ranking}`, because keyword strings are not safe as field names
 *
 * @internal
 */
#[Package('framework')]
final class MongoDbDocument
{
    /**
     * @param array<string, mixed> $document
     * @param class-string $documentClass
     *
     * @return array<string, mixed>
     */
    public static function toBson(array $document, string $documentClass = ProductDocument::class): array
    {
        $folded = [];
        foreach (DocumentSchema::fields($documentClass) as $name => $field) {
            if ($field->queryable && \array_key_exists($name, $document)) {
                $folded[$name] = self::fold($document[$name]);
            }
        }

        $keywords = [];
        $searchKeywords = $document['searchKeywords'] ?? [];
        foreach (\is_array($searchKeywords) ? $searchKeywords : [] as $languageId => $ranked) {
            $entries = [];
            foreach (\is_array($ranked) ? $ranked : [] as $keyword => $ranking) {
                $entries[] = ['k' => QueryEvaluator::fold((string) $keyword), 'r' => (float) $ranking];
            }
            $keywords[(string) $languageId] = $entries;
        }

        return ['_id' => (string) $document['id'], 'd' => $document, 'f' => $folded, 's' => (object) $keywords];
    }

    private static function fold(mixed $value): mixed
    {
        if (\is_string($value)) {
            return QueryEvaluator::fold($value);
        }

        if (\is_array($value)) {
            return array_map(self::fold(...), $value);
        }

        return $value;
    }
}
