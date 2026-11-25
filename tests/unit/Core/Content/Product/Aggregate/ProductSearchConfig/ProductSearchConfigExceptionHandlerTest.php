<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\Aggregate\ProductSearchConfig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductSearchConfig\ProductSearchConfigExceptionHandler;
use Shopware\Core\Content\Product\Exception\DuplicateProductSearchConfigLanguageException;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('inventory')]
#[CoversClass(ProductSearchConfigExceptionHandler::class)]
class ProductSearchConfigExceptionHandlerTest extends TestCase
{
    #[DataProvider('duplicateMessages')]
    public function testDuplicateKeyIsMatched(string $message): void
    {
        $result = (new ProductSearchConfigExceptionHandler())->matchException(new \RuntimeException($message));

        static::assertInstanceOf(DuplicateProductSearchConfigLanguageException::class, $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateMessages(): iterable
    {
        yield 'MySQL' => ['An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'a\' for key \'product_search_config.uniq.product_search_config.language_id\''];
        yield 'PostgreSQL' => ["An exception occurred while executing a query: SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"product_search_config.uniq.product_search_config.language_id\"\nDETAIL:  Key (language_id)=(a) already exists."];
    }

    public function testOtherKeyIsNotMatched(): void
    {
        $e = new \RuntimeException('An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'a\' for key \'product.uniq.product.product_number__version_id\'');

        static::assertNull((new ProductSearchConfigExceptionHandler())->matchException($e));
    }
}
