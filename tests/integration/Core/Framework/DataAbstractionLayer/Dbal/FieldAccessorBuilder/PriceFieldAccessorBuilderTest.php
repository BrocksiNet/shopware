<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder\PriceFieldAccessorBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Field\PriceField;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * @internal
 */
#[Package('framework')]
class PriceFieldAccessorBuilderTest extends TestCase
{
    use KernelTestBehaviour;

    protected PriceFieldAccessorBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = static::getContainer()->get(PriceFieldAccessorBuilder::class);
    }

    public function testWithPriceAccessor(): void
    {
        $priceField = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $priceField, $context, 'price');

        static::assertSame(self::quoted('(ROUND((ROUND(CAST((COALESCE((JSON_UNQUOTE(JSON_EXTRACT(`product`.`price`, "$.cb7d2554b0ce847cd82f3ac9bd1c0dfca.gross")) + 0.0))) as DECIMAL(30, 20)), 2)) * 100, 0) / 100)'), $sql);
    }

    public function testWithListPriceAccessor(): void
    {
        $priceField = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $priceField, $context, 'price.listPrice');

        static::assertSame(self::quoted('(ROUND((ROUND(CAST((COALESCE((JSON_UNQUOTE(JSON_EXTRACT(`product`.`price`, "$.cb7d2554b0ce847cd82f3ac9bd1c0dfca.listPrice.gross")) + 0.0))) as DECIMAL(30, 20)), 2)) * 100, 0) / 100)'), $sql);
    }

    public function testWithPercentageAccessor(): void
    {
        $priceField = new PriceField('price', 'price');
        $context = Context::createDefaultContext();

        $sql = $this->builder->buildAccessor('product', $priceField, $context, 'price.percentage');

        static::assertSame(self::quoted('(100 - (ROUND((ROUND(CAST((COALESCE((JSON_UNQUOTE(JSON_EXTRACT(`product`.`price`, "$.cb7d2554b0ce847cd82f3ac9bd1c0dfca.percentage.gross")) + 0.0))) as DECIMAL(30, 20)), 2)) * 100, 0) / 100))'), $sql);
    }

    private static function quoted(string $sql): string
    {
        // the expected strings are written with backticks, the identifier quote depends on the dialect
        return str_replace('`', EntityDefinitionQueryHelper::escape('x')[0], $sql);
    }
}
