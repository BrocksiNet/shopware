<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\SalesChannel\Sorting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Exception\DuplicateProductSortingKeyException;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingExceptionHandler;

/**
 * @internal
 */
#[CoversClass(ProductSortingExceptionHandler::class)]
class ProductSortingExceptionHandlerTest extends TestCase
{
    public static function duplicateUrlKeyProvider(): \Generator
    {
        yield 'MySQL' => ['SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'price-asc\' for key \'product_sorting.uniq.product_sorting.url_key\''];
        yield 'MariaDB' => ['SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'price-asc\' for key \'uniq.product_sorting.url_key\''];
        yield 'PostgreSQL' => ["SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"product_sorting.uniq.product_sorting.url_key\"\nDETAIL:  Key (url_key)=(price-asc) already exists."];
    }

    #[DataProvider('duplicateUrlKeyProvider')]
    public function testDuplicateUrlKey(string $message): void
    {
        $exception = (new ProductSortingExceptionHandler())->matchException(new \RuntimeException($message));

        static::assertInstanceOf(DuplicateProductSortingKeyException::class, $exception);
        static::assertSame('Sorting with key "price-asc" already exists.', $exception->getMessage());
    }

    public function testOtherViolationIsNotMatched(): void
    {
        static::assertNull((new ProductSortingExceptionHandler())->matchException(
            new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'x\' for key \'product.uniq.product.product_number__version_id\'')
        ));
    }
}
