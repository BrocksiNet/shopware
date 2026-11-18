<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Request;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * @internal
 */
#[Package('after-sales')]
final readonly class ConfirmRequest
{
    public function __construct(
        public string $hash,
        public ?string $em,
    ) {
    }

    public static function fromDataBag(RequestDataBag $bag): self
    {
        $hash = $bag->get('hash', '');
        $em = $bag->get('em');

        return new self(\is_scalar($hash) ? (string) $hash : '', \is_scalar($em) ? (string) $em : null);
    }
}
