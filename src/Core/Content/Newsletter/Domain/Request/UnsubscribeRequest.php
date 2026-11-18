<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Request;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * @internal
 */
#[Package('after-sales')]
final readonly class UnsubscribeRequest
{
    public function __construct(public string $email)
    {
    }

    public static function fromDataBag(RequestDataBag $bag): self
    {
        $email = $bag->get('email');

        return new self(\is_string($email) ? $email : '');
    }
}
