<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\OpenSearch;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Document\ProductDocument;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\Schema\DocumentSchema;
use Shopware\Core\Framework\ReadStore\Port\Schema\Field;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldScope;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * Turns a document into what is indexed and derives the index mapping from the document schema.
 *
 * `_source` keeps the document as it is, so reads return it untouched (the key `rs` is excluded when reading).
 * Everything that is searched lives below the key `rs`, the original fields are not indexed:
 *
 * - `rs.f.<path>`: the value to filter and aggregate on. Strings are folded (lower case, no accents, the same function as the
 *   reference evaluator), ids and list elements are lower cased, numbers, booleans and dates stay typed.
 * - `rs.s.<path>`: the ICU primary sort key of a string as hex, used for sorting and ranges, so the order is the one of the reference evaluator.
 * - `rs.n.<field>`: the length of a list field, tells an empty list (known) from a missing one (unknown).
 * - `rs.g.<field>`: the value of a collapse field, with a sentinel for null, so that null is one group like in SQL.
 * - `rs.f.kw.<languageId>`: folded search keywords, `rs.k`: all keywords with their ranking, read by the score script only.
 *
 * @internal
 */
#[Package('framework')]
final class IndexSchema
{
    public const SENTINEL = '~null';

    public const DATE_FORMAT = 'yyyy-MM-dd HH:mm:ss.SSS';

    private const MAX_KEYWORD_BYTES = 32000;

    /**
     * @param class-string $documentClass
     * @param list<string> $collapseFields fields that can be used as `groupField`
     * @param list<string> $noSortKeyFields string fields without a sort key (long texts), sorting and ranges on them are not supported
     */
    public function __construct(
        private readonly string $documentClass = ProductDocument::class,
        private readonly array $collapseFields = ['displayGroup'],
        private readonly array $noSortKeyFields = ['description'],
    ) {
    }

    /**
     * @return list<string>
     */
    public function collapseFields(): array
    {
        return $this->collapseFields;
    }

    public function hasSortKey(string $field): bool
    {
        return !\in_array($field, $this->noSortKeyFields, true);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function index(array $document): array
    {
        $f = [];
        $s = [];
        $n = [];
        $g = [];

        foreach (DocumentSchema::fields($this->documentClass) as $name => $field) {
            if (!$field->queryable) {
                continue;
            }

            $value = $document[$name] ?? null;
            $this->indexField($name, $field, $value, $f, $s, $n, $g);
        }

        $f['kw'] = [];
        $keywords = [];
        $searchKeywords = $document['searchKeywords'] ?? [];
        foreach (\is_array($searchKeywords) ? $searchKeywords : [] as $languageId => $languageKeywords) {
            if (!\is_array($languageKeywords)) {
                continue;
            }
            foreach ($languageKeywords as $keyword => $ranking) {
                $folded = QueryEvaluator::fold((string) $keyword);
                $f['kw'][(string) $languageId][] = $folded;
                $keywords[] = ['k' => $folded, 'r' => (float) (\is_numeric($ranking) ? $ranking : 0.0)];
            }
        }

        $document['rs'] = ['f' => $f, 's' => $s, 'n' => $n, 'g' => $g, 'k' => $keywords];

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapping(): array
    {
        $properties = [];
        $templates = [];
        $sortProperties = [];
        $groupProperties = [];
        $lengthProperties = [];

        foreach (DocumentSchema::fields($this->documentClass) as $name => $field) {
            if (!$field->queryable) {
                continue;
            }

            if ($field->scope === FieldScope::NONE) {
                $mapped = $this->mapType($field->type);
                if ($mapped !== null) {
                    $properties[$name] = $mapped;
                }
                if ($field->type === FieldType::STRING || $field->type === FieldType::TEXT) {
                    $sortProperties[$name] = ['type' => 'keyword'];
                }
                if ($field->type === FieldType::LIST) {
                    $lengthProperties[$name] = ['type' => 'integer'];
                }
                if ($field->type === FieldType::ID && \in_array($name, $this->collapseFields, true)) {
                    $groupProperties[$name] = ['type' => 'keyword'];
                }
                if ($field->type === FieldType::OBJECT) {
                    $templates[] = $this->template('rs.f.' . $name . '.*', ['type' => 'keyword'], 'string');
                }

                continue;
            }

            if (($field->type === FieldType::STRING || $field->type === FieldType::TEXT) && $field->scope === FieldScope::LANGUAGE) {
                $templates[] = $this->template('rs.f.' . $name . '.*', ['type' => 'keyword']);
                $templates[] = $this->template('rs.s.' . $name . '.*', ['type' => 'keyword']);

                continue;
            }

            if ($field->type === FieldType::OBJECT && $field->scope === FieldScope::LANGUAGE) {
                $templates[] = $this->template('rs.f.' . $name . '.*', ['type' => 'keyword'], 'string');

                continue;
            }

            // objects (the nested levels of cheapestPrice) must not match, so the template is limited to numeric json values
            foreach (['long', 'double'] as $jsonType) {
                $templates[] = $this->template('rs.f.' . $name . '.*', ['type' => $field->type === FieldType::INT ? 'long' : 'double'], $jsonType);
            }
        }

        $templates[] = $this->template('rs.f.kw.*', ['type' => 'keyword']);

        return [
            'dynamic' => false,
            'dynamic_templates' => $templates,
            'properties' => [
                'rs' => [
                    'type' => 'object',
                    'dynamic' => true,
                    'properties' => [
                        'f' => ['type' => 'object', 'dynamic' => true, 'properties' => $properties],
                        's' => ['type' => 'object', 'dynamic' => true, 'properties' => $sortProperties],
                        'n' => ['type' => 'object', 'dynamic' => false, 'properties' => $lengthProperties],
                        'g' => ['type' => 'object', 'dynamic' => false, 'properties' => $groupProperties],
                        'k' => ['type' => 'object', 'enabled' => false],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $f
     * @param array<string, mixed> $s
     * @param array<string, mixed> $n
     * @param array<string, mixed> $g
     */
    private function indexField(string $name, Field $field, mixed $value, array &$f, array &$s, array &$n, array &$g): void
    {
        if ($field->scope === FieldScope::NONE) {
            $this->indexPlain($name, $field->type, $value, $f, $s, $n, $g);

            return;
        }

        if (!\is_array($value)) {
            return;
        }

        if ($field->scope === FieldScope::RULE_CURRENCY) {
            $numbers = $this->numbers($value);
            if ($numbers !== []) {
                $f[$name] = $numbers;
            }

            return;
        }

        foreach ($value as $key => $entry) {
            $key = (string) $key;
            if ($field->type === FieldType::STRING || $field->type === FieldType::TEXT) {
                if (!\is_string($entry)) {
                    continue;
                }
                $f[$name][$key] = $this->keyword(QueryEvaluator::fold($entry), $name);
                if ($this->hasSortKey($name)) {
                    $s[$name][$key] = $this->sortKey($entry, $name);
                }

                continue;
            }

            if ($field->type === FieldType::OBJECT) {
                $mirror = $this->mirror($entry);
                if ($mirror !== null) {
                    $f[$name][$key] = $mirror;
                }

                continue;
            }

            if (\is_int($entry) || \is_float($entry)) {
                $f[$name][$key] = $entry;
            }
        }
    }

    /**
     * @param array<string, mixed> $f
     * @param array<string, mixed> $s
     * @param array<string, mixed> $n
     * @param array<string, mixed> $g
     */
    private function indexPlain(string $name, FieldType $type, mixed $value, array &$f, array &$s, array &$n, array &$g): void
    {
        if ($type === FieldType::ID && \in_array($name, $this->collapseFields, true)) {
            $g[$name] = \is_string($value) ? strtolower($value) : self::SENTINEL;
        }

        if ($value === null) {
            return;
        }

        switch ($type) {
            case FieldType::ID:
                if (\is_string($value)) {
                    $f[$name] = strtolower($value);
                }

                break;
            case FieldType::STRING:
            case FieldType::TEXT:
                if (\is_string($value)) {
                    $f[$name] = $this->keyword(QueryEvaluator::fold($value), $name);
                    if ($this->hasSortKey($name)) {
                        $s[$name] = $this->sortKey($value, $name);
                    }
                }

                break;
            case FieldType::INT:
            case FieldType::FLOAT:
                if (\is_int($value) || \is_float($value)) {
                    $f[$name] = $value;
                }

                break;
            case FieldType::BOOL:
                if (\is_bool($value)) {
                    $f[$name] = $value;
                }

                break;
            case FieldType::DATETIME:
                if (\is_string($value)) {
                    $f[$name] = $value;
                }

                break;
            case FieldType::LIST:
                if (\is_array($value)) {
                    $f[$name] = array_values(array_map(static fn (mixed $element): string => strtolower(\is_scalar($element) ? (string) $element : ''), $value));
                    $n[$name] = \count($value);
                }

                break;
            case FieldType::OBJECT:
                $mirror = $this->mirror($value);
                if ($mirror !== null) {
                    $f[$name] = $mirror;
                }

                break;
            default:
                break;
        }
    }

    /**
     * Mirrors the scalar leaves of custom fields and extensions as folded strings, lists and booleans are not mirrored.
     */
    private function mirror(mixed $value): mixed
    {
        if (\is_string($value)) {
            return $this->keyword(QueryEvaluator::fold($value), 'custom field');
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (!\is_array($value) || array_is_list($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $key => $entry) {
            $mirrored = $this->mirror($entry);
            if ($mirrored !== null) {
                $result[(string) $key] = $mirrored;
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function numbers(array $value): array
    {
        $result = [];
        foreach ($value as $key => $entry) {
            if (\is_int($entry) || \is_float($entry)) {
                $result[(string) $key] = $entry;
            } elseif (\is_array($entry)) {
                $nested = $this->numbers($entry);
                if ($nested !== []) {
                    $result[(string) $key] = $nested;
                }
            }
        }

        return $result;
    }

    private function keyword(string $value, string $field): string
    {
        if (\strlen($value) > self::MAX_KEYWORD_BYTES) {
            throw ReadStoreException::invalidCriteria(\sprintf('the value of "%s" is too long to index as a keyword (%d bytes)', $field, \strlen($value)));
        }

        return $value;
    }

    private function sortKey(string $value, string $field): string
    {
        static $collator = null;
        if (!$collator instanceof \Collator) {
            $collator = new \Collator('root');
            $collator->setStrength(\Collator::PRIMARY);
        }

        $key = $collator->getSortKey($value);

        return $this->keyword(bin2hex($key === false ? $value : $key), $field);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapType(FieldType $type): ?array
    {
        return match ($type) {
            FieldType::ID, FieldType::STRING, FieldType::TEXT, FieldType::LIST => ['type' => 'keyword'],
            FieldType::INT => ['type' => 'long'],
            FieldType::FLOAT => ['type' => 'double'],
            FieldType::BOOL => ['type' => 'boolean'],
            FieldType::DATETIME => ['type' => 'date', 'format' => self::DATE_FORMAT],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array<string, array<string, mixed>>
     */
    private function template(string $pathMatch, array $mapping, ?string $mappingType = null): array
    {
        $rule = ['path_match' => $pathMatch, 'mapping' => $mapping];
        if ($mappingType !== null) {
            $rule['match_mapping_type'] = $mappingType;
        }

        return [$pathMatch . '#' . ($mappingType ?? 'any') => $rule];
    }
}
