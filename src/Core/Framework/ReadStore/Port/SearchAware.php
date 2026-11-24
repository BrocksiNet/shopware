<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;

/**
 * Storefront word search: `StoreCriteria::$search` must be set, everything else behaves like {@see FilterAware::filter()}.
 *
 * The {@see Criteria\SearchTerm} carries the keywords Shopware's `ProductSearchTermInterpreter` resolved for the term, so an engine can answer
 * like the DAL (`ProductSearchBuilder`): a document matches when its `searchKeywords` of the context language contain one keyword of every
 * keyword group (`andLogic`) or of any group (or-logic). Engines with their own full-text search may answer with it instead; they must say so
 * in their capability matrix, because hits and order then differ from the DAL.
 *
 * @internal
 */
#[Package('framework')]
interface SearchAware
{
    /**
     * @throws NotSupportedByEngine
     */
    public function search(StoreCriteria $criteria, StoreContext $context): StoreResult;
}
