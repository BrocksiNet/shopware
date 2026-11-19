<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\Dialect\MySqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\PostgreSqlDialect;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder\JsonFieldAccessorBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

/**
 * @internal
 */
#[CoversClass(JsonFieldAccessorBuilder::class)]
class JsonFieldAccessorBuilderTest extends TestCase
{
    public function testWholeColumn(): void
    {
        $builder = new JsonFieldAccessorBuilder($this->createStub(Connection::class), new MySqlDialect());

        static::assertSame('`product`.`data`', $builder->buildAccessor('product', $this->field(), Context::createDefaultContext(), 'data'));
    }

    public function testMySqlKeepsTheExistingSql(): void
    {
        $builder = new JsonFieldAccessorBuilder($this->createStub(Connection::class), new MySqlDialect());
        $context = Context::createDefaultContext();

        static::assertSame(
            'IF(JSON_TYPE(JSON_EXTRACT(`product`.`data`, \'$.name\')) != "NULL", CONVERT(JSON_UNQUOTE(JSON_EXTRACT(`product`.`data`, \'$.name\')) USING "utf8mb4") COLLATE utf8mb4_unicode_ci, NULL)',
            $builder->buildAccessor('product', $this->field(), $context, 'data.name')
        );
        static::assertSame(
            'IF(JSON_TYPE(JSON_EXTRACT(`product`.`data`, \'$.count\')) != "NULL", JSON_UNQUOTE(JSON_EXTRACT(`product`.`data`, \'$.count\')) + 0.0, NULL)',
            $builder->buildAccessor('product', $this->field(), $context, 'data.count')
        );
        static::assertSame(
            'IF(JSON_TYPE(JSON_EXTRACT(`product`.`data`, \'$.a."b-c"\')) != "NULL", CONVERT(JSON_UNQUOTE(JSON_EXTRACT(`product`.`data`, \'$.a."b-c"\')) USING "utf8mb4") COLLATE utf8mb4_unicode_ci, NULL)',
            $builder->buildAccessor('product', $this->field(), $context, 'data.a.b-c')
        );
    }

    public function testPostgreSqlUsesJsonPath(): void
    {
        $builder = new JsonFieldAccessorBuilder($this->createStub(Connection::class), new PostgreSqlDialect(null, true));
        $context = Context::createDefaultContext();

        static::assertSame(
            '(jsonb_path_query_first("product"."data", \'$.name\'::jsonpath) #>> \'{}\')',
            $builder->buildAccessor('product', $this->field(), $context, 'data.name')
        );
        static::assertSame(
            '(jsonb_path_query_first("product"."data", \'$.count\'::jsonpath) #>> \'{}\')::numeric',
            $builder->buildAccessor('product', $this->field(), $context, 'data.count')
        );
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        $builder = new JsonFieldAccessorBuilder($this->createStub(Connection::class), new MySqlDialect());

        $this->expectException(\Throwable::class);
        $builder->buildAccessor('pro`duct', $this->field(), Context::createDefaultContext(), 'data.name');
    }

    private function field(): JsonField
    {
        $field = new JsonField('data', 'data', [new StringField('name', 'name'), new IntField('count', 'count')]);

        return $field;
    }
}
