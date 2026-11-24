<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

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
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\Schema\ResolvedField;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * Turns filters, sortings and search terms into SQL for one {@see JsonDialect}. Every field goes through the
 * {@see FieldResolver}. The reference is {@see QueryEvaluator}: where the SQL cannot give the same answer for every
 * possible input, the compiler throws {@see NotSupportedByEngine}.
 *
 * Filter expressions are three valued (true, false, NULL for unknown) exactly like the reference, a row matches when
 * the expression is true.
 *
 * @internal
 */
#[Package('framework')]
final class SqlQueryCompiler
{
    public function __construct(
        private readonly JsonDialect $dialect,
        private readonly FieldResolver $resolver,
    ) {
    }

    /**
     * @param list<Filter> $filters
     *
     * @return list<string>
     */
    public function conditions(array $filters, StoreContext $context, Binder $binder, SqlScope $scope): array
    {
        return array_map(fn (Filter $filter): string => $this->filter($filter, $context, $binder, $scope), $filters);
    }

    public function filter(Filter $filter, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        if ($filter instanceof Multi || $filter instanceof Not) {
            $glue = $filter->operator === Multi::AND ? ' AND ' : ' OR ';
            $parts = array_map(fn (Filter $inner): string => $this->filter($inner, $context, $binder, $scope), array_values($filter->filters));
            $joined = $parts === [] ? ($filter->operator === Multi::AND ? 'TRUE' : 'FALSE') : '(' . implode($glue, $parts) . ')';

            return $filter instanceof Not ? 'NOT ' . $joined : $joined;
        }

        if ($filter instanceof Equals) {
            return $this->equalsAny($filter->field, [$filter->value], $context, $binder, $scope);
        }

        if ($filter instanceof EqualsAny) {
            return $this->equalsAny($filter->field, array_values($filter->values), $context, $binder, $scope);
        }

        if ($filter instanceof Contains || $filter instanceof Prefix) {
            return $this->like($filter, $context, $binder, $scope);
        }

        if ($filter instanceof Range) {
            return $this->range($filter, $context, $binder, $scope);
        }

        throw NotSupportedByEngine::feature($this->dialect->name(), $filter::class);
    }

    /**
     * The plain value of a field in its native type: number, bool (1 or 0) or text. The first non null path wins.
     */
    public function native(ResolvedField $field, Binder $binder, SqlScope $scope): string
    {
        $doc = $scope->alias . '.doc';
        $parts = [];
        foreach ($field->paths as $path) {
            $parts[] = match ($field->type) {
                FieldType::INT, FieldType::FLOAT => $this->dialect->number($doc, $path, $binder),
                FieldType::BOOL => $this->dialect->bool($doc, $path, $binder),
                FieldType::ID, FieldType::STRING, FieldType::TEXT, FieldType::DATETIME => $this->dialect->text($doc, $path, $binder),
                default => throw NotSupportedByEngine::field($field->field, 'a field of type ' . $field->type->value . ' has no single native value'),
            };
        }

        return \count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
    }

    public function search(SearchTerm $search, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        $exists = function (string $extra) use ($context, $binder, $scope): string {
            return 'EXISTS (SELECT 1 FROM ' . $this->dialect->quote($scope->keywords) . ' kw WHERE kw.product_id = ' . $scope->alias . '.id AND kw.language_id = ' . $binder->bind($context->languageId) . $extra . ')';
        };
        $in = fn (array $keywords): string => $keywords === []
            ? ' AND FALSE'
            : ' AND kw.keyword_fold IN (' . implode(', ', array_map(fn (string $keyword): string => $binder->bind(QueryEvaluator::fold($keyword)), $keywords)) . ')';

        if (!$search->andLogic) {
            return $exists($in($search->anyKeywords()));
        }

        // and logic: the product needs a keyword in the language at all, and one hit per token group
        $parts = [$exists('')];
        foreach ($search->tokenKeywords as $group) {
            $parts[] = $exists($in(array_values($group)));
        }

        return '(' . implode(' AND ', $parts) . ')';
    }

    /**
     * Sum of `score * ranking` over the keyword rows the DAL joins, see {@see QueryEvaluator::score()}.
     */
    public function score(SearchTerm $search, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        $terms = [];
        foreach ($search->scoredKeywords as $scored) {
            $terms[] = 'CASE WHEN kw.keyword_fold = ' . $binder->bind(QueryEvaluator::fold($scored['keyword'])) . ' THEN ' . $this->dialect->toFloat($binder->bind((float) $scored['score'])) . ' ELSE 0 END';
        }

        $original = QueryEvaluator::fold($search->original);
        if ($original !== '') {
            $terms[] = 'CASE WHEN ' . $this->dialect->binaryContains('kw.keyword_fold', $original, $binder) . ' THEN ' . $this->dialect->toFloat($binder->bind($search->originalScore)) . ' ELSE 0 END';
        }

        if ($terms === []) {
            return '0';
        }

        $filter = '';
        $filterKeywords = null;
        if (!$search->andLogic) {
            $filterKeywords = $search->anyKeywords();
        } elseif (\count($search->tokenKeywords) === 1) {
            $filterKeywords = $search->tokenKeywords[0];
        }
        if ($filterKeywords !== null) {
            $lang = $binder->bind($context->languageId);
            if ($filterKeywords === []) {
                return '0';
            }
            $in = implode(', ', array_map(fn (string $keyword): string => $binder->bind(QueryEvaluator::fold($keyword)), array_values($filterKeywords)));
            $filter = ' AND kw.language_id = ' . $lang . ' AND kw.keyword_fold IN (' . $in . ')';
        }

        return 'COALESCE((SELECT SUM(kw.ranking * (' . implode(' + ', $terms) . ')) FROM ' . $this->dialect->quote($scope->keywords) . ' kw WHERE kw.product_id = ' . $scope->alias . '.id' . $filter . '), 0)';
    }

    /**
     * @param list<Sorting> $sortings
     *
     * @return list<SqlSortKey> the expression of the score is empty and replaced by the caller
     */
    public function sortKeys(array $sortings, StoreContext $context, Binder $binder, SqlScope $scope, string $scoreColumn): array
    {
        $keys = [];
        foreach ($sortings as $sorting) {
            if ($sorting->field === Sorting::SCORE) {
                $keys[] = new SqlSortKey($scoreColumn, 'num', $sorting->direction === Sorting::ASC ? 'ASC' : 'DESC', FieldType::FLOAT, $sorting->direction === Sorting::ASC);
                continue;
            }

            $field = $this->resolver->resolve($sorting->field, $context);
            if ($field->isList()) {
                throw NotSupportedByEngine::field($sorting->field, 'cannot sort by a list field');
            }

            $mode = match ($field->type) {
                FieldType::INT, FieldType::FLOAT, FieldType::BOOL => 'num',
                FieldType::ID, FieldType::DATETIME => 'bin',
                FieldType::STRING, FieldType::TEXT => 'ci',
                default => throw NotSupportedByEngine::field($sorting->field, 'cannot sort by a field of type ' . $field->type->value),
            };

            $keys[] = new SqlSortKey(
                $this->native($field, $binder, $scope),
                $mode,
                $sorting->direction === Sorting::ASC ? 'ASC' : 'DESC',
                $field->type,
                $sorting->direction === Sorting::ASC || str_starts_with($sorting->field, 'cheapestPrice'),
            );
        }

        return $keys;
    }

    /**
     * The key expression with the comparison rules of its mode, applied to a column or an expression.
     */
    public function compared(SqlSortKey $key, string $column): string
    {
        return match ($key->mode) {
            'bin' => $this->dialect->binaryLower($column),
            'ci' => $this->dialect->ciCollate($column),
            default => $column,
        };
    }

    public function orderTerm(SqlSortKey $key, string $column): string
    {
        return $this->dialect->orderTerm($this->compared($key, $column), $key->direction);
    }

    /**
     * The collapse group of a document: the lower cased id (the reference folds the value, an id folds to itself).
     */
    public function groupKey(string $field, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        $resolved = $this->resolver->resolve($field, $context);
        if ($resolved->type !== FieldType::ID) {
            throw NotSupportedByEngine::field($field, 'collapse is only answered for id fields');
        }

        return $this->dialect->binaryLower($this->native($resolved, $binder, $scope));
    }

    /**
     * @param list<string|int|float|bool|null> $candidates
     */
    private function equalsAny(string $fieldName, array $candidates, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        $field = $this->resolver->resolve($fieldName, $context);
        $hasNull = \in_array(null, $candidates, true);
        $values = array_values(array_filter($candidates, static fn (mixed $candidate): bool => $candidate !== null));
        $doc = $scope->alias . '.doc';

        if ($field->isList()) {
            $path = $field->paths[0];
            $contains = [];
            foreach ($values as $value) {
                if (!\is_string($value)) {
                    throw NotSupportedByEngine::field($fieldName, 'list fields are only compared with string candidates');
                }
                $contains[] = $this->dialect->arrayContains($doc, $path, strtolower($value), $binder);
            }
            $inner = $contains === [] ? 'FALSE' : 'COALESCE((' . implode(') OR (', $contains) . '), FALSE)';

            // a missing list is unknown, unless null is a candidate
            return 'CASE WHEN COALESCE(' . $this->dialect->isArray($doc, $path, $binder) . ', FALSE) THEN ' . $inner . ' ELSE ' . ($hasNull ? 'TRUE' : 'NULL') . ' END';
        }

        $parts = [];
        if ($hasNull) {
            $parts[] = $field->type === FieldType::ANY
                ? '(' . $this->dialect->kind($doc, $field->paths[0], $binder) . ' IS NULL)'
                : '(' . $this->native($field, $binder, $scope) . ' IS NULL)';
        }

        if ($field->type === FieldType::ID && $values !== []) {
            foreach ($values as $value) {
                if (!\is_string($value)) {
                    throw NotSupportedByEngine::field($fieldName, 'id fields are only compared with string candidates');
                }
            }
            $lowered = array_map(fn (string $value): string => $binder->bind(strtolower($value)), $values);
            // the id column holds the lower case id, so it is compared directly and keeps its index
            $expression = $field->paths === [['id']] ? $scope->alias . '.id' : $this->dialect->binaryLower($this->native($field, $binder, $scope));
            $parts[] = $expression . ' IN (' . implode(', ', $lowered) . ')';
        } else {
            foreach ($values as $value) {
                $parts[] = $this->equalsScalar($field, $value, $binder, $scope);
            }
        }

        return $parts === [] ? 'FALSE' : 'COALESCE((' . implode(' OR ', $parts) . '), FALSE)';
    }

    private function equalsScalar(ResolvedField $field, mixed $candidate, Binder $binder, SqlScope $scope): string
    {
        $name = $field->field;

        switch ($field->type) {
            case FieldType::STRING:
            case FieldType::TEXT:
            case FieldType::DATETIME:
                if (!\is_string($candidate)) {
                    throw NotSupportedByEngine::field($name, 'string fields are only compared with string candidates');
                }

                return $this->dialect->ciEquals($this->native($field, $binder, $scope), $candidate, $binder);

            case FieldType::INT:
            case FieldType::FLOAT:
                $number = $this->native($field, $binder, $scope);
                if (\is_bool($candidate)) {
                    return '(CASE WHEN ' . $number . ' <> 0 THEN 1 ELSE 0 END) = ' . ($candidate ? 1 : 0);
                }
                if (\is_string($candidate) && !is_numeric($candidate)) {
                    return 'FALSE';
                }

                return $number . ' = ' . $this->dialect->toFloat($binder->bind((float) $candidate));

            case FieldType::BOOL:
                return $this->native($field, $binder, $scope) . ' = ' . ($this->truthy($candidate) ? 1 : 0);

            case FieldType::ANY:
                if (!\is_string($candidate)) {
                    throw NotSupportedByEngine::field($name, 'custom fields and extensions are only compared with string candidates');
                }
                $doc = $scope->alias . '.doc';
                $path = $field->paths[0];

                return 'CASE ' . $this->dialect->kind($doc, $path, $binder)
                    . ' WHEN \'S\' THEN ' . $this->dialect->ciEquals($this->dialect->text($doc, $path, $binder), $candidate, $binder)
                    . ' WHEN \'N\' THEN ' . (is_numeric($candidate) ? $this->dialect->number($doc, $path, $binder) . ' = ' . $this->dialect->toFloat($binder->bind((float) $candidate)) : 'FALSE')
                    . ' WHEN \'B\' THEN ' . $this->dialect->bool($doc, $path, $binder) . ' = ' . ($this->truthy($candidate) ? 1 : 0)
                    . ' ELSE FALSE END';

            default:
                throw NotSupportedByEngine::field($name, 'a field of type ' . $field->type->value . ' cannot be compared');
        }
    }

    /**
     * PHP's `(bool)` of a candidate, the reference compares a boolean value with the truthiness of the candidate.
     */
    private function truthy(mixed $candidate): bool
    {
        if (\is_string($candidate)) {
            return $candidate !== '' && $candidate !== '0';
        }

        return (bool) $candidate;
    }

    private function like(Contains|Prefix $filter, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        $field = $this->resolver->resolve($filter->field, $context);
        if ($field->isList() || !\in_array($field->type, [FieldType::ID, FieldType::STRING, FieldType::TEXT, FieldType::DATETIME], true)) {
            throw NotSupportedByEngine::field($filter->field, 'contains and prefix are only answered for string fields');
        }

        return $this->dialect->ciLike($this->native($field, $binder, $scope), $filter->value, $filter instanceof Contains ? 'contains' : 'prefix', $binder);
    }

    private function range(Range $filter, StoreContext $context, Binder $binder, SqlScope $scope): string
    {
        $field = $this->resolver->resolve($filter->field, $context);
        $bounds = array_filter([
            '>=' => $filter->gte,
            '>' => $filter->gt,
            '<=' => $filter->lte,
            '<' => $filter->lt,
        ], static fn (int|float|string|null $bound): bool => $bound !== null);

        $value = match ($field->type) {
            FieldType::INT, FieldType::FLOAT, FieldType::ID, FieldType::DATETIME, FieldType::STRING, FieldType::TEXT => $this->native($field, $binder, $scope),
            default => throw NotSupportedByEngine::field($filter->field, 'a range is not answered for a field of type ' . $field->type->value),
        };

        if ($bounds === []) {
            return 'CASE WHEN ' . $value . ' IS NULL THEN NULL ELSE TRUE END';
        }

        $terms = [];
        foreach ($bounds as $operator => $bound) {
            if ($field->type === FieldType::INT || $field->type === FieldType::FLOAT) {
                if (\is_string($bound) && !is_numeric($bound)) {
                    throw NotSupportedByEngine::field($filter->field, 'a numeric field is only compared with numeric bounds');
                }
                $terms[] = $value . ' ' . $operator . ' ' . $this->dialect->toFloat($binder->bind((float) $bound));
                continue;
            }

            if (!\is_string($bound)) {
                throw NotSupportedByEngine::field($filter->field, 'a string field is only compared with string bounds');
            }

            $terms[] = match ($field->type) {
                FieldType::ID, FieldType::DATETIME => $this->dialect->binaryLower($value) . ' ' . $operator . ' ' . $binder->bind(strtolower($bound)),
                default => '(' . $this->dialect->ciCollate($value) . ') ' . $operator . ' ' . $binder->bind($bound),
            };
        }

        return '(' . implode(' AND ', $terms) . ')';
    }
}
