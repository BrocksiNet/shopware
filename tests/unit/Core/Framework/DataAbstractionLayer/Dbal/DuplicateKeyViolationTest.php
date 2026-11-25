<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DataAbstractionLayer\Dbal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\DuplicateKeyViolation;

/**
 * @internal
 */
#[CoversClass(DuplicateKeyViolation::class)]
class DuplicateKeyViolationTest extends TestCase
{
    public function testMySqlWithTablePrefix(): void
    {
        $violation = DuplicateKeyViolation::matching(
            new \RuntimeException('An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'EUR\' for key \'currency.uniq.currency.iso_code\''),
            'currency',
            'uniq.currency.iso_code'
        );

        static::assertNotNull($violation);
        static::assertSame('EUR', $violation->entry);
        static::assertNull($violation->columnValues);
    }

    public function testMariaDbWithoutTablePrefix(): void
    {
        $violation = DuplicateKeyViolation::matching(
            new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'SW1-abc\' for key \'uniq.product.product_number__version_id\''),
            'product',
            'uniq.product.product_number__version_id'
        );

        static::assertNotNull($violation);
        static::assertSame('SW1-abc', $violation->entry);
    }

    public function testPostgreSql(): void
    {
        $violation = DuplicateKeyViolation::matching(
            new \RuntimeException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"product.uniq.product.product_number__version_id\"\nDETAIL:  Key (product_number, version_id)=(SW1, 0fa91ce3-e96a-4bc2-be4b-d9ce752c3425) already exists."),
            'product',
            'uniq.product.product_number__version_id'
        );

        static::assertNotNull($violation);
        static::assertSame(['SW1', '0fa91ce3-e96a-4bc2-be4b-d9ce752c3425'], $violation->columnValues);
        static::assertSame('SW1-0fa91ce3-e96a-4bc2-be4b-d9ce752c3425', $violation->entry);
    }

    public function testPostgreSqlShortenedIndexName(): void
    {
        $qualified = 'a_very_long_table_name_for_testing.uniq.a_very_long_table_name_for_testing.technical_name';
        $shortened = substr($qualified, 0, 54) . '_' . substr(md5($qualified), 0, 8);

        $violation = DuplicateKeyViolation::fromThrowable(
            new \RuntimeException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"{$shortened}\"\nDETAIL:  Key (technical_name)=(foo) already exists.")
        );

        static::assertNotNull($violation);
        static::assertTrue($violation->isKey('a_very_long_table_name_for_testing', 'uniq.a_very_long_table_name_for_testing.technical_name'));
        static::assertSame('foo', $violation->entry);
    }

    public function testOtherKeyDoesNotMatch(): void
    {
        static::assertNull(DuplicateKeyViolation::matching(
            new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'EUR\' for key \'currency.uniq.currency.iso_code\''),
            'product',
            'uniq.product.product_number__version_id'
        ));
        static::assertNull(DuplicateKeyViolation::fromThrowable(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row')));
    }
}
