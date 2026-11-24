<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\OpenSearch;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Contains;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\EqualsAny;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Multi;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Not;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Prefix;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Range;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\Schema\ResolvedField;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * Translates store criteria into OpenSearch query DSL, see {@see IndexSchema} for the indexed layout.
 *
 * The three-valued logic of the port is kept by translating every filter twice: `positive()` is the set of documents for which the filter
 * is true, `negative()` the set for which it is false (unknown is in neither). `Not` swaps them, `AND` and `OR` combine them as Kleene logic.
 * Anything that cannot be answered exactly throws {@see NotSupportedByEngine}.
 *
 * @internal
 */
#[Package('framework')]
final class QueryBuilder
{
    private const ENGINE = 'opensearch';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\z/';

    public function __construct(
        private readonly FieldResolver $resolver,
        private readonly IndexSchema $schema,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function positive(Filter $filter, StoreContext $context): array
    {
        return $this->translate($filter, $context)[0];
    }

    /**
     * @param list<Filter> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function all(array $filters, StoreContext $context): array
    {
        return array_map(fn (Filter $filter): array => $this->positive($filter, $context), $filters);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    public function allMissing(array $paths): array
    {
        return ['bool' => ['must_not' => array_map(static fn (string $p): array => ['exists' => ['field' => $p]], $paths)]];
    }

    /**
     * @param list<Sorting> $sortings
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function sort(array $sortings, array $ids, bool $search, StoreContext $context): array
    {
        $sort = [];
        foreach ($sortings as $sorting) {
            if ($sorting->field === Sorting::SCORE) {
                $sort[] = ['_score' => ['order' => strtolower($sorting->direction)]];

                continue;
            }

            $sort[] = $this->sortClause($sorting, $context);
        }

        if ($sortings === []) {
            if ($ids !== [] && !$search) {
                $positions = [];
                foreach ($ids as $position => $id) {
                    $positions[strtolower($id)] ??= $position;
                }
                $sort[] = ['_script' => [
                    'type' => 'number',
                    'order' => 'asc',
                    'script' => [
                        'lang' => 'painless',
                        'source' => '(double) params.pos.getOrDefault(doc[\'rs.f.id\'].value, 2147483647)',
                        'params' => ['pos' => (object) $positions],
                    ],
                ]];
            } elseif ($search) {
                $sort[] = ['_score' => ['order' => 'desc']];
            }
        }

        $sort[] = ['rs.f.id' => ['order' => 'asc']];

        return $sort;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} positive and negative set
     */
    private function translate(Filter $filter, StoreContext $context): array
    {
        if ($filter instanceof Multi || $filter instanceof Not) {
            return $this->combine($filter, $context);
        }

        if ($filter instanceof Equals) {
            return $this->equals($filter->field, [$filter->value], $context);
        }

        if ($filter instanceof EqualsAny) {
            return $this->equals($filter->field, $filter->values, $context);
        }

        if ($filter instanceof Contains || $filter instanceof Prefix) {
            return $this->text($filter, $context);
        }

        if ($filter instanceof Range) {
            return $this->range($filter, $context);
        }

        throw NotSupportedByEngine::feature(self::ENGINE, $filter::class);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function combine(Multi|Not $filter, StoreContext $context): array
    {
        $positives = [];
        $negatives = [];
        foreach ($filter->filters as $inner) {
            [$positive, $negative] = $this->translate($inner, $context);
            $positives[] = $positive;
            $negatives[] = $negative;
        }

        if ($filter->operator === Multi::AND) {
            $positive = $positives === [] ? $this->matchAll() : ['bool' => ['filter' => $positives]];
            $negative = $negatives === [] ? $this->matchNone() : ['bool' => ['should' => $negatives, 'minimum_should_match' => 1]];
        } else {
            $positive = $positives === [] ? $this->matchNone() : ['bool' => ['should' => $positives, 'minimum_should_match' => 1]];
            $negative = $negatives === [] ? $this->matchAll() : ['bool' => ['filter' => $negatives]];
        }

        return $filter instanceof Not ? [$negative, $positive] : [$positive, $negative];
    }

    /**
     * @param list<mixed> $candidates
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function equals(string $fieldName, array $candidates, StoreContext $context): array
    {
        $resolved = $this->resolver->resolve($fieldName, $context);
        $paths = $this->paths($resolved);
        $hasNull = \in_array(null, $candidates, true);
        $values = [];
        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                $values[] = $this->equalsValue($resolved, $candidate);
            }
        }

        if ($resolved->type === FieldType::ANY && $hasNull) {
            throw NotSupportedByEngine::field($fieldName, 'null on a field of unknown type');
        }

        if ($resolved->isList()) {
            $present = ['exists' => ['field' => 'rs.n.' . $paths[0]]];
            $hit = $values === [] ? null : $this->termQuery('rs.f.' . $paths[0], $values);
            $parts = [];
            if ($hit !== null) {
                $parts[] = $hit;
            }
            if ($hasNull) {
                $parts[] = ['bool' => ['must_not' => [$present]]];
            }
            $positive = $parts === [] ? $this->matchNone() : ['bool' => ['should' => $parts, 'minimum_should_match' => 1]];
            $negative = ['bool' => ['filter' => [$present], 'must_not' => $hit === null ? [] : [$hit]]];

            return [$positive, $negative];
        }

        $fieldPaths = array_map(static fn (string $path): string => 'rs.f.' . $path, $paths);
        $parts = [];
        if ($values !== []) {
            $parts[] = $this->coalesced($fieldPaths, fn (string $path): array => $this->termQuery($path, $values));
        }
        if ($hasNull) {
            $parts[] = $this->allMissing($fieldPaths);
        }

        $positive = $parts === [] ? $this->matchNone() : (\count($parts) === 1 ? $parts[0] : ['bool' => ['should' => $parts, 'minimum_should_match' => 1]]);

        return [$positive, ['bool' => ['must_not' => [$positive]]]];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function text(Contains|Prefix $filter, StoreContext $context): array
    {
        $resolved = $this->resolver->resolve($filter->field, $context);
        if ($resolved->isList() || !\in_array($resolved->type, [FieldType::ID, FieldType::STRING, FieldType::TEXT, FieldType::ANY], true)) {
            throw NotSupportedByEngine::field($filter->field, 'contains and prefix need a string field');
        }

        $paths = array_map(static fn (string $path): string => 'rs.f.' . $path, $this->paths($resolved));
        $needle = QueryEvaluator::fold($filter->value);

        $leaf = static function (string $path) use ($filter, $needle): array {
            if ($needle === '') {
                return ['exists' => ['field' => $path]];
            }
            if ($filter instanceof Prefix) {
                return ['prefix' => [$path => ['value' => $needle]]];
            }

            return ['wildcard' => [$path => ['value' => '*' . addcslashes($needle, '\\*?') . '*']]];
        };

        return $this->known($paths, $leaf);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function range(Range $filter, StoreContext $context): array
    {
        $resolved = $this->resolver->resolve($filter->field, $context);
        $paths = $this->paths($resolved);
        $type = $resolved->type;
        $bounds = ['gte' => $filter->gte, 'gt' => $filter->gt, 'lte' => $filter->lte, 'lt' => $filter->lt];

        $sortKey = false;
        $extra = [];
        $converted = [];
        foreach ($bounds as $operator => $bound) {
            if ($bound === null) {
                continue;
            }

            if ($type === FieldType::INT || $type === FieldType::FLOAT) {
                if (!is_numeric($bound)) {
                    throw NotSupportedByEngine::field($filter->field, 'range bound is not numeric');
                }
                $converted[$operator] = (float) $bound;
            } elseif ($type === FieldType::DATETIME) {
                if (!\is_string($bound) || preg_match(self::DATE_PATTERN, $bound) !== 1) {
                    throw NotSupportedByEngine::field($filter->field, 'range bound is not a date time with milliseconds');
                }
                $converted[$operator] = $bound;
                $extra = ['format' => IndexSchema::DATE_FORMAT];
            } elseif ($type === FieldType::ID) {
                if (!\is_string($bound)) {
                    throw NotSupportedByEngine::field($filter->field, 'range bound is not a string');
                }
                $converted[$operator] = strtolower($bound);
            } elseif ($type === FieldType::STRING || $type === FieldType::TEXT) {
                if (!\is_string($bound) || !$this->schema->hasSortKey(explode('.', $filter->field)[0])) {
                    throw NotSupportedByEngine::field($filter->field, 'range on this string is not supported');
                }
                $converted[$operator] = $this->sortKeyOf($bound);
                $sortKey = true;
            } else {
                throw NotSupportedByEngine::field($filter->field, 'range is not supported on this field type');
            }
        }

        $prefix = $sortKey ? 'rs.s.' : 'rs.f.';
        $fieldPaths = array_map(static fn (string $path): string => $prefix . $path, $paths);

        $leaf = static fn (string $path): array => $converted === []
            ? ['exists' => ['field' => $path]]
            : ['range' => [$path => $converted + $extra]];

        return $this->known($fieldPaths, $leaf);
    }

    /**
     * Positive set `leaf`, negative set "has a value and not leaf", for filters that are unknown for a missing value.
     *
     * @param list<string> $paths
     * @param \Closure(string): array<string, mixed> $leaf
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function known(array $paths, \Closure $leaf): array
    {
        if (\count($paths) === 1) {
            return [
                $leaf($paths[0]),
                ['bool' => ['filter' => [['exists' => ['field' => $paths[0]]]], 'must_not' => [$leaf($paths[0])]]],
            ];
        }

        $positive = [];
        $negative = [];
        foreach ($paths as $index => $path) {
            $earlier = array_map(static fn (string $p): array => ['exists' => ['field' => $p]], \array_slice($paths, 0, $index));
            $positive[] = ['bool' => ['filter' => [$leaf($path)], 'must_not' => $earlier]];
            $negative[] = ['bool' => ['filter' => [['exists' => ['field' => $path]]], 'must_not' => [...$earlier, $leaf($path)]]];
        }

        return [
            ['bool' => ['should' => $positive, 'minimum_should_match' => 1]],
            ['bool' => ['should' => $negative, 'minimum_should_match' => 1]],
        ];
    }

    /**
     * The first existing path decides, like `COALESCE`.
     *
     * @param list<string> $paths
     * @param \Closure(string): array<string, mixed> $leaf
     *
     * @return array<string, mixed>
     */
    private function coalesced(array $paths, \Closure $leaf): array
    {
        if (\count($paths) === 1) {
            return $leaf($paths[0]);
        }

        $branches = [];
        foreach ($paths as $index => $path) {
            $earlier = array_map(static fn (string $p): array => ['exists' => ['field' => $p]], \array_slice($paths, 0, $index));
            $branches[] = ['bool' => ['filter' => [$leaf($path)], 'must_not' => $earlier]];
        }

        return ['bool' => ['should' => $branches, 'minimum_should_match' => 1]];
    }

    /**
     * @param list<mixed> $values
     *
     * @return array<string, mixed>
     */
    private function termQuery(string $path, array $values): array
    {
        return \count($values) === 1 ? ['term' => [$path => $values[0]]] : ['terms' => [$path => $values]];
    }

    /**
     * Converts an equality candidate to the indexed representation, throws when the DAL's loose comparison cannot be reproduced.
     */
    private function equalsValue(ResolvedField $field, mixed $candidate): string|float|bool
    {
        $unsupported = static fn (string $reason): NotSupportedByEngine => NotSupportedByEngine::field($field->field, $reason);

        if ($field->isList() || $field->type === FieldType::ID) {
            return \is_string($candidate) ? strtolower($candidate) : throw $unsupported('an id is compared with a non-string');
        }

        return match ($field->type) {
            FieldType::STRING, FieldType::TEXT => \is_string($candidate) ? QueryEvaluator::fold($candidate) : throw $unsupported('a string is compared with a non-string'),
            FieldType::ANY => \is_string($candidate) && !is_numeric($candidate) ? QueryEvaluator::fold($candidate) : throw $unsupported('a field of unknown type is only compared with non-numeric strings'),
            FieldType::INT, FieldType::FLOAT => \is_int($candidate) || \is_float($candidate) || (\is_string($candidate) && is_numeric($candidate)) ? (float) $candidate : throw $unsupported('a number is compared with a non-number'),
            FieldType::BOOL => \is_bool($candidate) ? $candidate : throw $unsupported('a boolean is compared with a non-boolean'),
            FieldType::DATETIME => \is_string($candidate) && preg_match(self::DATE_PATTERN, $candidate) === 1 ? $candidate : throw $unsupported('a date time is compared with something else than Y-m-d H:i:s.v'),
            default => throw $unsupported('not comparable'),
        };
    }

    /**
     * @return list<string> dotted paths of the resolved field
     */
    private function paths(ResolvedField $resolved): array
    {
        return $resolved->dottedPaths();
    }

    private function sortKeyOf(string $value): string
    {
        static $collator = null;
        if (!$collator instanceof \Collator) {
            $collator = new \Collator('root');
            $collator->setStrength(\Collator::PRIMARY);
        }

        $key = $collator->getSortKey($value);

        return bin2hex($key === false ? $value : $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchAll(): array
    {
        return ['match_all' => new \stdClass()];
    }

    /**
     * @return array<string, mixed>
     */
    private function matchNone(): array
    {
        return ['match_none' => new \stdClass()];
    }

    /**
     * @return array<string, mixed>
     */
    private function sortClause(Sorting $sorting, StoreContext $context): array
    {
        $resolved = $this->resolver->resolve($sorting->field, $context);
        if ($resolved->isList() || $resolved->type === FieldType::ANY) {
            throw NotSupportedByEngine::field($sorting->field, 'cannot sort by this field');
        }

        $order = strtolower($sorting->direction);
        $missing = $sorting->direction === Sorting::ASC ? '_first' : '_last';
        $prefix = 'rs.f.';
        if ($resolved->type === FieldType::STRING || $resolved->type === FieldType::TEXT) {
            if (!$this->schema->hasSortKey(explode('.', $sorting->field)[0])) {
                throw NotSupportedByEngine::field($sorting->field, 'no sort key for this long text');
            }
            $prefix = 'rs.s.';
        }

        $paths = array_map(static fn (string $path): string => $prefix . $path, $resolved->dottedPaths());
        if (\count($paths) === 1) {
            return [$paths[0] => ['order' => $order, 'missing' => $missing, 'unmapped_type' => $this->unmappedType($resolved)]];
        }

        // a missing value is the smallest value, it sorts first ascending and last descending
        return ['_script' => [
            'type' => 'number',
            'order' => $order,
            'script' => [
                'lang' => 'painless',
                'source' => 'for (String p : params.paths) { if (doc.containsKey(p) && doc[p].size() > 0) { return doc[p].value; } } return -1.0E300;',
                'params' => ['paths' => $paths],
            ],
        ]];
    }

    private function unmappedType(ResolvedField $resolved): string
    {
        return match ($resolved->type) {
            FieldType::INT => 'long',
            FieldType::FLOAT => 'double',
            FieldType::BOOL => 'boolean',
            FieldType::DATETIME => 'date',
            default => 'keyword',
        };
    }
}
