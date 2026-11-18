<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Response;

use Shopware\Core\Content\Newsletter\Domain\NewsletterStatus;
use Shopware\Core\Framework\Log\Package;

/**
 * Response DTO of the subscribe route, the wire shape is `{status, success}` plus `apiAlias`.
 *
 * @internal
 */
#[Package('after-sales')]
final readonly class NewsletterSubscribeResult
{
    public function __construct(public NewsletterStatus $status)
    {
    }
}
