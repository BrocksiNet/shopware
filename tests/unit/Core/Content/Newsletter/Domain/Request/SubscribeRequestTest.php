<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Newsletter\Domain\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Newsletter\Domain\Request\SubscribeRequest;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * @internal
 */
#[Package('after-sales')]
#[CoversClass(SubscribeRequest::class)]
class SubscribeRequestTest extends TestCase
{
    public function testExplicitNullCountsAsPresentAndMissingDoesNot(): void
    {
        $request = SubscribeRequest::fromDataBag(new RequestDataBag([
            'email' => 'a@example.com',
            'option' => 'subscribe',
            'firstName' => null,
            'lastName' => 'Doe',
        ]));

        static::assertNull($request->firstName);
        static::assertNull($request->city);
        static::assertSame(['firstName', 'lastName'], $request->present);
    }
}
