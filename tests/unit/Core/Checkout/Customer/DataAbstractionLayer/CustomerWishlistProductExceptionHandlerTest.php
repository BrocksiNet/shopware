<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Customer\DataAbstractionLayer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerException;
use Shopware\Core\Checkout\Customer\DataAbstractionLayer\CustomerWishlistProductExceptionHandler;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(CustomerWishlistProductExceptionHandler::class)]
class CustomerWishlistProductExceptionHandlerTest extends TestCase
{
    #[DataProvider('duplicateMessages')]
    public function testDuplicateKeyIsMatched(string $message): void
    {
        $result = (new CustomerWishlistProductExceptionHandler())->matchException(new \RuntimeException($message));

        static::assertInstanceOf(CustomerException::class, $result);
        static::assertSame(CustomerException::DUPLICATE_WISHLIST_PRODUCT, $result->getErrorCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateMessages(): iterable
    {
        yield 'MySQL' => ['An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'a-b\' for key \'customer_wishlist_product.uniq.customer_wishlist.sales_channel_id__customer_id\''];
        yield 'PostgreSQL' => ["An exception occurred while executing a query: SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"customer_wishlist_product.uniq.customer_wishlist.sales_4878ce6d\"\nDETAIL:  Key (customer_wishlist_id, product_id)=(a, b) already exists."];
    }

    public function testOtherKeyIsNotMatched(): void
    {
        $e = new \RuntimeException('An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'a\' for key \'product.uniq.product.product_number__version_id\'');

        static::assertNull((new CustomerWishlistProductExceptionHandler())->matchException($e));
    }
}
