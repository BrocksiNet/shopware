<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Evaluation;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Aggregation;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Max;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Stats;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Contains;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\EqualsAny;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Multi;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Not;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Prefix;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Range;
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\AggregationResult;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\Schema\ResolvedField;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;

/**
 * The reference semantics of the port, evaluated in PHP over document arrays. The Array adapter answers every query with it; the other
 * adapters translate the same criteria into their engine and the contract suite compares their answers with the ones defined here.
 *
 * String comparison folds case and accents (MySQL `utf8mb4_unicode_ci`, the database the `dal` reference runs on); sorting uses an ICU
 * collator with primary strength, which is close to, but not the same as, the UCA 4.0.0 weights of `utf8mb4_unicode_ci`.
 *
 * @internal
 */
#[Package('framework')]
final class QueryEvaluator
{
    private ?\Collator $collator = null;

    public function __construct(private readonly FieldResolver $resolver = new FieldResolver())
    {
    }

    /**
     * @param iterable<array<string, mixed>> $documents all documents of the store
     */
    public function evaluate(StoreCriteria $criteria, StoreContext $context, iterable $documents): StoreResult
    {
        $filters = $criteria->filters;
        if ($criteria->ids !== []) {
            $filters[] = new EqualsAny('id', $criteria->ids);
        }

        $search = $criteria->search;
        $matching = [];
        $scores = [];
        foreach ($documents as $document) {
            if (!$this->matchesAll($filters, $document, $context)) {
                continue;
            }

            if ($search !== null) {
                if (!$this->matchesSearch($search, $document, $context)) {
                    continue;
                }
                $scores[(string) $document['id']] = $this->score($search, $document, $context);
            }

            $matching[] = $document;
        }

        $aggregations = $this->aggregations($criteria->aggregations, $matching, $context);

        $hits = array_values(array_filter($matching, fn (array $document): bool => $this->matchesAll($criteria->postFilters, $document, $context)));
        $hits = $this->sort($hits, $criteria, $context, $scores);

        if ($criteria->groupField !== null) {
            $hits = $this->collapse($hits, $criteria, $context, $scores);
        }

        $total = $criteria->total === Total::EXACT ? \count($hits) : null;
        $page = \array_slice($hits, $criteria->offset, $criteria->limit);

        $pageScores = [];
        foreach ($page as $document) {
            if (isset($scores[(string) $document['id']])) {
                $pageScores[(string) $document['id']] = $scores[(string) $document['id']];
            }
        }

        return new StoreResult($page, $total, $aggregations, $pageScores);
    }

    /**
     * @param list<Filter> $filters
     * @param array<string, mixed> $document
     */
    public function matchesAll(array $filters, array $document, StoreContext $context): bool
    {
        foreach ($filters as $filter) {
            if ($this->match($filter, $document, $context) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Three-valued: `null` is SQL unknown.
     *
     * @param array<string, mixed> $document
     */
    public function match(Filter $filter, array $document, StoreContext $context): ?bool
    {
        if ($filter instanceof Multi || $filter instanceof Not) {
            $result = $filter->operator === Multi::AND ? true : false;
            foreach ($filter->filters as $inner) {
                $result = $filter->operator === Multi::AND
                    ? self::and($result, $this->match($inner, $document, $context))
                    : self::or($result, $this->match($inner, $document, $context));
            }

            return $filter instanceof Not ? ($result === null ? null : !$result) : $result;
        }

        if ($filter instanceof Equals) {
            return $this->matchesAny($filter->field, [$filter->value], $document, $context);
        }

        if ($filter instanceof EqualsAny) {
            return $this->matchesAny($filter->field, $filter->values, $document, $context);
        }

        if ($filter instanceof Contains || $filter instanceof Prefix) {
            $field = $this->resolver->resolve($filter->field, $context);
            $value = $this->value($field, $document);
            if ($value === null) {
                return null;
            }
            if (\is_array($value)) {
                throw NotSupportedByEngine::field($filter->field, 'contains and prefix need a scalar field');
            }

            $haystack = self::fold((string) self::scalar($value));
            $needle = self::fold($filter->value);

            return $filter instanceof Contains ? str_contains($haystack, $needle) : str_starts_with($haystack, $needle);
        }

        if ($filter instanceof Range) {
            $field = $this->resolver->resolve($filter->field, $context);
            $value = $this->value($field, $document);
            if ($value === null) {
                return null;
            }
            if (\is_array($value)) {
                throw NotSupportedByEngine::field($filter->field, 'range needs a scalar field');
            }

            return ($filter->gte === null || $this->compareValues($value, $filter->gte, $field->type) >= 0)
                && ($filter->gt === null || $this->compareValues($value, $filter->gt, $field->type) > 0)
                && ($filter->lte === null || $this->compareValues($value, $filter->lte, $field->type) <= 0)
                && ($filter->lt === null || $this->compareValues($value, $filter->lt, $field->type) < 0);
        }

        throw NotSupportedByEngine::feature('evaluator', $filter::class);
    }

    /**
     * The first non-missing value along the resolved paths.
     *
     * @param array<string, mixed> $document
     */
    public function value(ResolvedField $field, array $document): mixed
    {
        foreach ($field->paths as $path) {
            $value = $document;
            foreach ($path as $key) {
                if (!\is_array($value) || !\array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * DAL `ProductSearchBuilder`: the keyword rows of the context language must contain a keyword of every token group (and-logic) or one of
     * the original term and the scored keywords (or-logic).
     *
     * @param array<string, mixed> $document
     */
    public function matchesSearch(SearchTerm $search, array $document, StoreContext $context): bool
    {
        $keywords = $this->foldedKeywords($document['searchKeywords'][$context->languageId] ?? []);
        if ($keywords === []) {
            return false;
        }

        if (!$search->andLogic) {
            foreach ($search->anyKeywords() as $keyword) {
                if (isset($keywords[self::fold($keyword)])) {
                    return true;
                }
            }

            return false;
        }

        foreach ($search->tokenKeywords as $group) {
            $found = false;
            foreach ($group as $keyword) {
                if (isset($keywords[self::fold($keyword)])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Score of the DAL score queries, summed over the keyword rows the DAL joins for them (verified on rs_k_corpus, docs/40-read-store-port-and-document.md):
     * - one keyword filter (or-logic, or and-logic with one token): the DAL scores only the rows that pass it,
     *   rows of the context language whose keyword is one of the filter keywords;
     * - and-logic with two or more tokens (one filter per token): the DAL scores every keyword row of the product, of all languages.
     *
     * @param array<string, mixed> $document
     */
    public function score(SearchTerm $search, array $document, StoreContext $context): float
    {
        $rows = $document['searchKeywords'] ?? [];
        $filterKeywords = null;
        if (!$search->andLogic) {
            $filterKeywords = $search->anyKeywords();
        } elseif (\count($search->tokenKeywords) === 1) {
            $filterKeywords = $search->tokenKeywords[0];
        }
        if ($filterKeywords !== null) {
            $rows = [$rows[$context->languageId] ?? []];
            $allowed = array_flip(array_map(self::fold(...), $filterKeywords));
        }

        $score = 0.0;
        $original = self::fold($search->original);
        foreach ($rows as $keywords) {
            foreach ($keywords as $keyword => $ranking) {
                $folded = self::fold((string) $keyword);
                if (isset($allowed) && !isset($allowed[$folded])) {
                    continue;
                }
                foreach ($search->scoredKeywords as $scored) {
                    if (self::fold($scored['keyword']) === $folded) {
                        $score += $scored['score'] * (float) $ranking;
                    }
                }
                if ($original !== '' && str_contains($folded, $original)) {
                    $score += $search->originalScore * (float) $ranking;
                }
            }
        }

        return $score;
    }

    /**
     * Case and accent folding like `utf8mb4_unicode_ci` for the common cases (not `ß` = `ss` and other expansions).
     */
    public static function fold(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
        if (\is_string($normalized)) {
            $value = (string) preg_replace('/\p{Mn}+/u', '', $normalized);
        }

        return mb_strtolower($value);
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param array<string, float> $scores
     *
     * @return list<array<string, mixed>>
     */
    private function sort(array $documents, StoreCriteria $criteria, StoreContext $context, array $scores): array
    {
        if ($criteria->sortings === []) {
            if ($criteria->ids !== [] && $criteria->search === null) {
                $position = array_flip($criteria->ids);
                usort($documents, static fn (array $a, array $b): int => ($position[$a['id']] ?? \PHP_INT_MAX) <=> ($position[$b['id']] ?? \PHP_INT_MAX));
            }

            return $documents;
        }

        $keys = $this->sortKeys($criteria->sortings, $context);
        usort($documents, function (array $a, array $b) use ($keys, $scores): int {
            foreach ($keys as [$field, $direction]) {
                $left = $field === null ? ($scores[(string) $a['id']] ?? 0.0) : $this->value($field, $a);
                $right = $field === null ? ($scores[(string) $b['id']] ?? 0.0) : $this->value($field, $b);
                $result = $this->compareNullable($left, $right, $field->type ?? FieldType::FLOAT);
                if ($result !== 0) {
                    return $direction === Sorting::ASC ? $result : -$result;
                }
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        return $documents;
    }

    /**
     * One document per group value. With a search term see the scored branch below. Without one, groups are ordered like the DAL orders a grouped query: by `MIN(field)` for ascending and `MAX(field)`
     * for descending sortings (`cheapestPrice` always `MIN`), see `CriteriaQueryBuilder::addSortings()`; the representative is the first
     * document of the group in the sorted hits (the DAL picks an arbitrary row, `any_value()`), ties by the representative id.
     *
     * @param list<array<string, mixed>> $sorted
     * @param array<string, float> $scores
     *
     * @return list<array<string, mixed>>
     */
    private function collapse(array $sorted, StoreCriteria $criteria, StoreContext $context, array $scores): array
    {
        \assert($criteria->groupField !== null);
        $groupField = $this->resolver->resolve($criteria->groupField, $context);

        if ($criteria->search !== null) {
            // DAL `EntitySearcher::buildScoreRankedQuery()`: the representative is the highest scoring document of the group (ties by the
            // lowest id) and the groups are ordered by the sortings of their representative, not by `MIN()`/`MAX()` over the group
            $representatives = [];
            foreach ($sorted as $document) {
                $value = $this->value($groupField, $document);
                $groupKey = $value === null ? "\0null" : 'v' . self::fold((string) self::scalar($value));
                $current = $representatives[$groupKey] ?? null;
                if ($current === null) {
                    $representatives[$groupKey] = $document;
                    continue;
                }

                $comparison = ($scores[(string) $document['id']] ?? 0.0) <=> ($scores[(string) $current['id']] ?? 0.0);
                if ($comparison > 0 || ($comparison === 0 && strcmp((string) $document['id'], (string) $current['id']) < 0)) {
                    $representatives[$groupKey] = $document;
                }
            }

            return $this->sort(array_values($representatives), $criteria, $context, $scores);
        }

        $keys = $this->sortKeys($criteria->sortings, $context);

        /** @var array<string, array{representative: array<string, mixed>, aggregates: list<mixed>}> $groups */
        $groups = [];
        foreach ($sorted as $document) {
            $value = $this->value($groupField, $document);
            $groupKey = $value === null ? "\0null" : 'v' . self::fold((string) self::scalar($value));

            $values = [];
            foreach ($keys as $i => [$field]) {
                $values[$i] = $field === null ? ($scores[(string) $document['id']] ?? 0.0) : $this->value($field, $document);
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['representative' => $document, 'aggregates' => $values];
                continue;
            }

            foreach ($keys as $i => [$field, $direction]) {
                $useMin = $direction === Sorting::ASC || ($field !== null && str_starts_with($field->field, 'cheapestPrice'));
                $current = $groups[$groupKey]['aggregates'][$i];
                $candidate = $values[$i];
                if ($candidate === null) {
                    continue;
                }
                $comparison = $current === null ? ($useMin ? -1 : 1) : $this->compareNullable($candidate, $current, $field->type ?? FieldType::FLOAT);
                if (($useMin && $comparison < 0) || (!$useMin && $comparison > 0)) {
                    $groups[$groupKey]['aggregates'][$i] = $candidate;
                }
            }
        }

        $groups = array_values($groups);
        usort($groups, function (array $a, array $b) use ($keys): int {
            foreach ($keys as $i => [$field, $direction]) {
                $result = $this->compareNullable($a['aggregates'][$i], $b['aggregates'][$i], $field->type ?? FieldType::FLOAT);
                if ($result !== 0) {
                    return $direction === Sorting::ASC ? $result : -$result;
                }
            }

            return strcmp((string) $a['representative']['id'], (string) $b['representative']['id']);
        });

        return array_map(static fn (array $group): array => $group['representative'], $groups);
    }

    /**
     * @param list<Sorting> $sortings
     *
     * @return list<array{0: ResolvedField|null, 1: string}> `null` field = score
     */
    private function sortKeys(array $sortings, StoreContext $context): array
    {
        $keys = [];
        foreach ($sortings as $sorting) {
            if ($sorting->field === Sorting::SCORE) {
                $keys[] = [null, $sorting->direction];
                continue;
            }
            $field = $this->resolver->resolve($sorting->field, $context);
            if ($field->isList()) {
                throw NotSupportedByEngine::field($sorting->field, 'cannot sort by a list field');
            }
            $keys[] = [$field, $sorting->direction];
        }

        return $keys;
    }

    /**
     * @param list<Aggregation> $aggregations
     * @param list<array<string, mixed>> $documents
     *
     * @return array<string, AggregationResult>
     */
    private function aggregations(array $aggregations, array $documents, StoreContext $context): array
    {
        $results = [];
        foreach ($aggregations as $aggregation) {
            $field = $this->resolver->resolve($aggregation->field, $context);

            $values = [];
            $buckets = [];
            foreach ($documents as $document) {
                if (!$this->matchesAll($aggregation->filters, $document, $context)) {
                    continue;
                }

                $value = $this->value($field, $document);
                $elements = $field->isList() ? (\is_array($value) && $value !== [] ? array_values(array_unique($value)) : [null]) : [$value];

                foreach ($elements as $element) {
                    if ($aggregation instanceof Terms) {
                        $key = $element === null ? "\0null" : (string) self::scalar($element);
                        $buckets[$key] ??= ['key' => $element === null ? null : self::scalar($element), 'count' => 0];
                        ++$buckets[$key]['count'];
                    }
                    if ($element !== null) {
                        $values[] = self::scalar($element);
                    }
                }
            }

            $results[$aggregation->name] = $this->aggregate($aggregation, $field, $values, $buckets);
        }

        return $results;
    }

    /**
     * @param list<string|int|float|bool> $values
     * @param array<string, array{key: string|int|float|bool|null, count: int}> $buckets
     */
    private function aggregate(Aggregation $aggregation, ResolvedField $field, array $values, array $buckets): AggregationResult
    {
        $numeric = array_map(static fn (string|int|float|bool $value): float => (float) $value, $values);

        return match (true) {
            $aggregation instanceof Min => new MetricResult($aggregation->name, $this->extreme($values, $field->type, -1)),
            $aggregation instanceof Max => new MetricResult($aggregation->name, $this->extreme($values, $field->type, 1)),
            $aggregation instanceof Avg => new MetricResult($aggregation->name, $numeric === [] ? null : array_sum($numeric) / \count($numeric)),
            $aggregation instanceof Sum => new MetricResult($aggregation->name, $numeric === [] ? null : array_sum($numeric)),
            $aggregation instanceof Count => new MetricResult($aggregation->name, \count(array_unique(array_map(static fn (string|int|float|bool $value): string => \is_string($value) ? self::fold($value) : (string) $value, $values)))),
            $aggregation instanceof Stats => new StatsResult(
                $aggregation->name,
                $this->extreme($values, $field->type, -1),
                $this->extreme($values, $field->type, 1),
                $numeric === [] ? null : array_sum($numeric) / \count($numeric),
                $numeric === [] ? null : array_sum($numeric),
            ),
            $aggregation instanceof Terms => new TermsResult($aggregation->name, $this->buckets($aggregation, $field, $buckets)),
            default => throw NotSupportedByEngine::feature('evaluator', $aggregation::class),
        };
    }

    /**
     * @param array<string, array{key: string|int|float|bool|null, count: int}> $buckets
     *
     * @return list<array{key: string|int|float|bool|null, count: int}>
     */
    private function buckets(Terms $terms, ResolvedField $field, array $buckets): array
    {
        $buckets = array_values($buckets);
        if ($terms->limit !== null) {
            // highest count first, ties by the ascending key (the DAL has no order without a sorting, `LIMIT` returns arbitrary groups)
            usort($buckets, fn (array $a, array $b): int => [$b['count'], $this->compareNullable($a['key'], $b['key'], $field->type)] <=> [$a['count'], 0]);
            $buckets = \array_slice($buckets, 0, $terms->limit);
        }
        usort($buckets, fn (array $a, array $b): int => $this->compareNullable($a['key'], $b['key'], $field->type));

        return $buckets;
    }

    /**
     * @param list<string|int|float|bool> $values
     */
    private function extreme(array $values, FieldType $type, int $direction): string|int|float|null
    {
        $best = null;
        foreach ($values as $value) {
            if ($best === null || $this->compareValues($value, $best, $type) * $direction > 0) {
                $best = $value;
            }
        }

        return \is_bool($best) ? (int) $best : $best;
    }

    /**
     * Two-valued and null-safe like the DAL's `<=>` / `IS NULL` for equals; a missing list is unknown (`JSON_CONTAINS(NULL, ...)`).
     *
     * @param list<string|int|float|bool|null> $candidates
     * @param array<string, mixed> $document
     */
    private function matchesAny(string $fieldName, array $candidates, array $document, StoreContext $context): ?bool
    {
        $field = $this->resolver->resolve($fieldName, $context);
        $value = $this->value($field, $document);

        if ($field->isList()) {
            if ($value === null) {
                return \in_array(null, $candidates, true) ? true : null;
            }
            foreach ($candidates as $candidate) {
                if ($candidate === null) {
                    continue;
                }
                foreach ((array) $value as $element) {
                    if ($this->equalValues($element, $candidate, FieldType::ID)) {
                        return true;
                    }
                }
            }

            return false;
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null ? $value === null : ($value !== null && !\is_array($value) && $this->equalValues($value, $candidate, $field->type))) {
                return true;
            }
        }

        return false;
    }

    private function equalValues(mixed $value, string|int|float|bool $candidate, FieldType $type): bool
    {
        $value = self::scalar($value);
        if (\is_bool($value) || \is_bool($candidate)) {
            return (bool) $value === (bool) $candidate;
        }
        if (\is_int($value) || \is_float($value) || \is_int($candidate) || \is_float($candidate)) {
            return is_numeric($value) && is_numeric($candidate) && (float) $value === (float) $candidate;
        }
        if ($type === FieldType::ID || $type === FieldType::LIST) {
            return strtolower($value) === strtolower($candidate);
        }

        return self::fold($value) === self::fold($candidate);
    }

    /**
     * Missing values first (MySQL ascending order).
     */
    private function compareNullable(mixed $left, mixed $right, FieldType $type): int
    {
        if ($left === null || $right === null) {
            return ($left === null ? 0 : 1) <=> ($right === null ? 0 : 1);
        }

        return $this->compareValues($left, $right, $type);
    }

    private function compareValues(mixed $left, mixed $right, FieldType $type): int
    {
        $left = self::scalar($left);
        $right = self::scalar($right);

        if ((\is_int($left) || \is_float($left) || \is_bool($left)) && (\is_int($right) || \is_float($right) || is_numeric($right))) {
            return (float) $left <=> (float) $right;
        }
        if ($type === FieldType::INT || $type === FieldType::FLOAT || $type === FieldType::BOOL) {
            return (float) $left <=> (float) $right;
        }
        if ($type === FieldType::ID || $type === FieldType::DATETIME || $type === FieldType::LIST) {
            return strcmp(strtolower((string) $left), strtolower((string) $right));
        }

        $result = $this->collator()->compare((string) $left, (string) $right);

        return $result === false ? strcmp((string) $left, (string) $right) : $result;
    }

    private function collator(): \Collator
    {
        if ($this->collator === null) {
            $this->collator = new \Collator('root');
            $this->collator->setStrength(\Collator::PRIMARY);
        }

        return $this->collator;
    }

    /**
     * @param array<string, float|int|string> $keywords
     *
     * @return array<string, true>
     */
    private function foldedKeywords(array $keywords): array
    {
        $folded = [];
        foreach (array_keys($keywords) as $keyword) {
            $folded[self::fold((string) $keyword)] = true;
        }

        return $folded;
    }

    private static function scalar(mixed $value): string|int|float|bool
    {
        if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }

        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private static function and(?bool $left, ?bool $right): ?bool
    {
        if ($left === false || $right === false) {
            return false;
        }

        return $left === null || $right === null ? null : true;
    }

    private static function or(?bool $left, ?bool $right): ?bool
    {
        if ($left === true || $right === true) {
            return true;
        }

        return $left === null || $right === null ? null : false;
    }
}
