<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('after-sales')]
enum NewsletterStatus: string
{
    case NotSet = 'notSet';
    case OptIn = 'optIn';
    case OptOut = 'optOut';
    case Direct = 'direct';
}
