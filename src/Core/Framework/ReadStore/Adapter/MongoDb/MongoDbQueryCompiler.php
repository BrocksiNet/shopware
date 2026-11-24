<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\MongoDb;

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
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\Schema\ResolvedField;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * Translates store criteria into MongoDB query documents over the layout of {@see MongoDbDocument}.
 *
 * The port is three-valued like SQL (`Contains`, `Prefix` and `Range` are unknown for a missing value, so `Not` of them does not match either),
 * MongoDB's `$not` is two-valued. Every filter is therefore compiled twice: `truth($filter, $context, true)` selects the documents for which the
 * filter is true, `truth($filter, $context, false)` the documents for which it is false. `Not` swaps the two, `AND` and `OR` combine them
 * like the truth tables do, and unknown is in neither set.
 *
 * Fields with several paths (`cheapestPrice` per rule, then default) take the first path with a value, like `COALESCE`.
 *
 * @internal
 */
#[Package('framework')]
final class MongoDbQueryCompiler
{
    /**
     * @var array<string, mixed>
     */
    public const NEVER = ['$expr' => false];

    public function __construct(private readonly FieldResolver $resolver = new FieldResolver())
    {
    }

    /**
     * @param list<Filter> $filters
     *
     * @return array<string, mixed> the documents for which every filter is true
     */
    public function match(array $filters, StoreContext $context): array
    {
        return self::all(array_map(fn (Filter $filter): array => $this->truth($filter, $context, true), $filters));
    }

    /**
     * @return array<string, mixed>
     */
    public function truth(Filter $filter, StoreContext $context, bool $want): array
    {
        if ($filter instanceof Not) {
            return $this->truth(new Multi($filter->operator, $filter->filters), $context, !$want);
        }

        if ($filter instanceof Multi) {
            $inner = array_map(fn (Filter $child): array => $this->truth($child, $context, $want), $filter->filters);

            // true: AND needs all children true, OR one; false: AND needs one child false, OR all
            return ($filter->operator === Multi::AND) === $want ? self::all($inner) : self::any($inner);
        }

        if ($filter instanceof Equals) {
            return $this->equality($filter->field, [$filter->value], $context, $want);
        }

        if ($filter instanceof EqualsAny) {
            return $this->equality($filter->field, $filter->values, $context, $want);
        }

        if ($filter instanceof Contains || $filter instanceof Prefix) {
            return $this->text($filter, $context, $want);
        }

        if ($filter instanceof Range) {
            return $this->range($filter, $context, $want);
        }

        throw NotSupportedByEngine::feature('mongodb', $filter::class);
    }

    /**
     * The searchable keywords of the context language, like `ProductSearchBuilder`: the or-logic needs one of the original term and the scored
     * keywords, the and-logic one keyword of every token group.
     *
     * @return array<string, mixed>
     */
    public function search(SearchTerm $search, StoreContext $context): array
    {
        $path = 's.' . self::segment($context->languageId) . '.k';
        $nonEmpty = ['s.' . self::segment($context->languageId) . '.0' => ['$exists' => true]];

        if (!$search->andLogic) {
            return self::all([$nonEmpty, [$path => ['$in' => self::folded($search->anyKeywords())]]]);
        }

        $groups = array_map(static fn (array $group): array => [$path => ['$in' => self::folded($group)]], $search->tokenKeywords);

        return self::all([$nonEmpty, ...$groups]);
    }

    /**
     * The DAL score, see {@see QueryEvaluator::score()}.
     *
     * @return array<string, mixed> expression
     */
    public function score(SearchTerm $search, StoreContext $context): array
    {
        $scored = array_map(
            static fn (array $keyword): array => ['k' => QueryEvaluator::fold($keyword['keyword']), 's' => (float) $keyword['score']],
            $search->scoredKeywords,
        );
        $original = QueryEvaluator::fold($search->original);

        $perKeyword = ['$sum' => ['$map' => [
            'input' => ['$literal' => $scored],
            'as' => 'sc',
            'in' => ['$cond' => [['$eq' => ['$$sc.k', '$$e.k']], ['$multiply' => ['$$sc.s', '$$e.r']], 0]],
        ]]];

        if ($original !== '') {
            $perKeyword = ['$add' => [$perKeyword, ['$cond' => [
                ['$gte' => [['$indexOfCP' => ['$$e.k', ['$literal' => $original]]], 0]],
                ['$multiply' => [$search->originalScore, '$$e.r']],
                0,
            ]]]];
        }

        $filterKeywords = null;
        if (!$search->andLogic) {
            $filterKeywords = $search->anyKeywords();
        } elseif (\count($search->tokenKeywords) === 1) {
            $filterKeywords = $search->tokenKeywords[0];
        }
        if ($filterKeywords !== null) {
            $path = '$s.' . self::segment($context->languageId);
            $allowed = self::folded($filterKeywords);

            return ['$sum' => ['$map' => [
                'input' => ['$filter' => [
                    'input' => ['$ifNull' => [$path, []]],
                    'as' => 'e',
                    'cond' => ['$in' => ['$$e.k', ['$literal' => $allowed]]],
                ]],
                'as' => 'e',
                'in' => $perKeyword,
            ]]];
        }

        return ['$sum' => ['$map' => [
            'input' => ['$objectToArray' => ['$ifNull' => ['$s', (object) []]]],
            'as' => 'l',
            'in' => ['$sum' => ['$map' => ['input' => '$$l.v', 'as' => 'e', 'in' => $perKeyword]]],
        ]]];
    }

    /**
     * @return array{field: ResolvedField, path: string, expression: mixed, single: bool} the resolved field, the dotted path (first path) and the
     *                                                                                    value expression (`COALESCE` over all paths) in the folded (`f`) or the raw (`d`) copy
     */
    public function value(string $fieldName, StoreContext $context, string $root = 'f'): array
    {
        $field = $this->resolver->resolve($fieldName, $context);
        $paths = array_map(fn (array $path): string => $this->path($path, $root), $field->paths);

        return [
            'field' => $field,
            'path' => $paths[0],
            'expression' => \count($paths) === 1 ? '$' . $paths[0] : ['$ifNull' => array_map(static fn (string $path): string => '$' . $path, $paths)],
            'single' => \count($paths) === 1,
        ];
    }

    /**
     * @param list<string> $path
     */
    public function path(array $path, string $root = 'f'): string
    {
        return $root . '.' . implode('.', array_map(self::segment(...), $path));
    }

    /**
     * @param list<array<string, mixed>> $queries
     *
     * @return array<string, mixed>
     */
    public static function all(array $queries): array
    {
        $queries = array_values(array_filter($queries, static fn (array $query): bool => $query !== []));

        return match (\count($queries)) {
            0 => [],
            1 => $queries[0],
            default => ['$and' => $queries],
        };
    }

    /**
     * @param list<array<string, mixed>> $queries
     *
     * @return array<string, mixed>
     */
    public static function any(array $queries): array
    {
        if (\in_array([], $queries, true)) {
            return [];
        }

        $queries = array_values(array_filter($queries, static fn (array $query): bool => $query !== self::NEVER));

        return match (\count($queries)) {
            0 => self::NEVER,
            1 => $queries[0],
            default => ['$or' => $queries],
        };
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private static function negate(array $query): array
    {
        if ($query === []) {
            return self::NEVER;
        }

        if ($query === self::NEVER) {
            return [];
        }

        return ['$nor' => [$query]];
    }

    /**
     * A path holds a value. `{path: {$ne: null}}` alone is wrong below an empty array (an empty PHP array is a BSON array, and equality with
     * null does not match a path through it), so presence is `$exists` and `$ne` together.
     *
     * @return array<string, mixed>
     */
    private static function present(string $path): array
    {
        return [$path => ['$exists' => true, '$ne' => null]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function absent(string $path): array
    {
        return ['$nor' => [self::present($path)]];
    }

    /**
     * Path segments end up in dotted paths: MongoDB reads dots, a leading dollar and numbers (array positions) specially.
     */
    private static function segment(string $segment): string
    {
        if ($segment === '' || str_contains($segment, '.') || str_starts_with($segment, '$') || ctype_digit($segment)) {
            throw NotSupportedByEngine::field($segment, 'not usable as a MongoDB path segment');
        }

        return $segment;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function folded(array $values): array
    {
        return array_values(array_unique(array_map(QueryEvaluator::fold(...), $values)));
    }

    /**
     * @param list<string|int|float|bool|null> $candidates
     *
     * @return array<string, mixed>
     */
    private function equality(string $fieldName, array $candidates, StoreContext $context, bool $want): array
    {
        $field = $this->resolver->resolve($fieldName, $context);
        $paths = array_map(fn (array $path): string => $this->path($path), $field->paths);
        $hasNull = \in_array(null, $candidates, true);
        $values = $this->equalityValues($field, array_values(array_filter($candidates, static fn (mixed $candidate): bool => $candidate !== null)));

        if ($field->isList()) {
            $path = $paths[0];
            $anyElement = $values === [] ? self::NEVER : [$path => ['$in' => $values]];

            if ($want) {
                return self::any([$anyElement, $hasNull ? self::absent($path) : self::NEVER]);
            }

            // a missing list is unknown, a present list that holds none of the candidates is false
            return self::all([self::present($path), self::negate($anyElement)]);
        }

        // scalar equality is two-valued and null-safe
        $nullMatch = $hasNull ? self::all(array_map(static fn (string $path): array => self::absent($path), $paths)) : self::NEVER;
        $valueMatch = $values === [] ? self::NEVER : $this->coalesce($paths, static fn (string $path): array => [$path => ['$in' => $values]]);
        $true = self::any([$valueMatch, $nullMatch]);

        return $want ? $true : self::negate($true);
    }

    /**
     * @param list<string|int|float|bool> $candidates
     *
     * @return list<string|int|float|bool>
     */
    private function equalityValues(ResolvedField $field, array $candidates): array
    {
        $values = [];
        foreach ($candidates as $candidate) {
            switch ($field->type) {
                case FieldType::BOOL:
                    if (!\is_bool($candidate) && !\is_int($candidate)) {
                        throw NotSupportedByEngine::field($field->field, 'a bool field is compared with a bool or an int only');
                    }
                    $values[] = (bool) $candidate;

                    break;
                case FieldType::INT:
                case FieldType::FLOAT:
                    if (\is_int($candidate) || \is_float($candidate)) {
                        $values[] = $candidate;
                    } elseif (\is_string($candidate) && is_numeric($candidate)) {
                        $values[] = (float) $candidate;
                    } else {
                        throw NotSupportedByEngine::field($field->field, 'a numeric field is compared with a number only');
                    }

                    break;
                case FieldType::ID:
                case FieldType::LIST:
                    if (!\is_string($candidate)) {
                        throw NotSupportedByEngine::field($field->field, 'an id field is compared with strings only');
                    }
                    $values[] = strtolower($candidate);

                    break;
                case FieldType::STRING:
                case FieldType::TEXT:
                case FieldType::DATETIME:
                    if (!\is_string($candidate)) {
                        throw NotSupportedByEngine::field($field->field, 'a string field is compared with strings only');
                    }
                    $values[] = QueryEvaluator::fold($candidate);

                    break;
                case FieldType::ANY:
                    // custom fields: a string matches strings (folded), numbers by value and, as the reference does, booleans by truthiness
                    if (!\is_string($candidate)) {
                        throw NotSupportedByEngine::field($field->field, 'a custom field is compared with strings only');
                    }
                    $values[] = QueryEvaluator::fold($candidate);
                    if (is_numeric($candidate)) {
                        $values[] = (float) $candidate;
                    }
                    $values[] = (bool) $candidate;

                    break;
                default:
                    throw NotSupportedByEngine::field($field->field, 'cannot be compared for equality');
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function text(Contains|Prefix $filter, StoreContext $context, bool $want): array
    {
        $field = $this->resolver->resolve($filter->field, $context);
        if (!\in_array($field->type, [FieldType::STRING, FieldType::TEXT, FieldType::ID, FieldType::DATETIME], true)) {
            throw NotSupportedByEngine::field($filter->field, 'contains and prefix need a string field');
        }

        $needle = QueryEvaluator::fold($filter->value);
        if (str_contains($needle, "\0")) {
            throw NotSupportedByEngine::field($filter->field, 'a regular expression cannot hold a NUL byte');
        }
        $regex = ($filter instanceof Prefix ? '^' : '') . preg_quote($needle, '/');

        $paths = array_map(fn (array $path): string => $this->path($path), $field->paths);

        return $this->coalesce($paths, static fn (string $path): array => $want
            ? [$path => ['$regex' => $regex]]
            : self::all([self::present($path), self::negate([$path => ['$regex' => $regex]])]));
    }

    /**
     * @return array<string, mixed>
     */
    private function range(Range $filter, StoreContext $context, bool $want): array
    {
        $field = $this->resolver->resolve($filter->field, $context);
        $numeric = \in_array($field->type, [FieldType::INT, FieldType::FLOAT], true);
        if (!$numeric && !\in_array($field->type, [FieldType::STRING, FieldType::TEXT, FieldType::ID, FieldType::DATETIME], true)) {
            throw NotSupportedByEngine::field($filter->field, 'range needs a number, a string or a date time field');
        }

        $operators = [];
        foreach (['$gte' => $filter->gte, '$gt' => $filter->gt, '$lte' => $filter->lte, '$lt' => $filter->lt] as $operator => $bound) {
            if ($bound === null) {
                continue;
            }

            if ($numeric) {
                if (!is_numeric($bound)) {
                    throw NotSupportedByEngine::field($filter->field, 'a numeric field is compared with numbers only');
                }
                $operators[$operator] = \is_string($bound) ? (float) $bound : $bound;
            } else {
                if (!\is_string($bound)) {
                    throw NotSupportedByEngine::field($filter->field, 'a string field is compared with strings only');
                }
                $operators[$operator] = QueryEvaluator::fold($bound);
            }
        }

        $paths = array_map(fn (array $path): string => $this->path($path), $field->paths);

        return $this->coalesce($paths, static function (string $path) use ($operators, $want): array {
            $nonNull = self::present($path);
            // without bounds every present value matches
            $inside = $operators === [] ? $nonNull : [$path => $operators];

            return $want ? $inside : self::all([$nonNull, self::negate($inside)]);
        });
    }

    /**
     * The first path that holds a value decides: `or(and(path 1 null, path 2 satisfies), ...)`.
     *
     * @param list<string> $paths
     * @param \Closure(string): array<string, mixed> $condition
     *
     * @return array<string, mixed>
     */
    private function coalesce(array $paths, \Closure $condition): array
    {
        $branches = [];
        $missing = [];
        foreach ($paths as $path) {
            $branches[] = self::all([...$missing, $condition($path)]);
            $missing[] = self::absent($path);
        }

        return self::any($branches);
    }
}
