<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\Aggregate\ProductSearchConfigField;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductSearchConfigField\ProductSearchConfigFieldExceptionHandler;
use Shopware\Core\Content\Product\Exception\DuplicateProductSearchConfigFieldException;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('inventory')]
#[CoversClass(ProductSearchConfigFieldExceptionHandler::class)]
class ProductSearchConfigFieldExceptionHandlerTest extends TestCase
{
    #[DataProvider('duplicateMessages')]
    public function testDuplicateKeyIsMatched(string $message): void
    {
        $result = (new ProductSearchConfigFieldExceptionHandler())->matchException(new \RuntimeException($message));

        static::assertInstanceOf(DuplicateProductSearchConfigFieldException::class, $result);
        static::assertSame('Product search config with field name already exists.', $result->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateMessages(): iterable
    {
        yield 'MySQL' => ['An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'name-0a1b\' for key \'product_search_config_field.uniq.search_config_field.field__config_id\''];
        yield 'PostgreSQL' => ["An exception occurred while executing a query: SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"product_search_config_field.uniq.search_config_field.f_abaf29e3\"\nDETAIL:  Key (field, product_search_config_id)=(name, 0a1b) already exists."];
    }

    public function testOtherKeyIsNotMatched(): void
    {
        $e = new \RuntimeException('An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'a\' for key \'product.uniq.product.product_number__version_id\'');

        static::assertNull((new ProductSearchConfigFieldExceptionHandler())->matchException($e));
    }
}
