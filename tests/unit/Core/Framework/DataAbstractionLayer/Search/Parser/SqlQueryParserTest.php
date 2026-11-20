<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DataAbstractionLayer\Search\Parser;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\SuffixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Parser\ParseResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Parser\SqlQueryParser;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ListDefinition;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Unit\Aggregate\UnitTranslation\UnitTranslationDefinition;
use Shopware\Core\System\Unit\UnitDefinition;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(SqlQueryParser::class)]
class SqlQueryParserTest extends TestCase
{
    public function testParseUnsupportedQueryFilter(): void
    {
        $this->expectException(DataAbstractionLayerException::class);

        $parser = new SqlQueryParser(new EntityDefinitionQueryHelper(), static::createStub(Connection::class));

        $parser->parse(
            new ScoreQuery(new ContainsFilter('description', 'test'), 250),
            new UnitDefinition(),
            Context::createDefaultContext(),
        );
    }

    public function testParseNegatedEqualsAnyFilterKeepsNullableRows(): void
    {
        $parser = new SqlQueryParser(new EntityDefinitionQueryHelper(), static::createStub(Connection::class));

        $result = $parser->parse(
            new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsAnyFilter('shortCode', ['foo']),
            ]),
            $this->getRegistry()->getByEntityName(UnitDefinition::ENTITY_NAME),
            Context::createDefaultContext(),
        );

        static::assertCount(1, $result->getWheres());
        static::assertStringStartsWith('NOT ((', $result->getWheres()[0]);
        static::assertStringContainsString(' IN (:param_', $result->getWheres()[0]);
        static::assertStringContainsString('IS NOT NULL', $result->getWheres()[0]);

        $parameters = array_values($result->getParameters());
        static::assertCount(1, $parameters);
        static::assertSame(['foo'], $parameters[0]);
    }

    public function testParseEmptyEqualsAnyFilterOnListFieldMatchesNothing(): void
    {
        $parser = new SqlQueryParser(new EntityDefinitionQueryHelper(), static::createStub(Connection::class));

        $result = $parser->parse(
            new EqualsAnyFilter('data', []),
            $this->getRegistry([ListDefinition::class])->getByEntityName(ListDefinition::ENTITY_NAME),
            Context::createDefaultContext(),
        );

        static::assertSame(['1 = 0'], $result->getWheres());
        static::assertSame([], $result->getParameters());
    }

    /**
     * @return iterable<string, array{Filter, string, mixed}>
     */
    public static function postgreSqlStringFilters(): iterable
    {
        yield 'equals' => [new EqualsFilter('shortCode', 'Müller_X'), 'lower(COALESCE(ACCESSOR)) COLLATE ucs_basic = :param_', 'müller_x'];
        yield 'equalsAny' => [new EqualsAnyFilter('shortCode', ['Müller', 'FOO']), 'lower(COALESCE(ACCESSOR)) COLLATE ucs_basic IN (:param_', ['müller', 'foo']];
        yield 'contains' => [new ContainsFilter('shortCode', 'Mü_%'), 'lower(COALESCE(ACCESSOR)) COLLATE ucs_basic LIKE :param_', '%mü\\_\\%%'];
        yield 'prefix' => [new PrefixFilter('shortCode', 'Mü_'), 'lower(COALESCE(ACCESSOR)) COLLATE ucs_basic LIKE :param_', 'mü\\_%'];
        yield 'suffix' => [new SuffixFilter('shortCode', 'Mü_'), 'lower(COALESCE(ACCESSOR)) COLLATE ucs_basic LIKE :param_', '%mü\\_'];
    }

    #[DataProvider('postgreSqlStringFilters')]
    public function testStringFiltersAreCaseInsensitiveOnPostgreSql(Filter $filter, string $expectedWhere, mixed $expectedValue): void
    {
        $result = $this->parseOn(new PostgreSQLPlatform(), $filter);

        static::assertCount(1, $result->getWheres());
        static::assertMatchesRegularExpression($this->wherePattern($expectedWhere), $result->getWheres()[0]);
        static::assertSame([$expectedValue], array_values($result->getParameters()));
    }

    public function testStringFiltersAreUnchangedOnMariaDb(): void
    {
        $result = $this->parseOn(new MariaDBPlatform(), new ContainsFilter('shortCode', 'Mü_'));

        static::assertMatchesRegularExpression($this->wherePattern('COALESCE(ACCESSOR) LIKE :param_'), $result->getWheres()[0]);
        static::assertStringNotContainsString('lower(', $result->getWheres()[0]);
        static::assertSame(['%Mü\\_%'], array_values($result->getParameters()));
    }

    public function testNonStringColumnsAreNotLoweredOnPostgreSql(): void
    {
        $result = $this->parseOn(new PostgreSQLPlatform(), new EqualsFilter('id', Uuid::randomHex()));

        static::assertStringNotContainsString('lower(', $result->getWheres()[0]);
    }

    /**
     * The identifier quoting of the translated field accessor depends on the database of the test run (`DATABASE_URL`)
     */
    private function wherePattern(string $where): string
    {
        return '/' . str_replace('ACCESSOR', '[`"]unit\\.translation\\.shortCode[`"]', preg_quote($where, '/')) . '/';
    }

    private function parseOn(AbstractPlatform $platform, Filter $filter): ParseResult
    {
        $connection = static::createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return (new SqlQueryParser(new EntityDefinitionQueryHelper(), $connection))->parse(
            $filter,
            $this->getRegistry()->getByEntityName(UnitDefinition::ENTITY_NAME),
            Context::createDefaultContext(),
        );
    }

    /**
     * @param list<class-string<EntityDefinition>> $definitions
     */
    private function getRegistry(array $definitions = [UnitDefinition::class, UnitTranslationDefinition::class]): DefinitionInstanceRegistry
    {
        return new StaticDefinitionInstanceRegistry(
            $definitions,
            static::createStub(ValidatorInterface::class),
            static::createStub(EntityWriteGatewayInterface::class)
        );
    }
}
