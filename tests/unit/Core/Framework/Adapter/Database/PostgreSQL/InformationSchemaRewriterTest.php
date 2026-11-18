<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\InformationSchemaRewriter;

/**
 * @internal
 */
#[CoversClass(InformationSchemaRewriter::class)]
class InformationSchemaRewriterTest extends TestCase
{
    public function testMatchesInformationSchemaQueries(): void
    {
        static::assertTrue(InformationSchemaRewriter::matches('SELECT * FROM information_schema.tables WHERE table_name = 1'));
        static::assertFalse(InformationSchemaRewriter::matches('SELECT * FROM product'));
    }

    public function testDoctrineOwnQueryIsNotRewritten(): void
    {
        static::assertFalse(InformationSchemaRewriter::matches('SELECT 1 FROM information_schema.tables WHERE table_catalog = current_database()'));
    }
}
