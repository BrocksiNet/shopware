<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
enum Total
{
    case NONE;
    case EXACT;
}
