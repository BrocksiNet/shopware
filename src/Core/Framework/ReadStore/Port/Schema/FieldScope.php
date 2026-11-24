<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Schema;

use Shopware\Core\Framework\Log\Package;

/**
 * How a field is keyed by context. A scoped field is a map in the document, e.g. `name: {<languageId>: 'Shirt'}`, the
 * {@see FieldResolver} picks the key of the {@see \Shopware\Core\Framework\ReadStore\Port\StoreContext}. Values are resolved at
 * build time (language fallback chain, parent inheritance, currency factor), so query time never needs a fallback, except for the
 * rule ids of `cheapestPrice`, which depend on the customer.
 *
 * @internal
 */
#[Package('framework')]
enum FieldScope: string
{
    case NONE = 'none';
    case LANGUAGE = 'language';
    case CURRENCY = 'currency';
    case SALES_CHANNEL = 'sales_channel';
    /**
     * `{<ruleId>|default: {<currencyId>: {gross, net, listPrice?: {gross, net}, percentage?: {gross, net}}}}`
     */
    case RULE_CURRENCY = 'rule_currency';
}
