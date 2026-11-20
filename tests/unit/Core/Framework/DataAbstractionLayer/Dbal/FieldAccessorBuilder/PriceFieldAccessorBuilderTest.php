<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\MySqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\PostgreSqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder\PriceFieldAccessorBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Field\PriceField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(PriceFieldAccessorBuilder::class)]
class PriceFieldAccessorBuilderTest extends TestCase
{
    private PriceFieldAccessorBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PriceFieldAccessorBuilder(
            static::createStub(Connection::class)
        );
    }

    public function testReturnsNullForNonPriceField(): void
    {
        $field = new StringField('name', 'name');
        $context = Context::createDefaultContext();

        static::assertNull($this->builder->buildAccessor('product', $field, $context, 'name'));
    }

    public function testPriceAccessorDoesNotInvert(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price');

        static::assertNotNull($sql);
        static::assertStringNotContainsString('100 -', $sql);
        static::assertStringContainsString('gross', $sql);
    }

    public function testListPriceAccessorDoesNotInvert(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price.listPrice');

        static::assertNotNull($sql);
        static::assertStringNotContainsString('100 -', $sql);
        static::assertStringContainsString('listPrice.gross', $sql);
    }

    public function testPercentageAccessorInvertsWithGrossContext(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price.percentage');

        static::assertNotNull($sql);
        static::assertStringStartsWith('(100 - ', $sql);
        static::assertStringContainsString('percentage.gross', $sql);
    }

    public function testPercentageAccessorInvertsWithNetContext(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();
        $context->setTaxState(CartPrice::TAX_STATE_NET);

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price.percentage');

        static::assertNotNull($sql);
        static::assertStringStartsWith('(100 - ', $sql);
        static::assertStringContainsString('percentage.net', $sql);
        static::assertStringNotContainsString('percentage.gross', $sql);
    }

    public function testPercentageWithExplicitGrossAccessor(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();
        $context->setTaxState(CartPrice::TAX_STATE_NET);

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price.percentage.gross');

        static::assertNotNull($sql);
        static::assertStringStartsWith('(100 - ', $sql);
        static::assertStringContainsString('percentage.gross', $sql);
    }

    public function testPercentageWithExplicitNetAccessor(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price.percentage.net');

        static::assertNotNull($sql);
        static::assertStringStartsWith('(100 - ', $sql);
        static::assertStringContainsString('percentage.net', $sql);
        static::assertStringNotContainsString('percentage.gross', $sql);
    }

    public function testPercentageAccessorUsesDefaultCurrency(): void
    {
        $field = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $field, $context, 'price.percentage');

        static::assertNotNull($sql);
        static::assertStringContainsString('$.c' . Defaults::CURRENCY . '.percentage.gross', $sql);
    }

    #[DataProvider('dialects')]
    public function testCurrencyFactorMultipliesNumericExtract(SqlDialect $dialect, string $expectedPath, string $expectedTail): void
    {
        $builder = new PriceFieldAccessorBuilder(static::createStub(Connection::class), $dialect);
        $currencyId = Uuid::randomHex();

        $sql = $builder->buildAccessor('product', new PriceField('price', 'price'), Context::createDefaultContext(), 'price.' . $currencyId);

        static::assertNotNull($sql);
        static::assertStringContainsString(\sprintf($expectedPath, $currencyId) . $expectedTail . ' + 0.0)', $sql);
        static::assertStringContainsString(\sprintf($expectedPath, Defaults::CURRENCY) . $expectedTail . ' * (SELECT `factor` FROM `currency`', $sql);
    }

    /**
     * @return iterable<string, array{SqlDialect, string, string}>
     */
    public static function dialects(): iterable
    {
        yield 'mysql' => [new MySqlDialect(), '\'$.c%s.gross\'', '))'];
        yield 'postgresql' => [new PostgreSqlDialect(), '\'$.c%s.gross\'', '::jsonpath)) ->> 0)::numeric) AS double precision)'];
    }
}
