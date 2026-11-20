<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Database\PostgreSQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\DdlRewriter;

/**
 * @internal
 */
#[CoversClass(DdlRewriter::class)]
class DdlRewriterTest extends TestCase
{
    /**
     * @param list<string> $fragments
     */
    #[DataProvider('fragmentProvider')]
    public function testRewriteContains(string $sql, array $fragments): void
    {
        $rewritten = (new DdlRewriter())->rewrite(self::translated($sql));

        foreach ($fragments as $fragment) {
            static::assertStringContainsString($fragment, $rewritten);
        }
    }

    /**
     * @param list<string> $fragments
     */
    #[DataProvider('absentProvider')]
    public function testRewriteDoesNotContain(string $sql, array $fragments): void
    {
        $rewritten = (new DdlRewriter())->rewrite(self::translated($sql));

        foreach ($fragments as $fragment) {
            static::assertStringNotContainsString($fragment, $rewritten);
        }
    }

    public function testIsDdl(): void
    {
        static::assertTrue(DdlRewriter::isDdl('CREATE TABLE `a` (`id` INT)'));
        static::assertTrue(DdlRewriter::isDdl('  ALTER TABLE `a` ADD `b` INT'));
        static::assertTrue(DdlRewriter::isDdl('RENAME TABLE a TO b'));
        static::assertFalse(DdlRewriter::isDdl('SELECT 1'));
        static::assertFalse(DdlRewriter::isDdl('UPDATE a SET b = 1'));
    }

    public function testIndexNameIsPrefixedWithTable(): void
    {
        static::assertSame('product.idx.name', DdlRewriter::indexName('product', 'idx.name'));
    }

    public function testLongIndexNameIsShortenedStable(): void
    {
        $name = DdlRewriter::indexName('product_cross_selling_assigned_products', 'uniq.cross_selling_id__product_id__product_version_id');

        static::assertSame(63, \strlen($name));
        static::assertSame($name, DdlRewriter::indexName('product_cross_selling_assigned_products', 'uniq.cross_selling_id__product_id__product_version_id'));
    }

    public function testSplitTopLevelIgnoresNestedSeparators(): void
    {
        static::assertSame(['a', ' b(1, 2)', ' \'x,y\''], DdlRewriter::splitTopLevel('a, b(1, 2), \'x,y\''));
    }

    public function testSplitTopLevelIgnoresBlockComments(): void
    {
        static::assertSame(['a', ' /* x, (y */ b', ' c'], DdlRewriter::splitTopLevel('a, /* x, (y */ b, c'));
    }

    public function testSplitTopLevelIgnoresLineComments(): void
    {
        static::assertSame(["a -- x, (y\n", ' b'], DdlRewriter::splitTopLevel("a -- x, (y\n, b"));
    }

    public function testSplitTopLevelIgnoresBacktickIdentifiers(): void
    {
        static::assertSame(['a', ' `b,c`', ' d(1, 2)'], DdlRewriter::splitTopLevel('a, `b,c`, d(1, 2)'));
        static::assertSame(['`a(` ', ' b'], DdlRewriter::splitTopLevel('`a(` , b'));
        static::assertSame(['`a``,b`', ' c'], DdlRewriter::splitTopLevel('`a``,b`, c'));
    }

    public function testSplitTopLevelHonoursBackslashEscapesInStrings(): void
    {
        static::assertSame(['\'a\\\'b, c\'', ' d'], DdlRewriter::splitTopLevel('\'a\\\'b, c\', d'));
    }

    public function testRewriteIgnoresParenthesesAndCommasInComments(): void
    {
        $sql = 'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `a` INT NULL /* a ), b */, PRIMARY KEY (`id`))';

        static::assertSame(
            "CREATE TABLE \"t\" (\n    \"id\" uuid NOT NULL,\n    \"a\" integer /* a ), b */,\n    PRIMARY KEY (\"id\")\n)",
            trim((new DdlRewriter())->rewrite(self::translated($sql)), ";\n"),
        );
    }

    public function testModifyUsesColumnTypeLookupForJson(): void
    {
        $rewriter = new DdlRewriter(static fn (string $table, string $column): string => 'jsonb');

        $rewritten = $rewriter->rewrite(self::translated('ALTER TABLE `t` MODIFY `c` LONGTEXT NULL'));

        static::assertStringContainsString('TYPE jsonb', $rewritten);
    }

    public function testSetDefaultOnBooleanColumnUsesBooleanLiteral(): void
    {
        $rewriter = new DdlRewriter(static fn (string $table, string $column): string => 'boolean');

        static::assertStringContainsString('SET DEFAULT false', $rewriter->rewrite(self::translated('ALTER TABLE `shipping_method` ALTER `active` SET DEFAULT 0')));
        static::assertStringContainsString('SET DEFAULT true', $rewriter->rewrite(self::translated('ALTER TABLE `shipping_method` ALTER `active` SET DEFAULT 1')));
    }

    #[DataProvider('exactProvider')]
    public function testRewriteExact(string $sql, string $expected): void
    {
        static::assertSame($expected, (new DdlRewriter())->rewrite(self::translated($sql)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exactProvider(): iterable
    {
        yield 'create table with keys and foreign key' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `a` VARCHAR(10) NULL, `n` INT(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`), KEY `idx.a` (`a`), UNIQUE KEY `uniq.a` (`a`), CONSTRAINT `fk.t.a` FOREIGN KEY (`a`) REFERENCES `parent` (`id`) ON DELETE SET NULL)',
            <<<'EOSQL'
CREATE TABLE "t" (
    "id" uuid NOT NULL,
    "a" varchar(10) COLLATE "ci",
    "n" integer GENERATED BY DEFAULT AS IDENTITY NOT NULL,
    PRIMARY KEY ("id"),
    CONSTRAINT "fk.t.a" FOREIGN KEY ("a") REFERENCES "parent" ("id") ON DELETE SET NULL ON UPDATE RESTRICT
);
DO $$ DECLARE r record; BEGIN FOR r IN SELECT a.attname::text AS name, format_type(a.atttypid, a.atttypmod) AS type, pg_get_expr(d.adbin, d.adrelid) AS expr FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = to_regclass('"t"') AND a.attgenerated = 'v' AND a.attname::text = ANY(ARRAY['a']) LOOP EXECUTE format('ALTER TABLE %s DROP COLUMN %I', '"t"', r.name); EXECUTE format('ALTER TABLE %s ADD COLUMN %I %s GENERATED ALWAYS AS (%s) STORED', '"t"', r.name, r.type, r.expr); END LOOP; CREATE INDEX IF NOT EXISTS "t.idx.a" ON "t" ("a"); FOR r IN SELECT ic.relname::text AS name FROM pg_constraint c JOIN pg_class ic ON ic.relname = (CASE WHEN length('t.' || c.conname) <= 63 THEN 't.' || c.conname ELSE substr('t.' || c.conname, 1, 54) || '_' || substr(md5('t.' || c.conname), 1, 8) END) JOIN pg_index i ON i.indexrelid = ic.oid WHERE c.contype = 'f' AND c.conrelid = to_regclass('"t"') AND i.indrelid = c.conrelid AND NOT i.indisunique AND NOT i.indisprimary AND obj_description(ic.oid, 'pg_class') = 'implicit foreign key index' AND ic.relname::text <> 't.idx.a' AND (SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1)) <= 1 AND ARRAY(SELECT unnest(i.indkey::int2[])) = (ARRAY(SELECT a.attnum FROM unnest(ARRAY['a']) WITH ORDINALITY u(n, o) JOIN pg_attribute a ON a.attrelid = to_regclass('"t"') AND a.attname = u.n ORDER BY u.o))[1:(SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1))] LOOP EXECUTE format('DROP INDEX %I', r.name); END LOOP; END $$;
DO $$ DECLARE r record; BEGIN FOR r IN SELECT a.attname::text AS name, format_type(a.atttypid, a.atttypmod) AS type, pg_get_expr(d.adbin, d.adrelid) AS expr FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = to_regclass('"t"') AND a.attgenerated = 'v' AND a.attname::text = ANY(ARRAY['a']) LOOP EXECUTE format('ALTER TABLE %s DROP COLUMN %I', '"t"', r.name); EXECUTE format('ALTER TABLE %s ADD COLUMN %I %s GENERATED ALWAYS AS (%s) STORED', '"t"', r.name, r.type, r.expr); END LOOP; CREATE UNIQUE INDEX IF NOT EXISTS "t.uniq.a" ON "t" ("a"); FOR r IN SELECT ic.relname::text AS name FROM pg_constraint c JOIN pg_class ic ON ic.relname = (CASE WHEN length('t.' || c.conname) <= 63 THEN 't.' || c.conname ELSE substr('t.' || c.conname, 1, 54) || '_' || substr(md5('t.' || c.conname), 1, 8) END) JOIN pg_index i ON i.indexrelid = ic.oid WHERE c.contype = 'f' AND c.conrelid = to_regclass('"t"') AND i.indrelid = c.conrelid AND NOT i.indisunique AND NOT i.indisprimary AND obj_description(ic.oid, 'pg_class') = 'implicit foreign key index' AND ic.relname::text <> 't.uniq.a' AND (SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1)) <= 1 AND ARRAY(SELECT unnest(i.indkey::int2[])) = (ARRAY(SELECT a.attnum FROM unnest(ARRAY['a']) WITH ORDINALITY u(n, o) JOIN pg_attribute a ON a.attrelid = to_regclass('"t"') AND a.attname = u.n ORDER BY u.o))[1:(SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1))] LOOP EXECUTE format('DROP INDEX %I', r.name); END LOOP; END $$
EOSQL,
        ];

        yield 'alter add column' => [
            'ALTER TABLE `t` ADD COLUMN `b` INT NULL',
            <<<'EOSQL'
ALTER TABLE "t" ADD COLUMN "b" integer
EOSQL,
        ];

        yield 'alter drop column' => [
            'ALTER TABLE `t` DROP COLUMN `b`',
            <<<'EOSQL'
ALTER TABLE "t" DROP COLUMN "b"
EOSQL,
        ];

        yield 'alter modify column' => [
            'ALTER TABLE `t` MODIFY `a` VARCHAR(50) NOT NULL',
            <<<'EOSQL'
DO $$ DECLARE g "char"; BEGIN SELECT a.attgenerated FROM pg_attribute a WHERE a.attrelid = to_regclass('"t"') AND a.attname = 'a' AND NOT a.attisdropped INTO g; IF g = 'v' THEN ALTER TABLE "t" DROP COLUMN "a"; ALTER TABLE "t" ADD COLUMN "a" varchar(50) COLLATE "ci"; ELSIF g = 's' THEN ALTER TABLE "t" ALTER COLUMN "a" DROP EXPRESSION; END IF; END $$;
ALTER TABLE "t" ALTER COLUMN "a" DROP DEFAULT;
ALTER TABLE "t" ALTER COLUMN "a" TYPE varchar(50) COLLATE "ci" USING "a"::text;
ALTER TABLE "t" ALTER COLUMN "a" SET NOT NULL;
ALTER TABLE "t" ALTER COLUMN "a" DROP DEFAULT
EOSQL,
        ];

        yield 'alter add index' => [
            'ALTER TABLE `t` ADD INDEX `idx.b` (`b`)',
            <<<'EOSQL'
DO $$ DECLARE r record; BEGIN FOR r IN SELECT a.attname::text AS name, format_type(a.atttypid, a.atttypmod) AS type, pg_get_expr(d.adbin, d.adrelid) AS expr FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = to_regclass('"t"') AND a.attgenerated = 'v' AND a.attname::text = ANY(ARRAY['b']) LOOP EXECUTE format('ALTER TABLE %s DROP COLUMN %I', '"t"', r.name); EXECUTE format('ALTER TABLE %s ADD COLUMN %I %s GENERATED ALWAYS AS (%s) STORED', '"t"', r.name, r.type, r.expr); END LOOP; CREATE INDEX IF NOT EXISTS "t.idx.b" ON "t" ("b"); FOR r IN SELECT ic.relname::text AS name FROM pg_constraint c JOIN pg_class ic ON ic.relname = (CASE WHEN length('t.' || c.conname) <= 63 THEN 't.' || c.conname ELSE substr('t.' || c.conname, 1, 54) || '_' || substr(md5('t.' || c.conname), 1, 8) END) JOIN pg_index i ON i.indexrelid = ic.oid WHERE c.contype = 'f' AND c.conrelid = to_regclass('"t"') AND i.indrelid = c.conrelid AND NOT i.indisunique AND NOT i.indisprimary AND obj_description(ic.oid, 'pg_class') = 'implicit foreign key index' AND ic.relname::text <> 't.idx.b' AND (SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1)) <= 1 AND ARRAY(SELECT unnest(i.indkey::int2[])) = (ARRAY(SELECT a.attnum FROM unnest(ARRAY['b']) WITH ORDINALITY u(n, o) JOIN pg_attribute a ON a.attrelid = to_regclass('"t"') AND a.attname = u.n ORDER BY u.o))[1:(SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1))] LOOP EXECUTE format('DROP INDEX %I', r.name); END LOOP; END $$
EOSQL,
        ];

        yield 'alter drop index' => [
            'ALTER TABLE `t` DROP INDEX `idx.a`',
            <<<'EOSQL'
DROP INDEX IF EXISTS "t.idx.a"
EOSQL,
        ];

        yield 'alter drop foreign key' => [
            'ALTER TABLE `t` DROP FOREIGN KEY `fk.t.p`',
            <<<'EOSQL'
ALTER TABLE "t" DROP CONSTRAINT "fk.t.p"
EOSQL,
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function fragmentProvider(): iterable
    {
        yield 'unnamed foreign keys are numbered like MySQL' => [
            'CREATE TABLE `t` (`a` BINARY(16) NOT NULL, `b` BINARY(16) NOT NULL, FOREIGN KEY (`a`) REFERENCES `x` (`id`), FOREIGN KEY (`b`) REFERENCES `y` (`id`))',
            ['CONSTRAINT "t_ibfk_1" FOREIGN KEY', 'CONSTRAINT "t_ibfk_2" FOREIGN KEY'],
        ];
        yield 'binary 16 is uuid' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, PRIMARY KEY (`id`))',
            ['CREATE TABLE "t"', '"id" uuid NOT NULL', 'PRIMARY KEY ("id")'],
        ];

        yield 'types' => [
            'CREATE TABLE `t` (`a` VARCHAR(255) NOT NULL, `b` TINYINT(1) NOT NULL DEFAULT 1, `c` JSON NULL, `d` DATETIME(3) NULL, `e` INT(11) UNSIGNED NULL, `f` LONGTEXT NULL, `g` LONGBLOB NULL)',
            ['"a" varchar(255) COLLATE "ci" NOT NULL', '"b" boolean NOT NULL DEFAULT true', '"c" jsonb', '"d" timestamp(3)', '"e" bigint', '"f" text', '"g" bytea'],
        ];

        yield 'auto increment is identity' => [
            'CREATE TABLE `t` (`n` INT(11) NOT NULL AUTO_INCREMENT, `id` BINARY(16) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `n` (`n`))',
            ['"n" integer GENERATED BY DEFAULT AS IDENTITY NOT NULL', 'CREATE UNIQUE INDEX IF NOT EXISTS "t.n" ON "t" ("n")'],
        ];

        yield 'key and unique key become indexes with table prefix' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `a` VARCHAR(10) NULL, PRIMARY KEY (`id`), KEY `idx.a` (`a`), UNIQUE KEY `uniq.a` (`a`))',
            ['CREATE INDEX IF NOT EXISTS "t.idx.a" ON "t" ("a")', 'CREATE UNIQUE INDEX IF NOT EXISTS "t.uniq.a" ON "t" ("a")'],
        ];

        yield 'column level unique creates an index named after the column' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `name` VARCHAR(255) NOT NULL UNIQUE, PRIMARY KEY (`id`))',
            ['CREATE UNIQUE INDEX IF NOT EXISTS "t.name" ON "t" ("name")'],
        ];

        yield 'foreign key without actions restricts like MySQL' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `p` BINARY(16) NULL, PRIMARY KEY (`id`), CONSTRAINT `fk.t.p` FOREIGN KEY (`p`) REFERENCES `parent` (`id`))',
            ['CONSTRAINT "fk.t.p" FOREIGN KEY ("p") REFERENCES "parent" ("id") ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ];

        yield 'foreign key keeps its actions' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `p` BINARY(16) NULL, PRIMARY KEY (`id`), CONSTRAINT `fk.t.p` FOREIGN KEY (`p`) REFERENCES `parent` (`id`) ON DELETE SET NULL ON UPDATE CASCADE)',
            ['ON DELETE SET NULL ON UPDATE CASCADE'],
        ];

        yield 'foreign key gets the implicit index MySQL creates' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `p` BINARY(16) NULL, PRIMARY KEY (`id`), CONSTRAINT `fk.t.p` FOREIGN KEY (`p`) REFERENCES `parent` (`id`))',
            ['CREATE INDEX "t.fk.t.p" ON "t" ("p")', 'COMMENT ON INDEX "t.fk.t.p" IS \'implicit foreign key index\''],
        ];

        yield 'table and column comments are kept' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `n` VARCHAR(255) NULL COMMENT \'marker\', PRIMARY KEY (`id`)) ENGINE=InnoDB COMMENT=\'custom-entity-element\'',
            ['COMMENT ON TABLE "t" IS \'custom-entity-element\'', 'COMMENT ON COLUMN "t"."n" IS \'marker\''],
        ];

        yield 'json check constraint makes the column jsonb' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `j` LONGTEXT NULL, PRIMARY KEY (`id`), CONSTRAINT `json.t.j` CHECK (JSON_VALID(`j`)))',
            ['"j" jsonb'],
        ];

        yield 'json object default' => [
            'ALTER TABLE `app` ADD COLUMN `source_config` JSON NULL DEFAULT (JSON_OBJECT())',
            ['DEFAULT \'{}\''],
        ];

        yield 'translated json default' => [
            'ALTER TABLE `app` ADD COLUMN `requested` JSON NULL DEFAULT (jsonb_build_array())',
            ['DEFAULT \'[]\''],
        ];

        yield 'drop index' => [
            'ALTER TABLE `t` DROP INDEX `idx.a`',
            ['"t.idx.a"'],
        ];

        yield 'drop foreign key' => [
            'ALTER TABLE `t` DROP FOREIGN KEY `fk.t.p`',
            ['DROP CONSTRAINT', '"fk.t.p"'],
        ];

        yield 'add foreign key only creates the index when no index covers the columns' => [
            'ALTER TABLE `t` ADD CONSTRAINT `fk.t.p` FOREIGN KEY (`p`) REFERENCES `parent` (`id`) ON DELETE CASCADE',
            ['IF NOT EXISTS (SELECT 1 FROM pg_index i', 'CREATE INDEX IF NOT EXISTS "t.fk.t.p" ON "t" ("p")', 'ON DELETE CASCADE ON UPDATE RESTRICT'],
        ];

        yield 'add index drops the implicit foreign key indexes it covers' => [
            'ALTER TABLE `t` ADD INDEX `idx.pv` (`p`, `pv`)',
            ['CREATE INDEX IF NOT EXISTS "t.idx.pv" ON "t" ("p", "pv")', 'obj_description(ic.oid, \'pg_class\') = \'implicit foreign key index\'', 'DROP INDEX %I'],
        ];

        yield 'add index on a virtual generated column rebuilds it as stored' => [
            'ALTER TABLE `t` ADD INDEX `idx.g` (`g`)',
            ['GENERATED ALWAYS AS (%s) STORED'],
        ];

        yield 'modify keeps primary key columns not null' => [
            'ALTER TABLE `t` MODIFY `id` BINARY(16) NULL',
            ['i.indisprimary', 'DROP NOT NULL'],
        ];

        yield 'modify turns a generated column into a plain one' => [
            'ALTER TABLE `t` MODIFY `c` DOUBLE NULL',
            ['IF g = \'v\' THEN', 'ELSIF g = \'s\' THEN', 'DROP EXPRESSION'],
        ];

        yield 'modify to a generated column adds it again' => [
            'ALTER TABLE `order_delivery_position` MODIFY `total_price` DOUBLE GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`price`, \'$.totalPrice\'))) VIRTUAL',
            ['DROP COLUMN "total_price"', 'ADD COLUMN "total_price" double precision GENERATED ALWAYS AS (("price"->>\'totalPrice\')::double precision) VIRTUAL'],
        ];

        yield 'modify tinyint of a column drops the old default first' => [
            'ALTER TABLE `product` CHANGE `active` `active` TINYINT UNSIGNED NULL',
            ['DROP DEFAULT', 'TYPE smallint', 'USING "active"::integer::smallint'],
        ];

        yield 'rename table' => [
            'RENAME TABLE `a` TO `b`',
            ['RENAME TO'],
        ];

        yield 'temporary table' => [
            'CREATE TEMPORARY TABLE `t` (`a` INT NULL)',
            ['CREATE TEMPORARY TABLE "t"'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function absentProvider(): iterable
    {
        yield 'explicit default null is dropped' => [
            'CREATE TABLE `t` (`a` VARCHAR(255) NULL DEFAULT NULL, `b` DATETIME(3) NULL DEFAULT NULL)',
            ['DEFAULT NULL'],
        ];

        yield 'fulltext index is skipped' => [
            'CREATE TABLE `t` (`a` TEXT NULL, FULLTEXT KEY `ft` (`a`))',
            ['FULLTEXT'],
        ];

        yield 'foreign key index is not created when the primary key covers it' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, PRIMARY KEY (`id`), CONSTRAINT `fk.t.id` FOREIGN KEY (`id`) REFERENCES `parent` (`id`))',
            ['CREATE INDEX'],
        ];

        yield 'foreign key index is not created when an explicit key covers it' => [
            'CREATE TABLE `t` (`id` BINARY(16) NOT NULL, `p` BINARY(16) NULL, PRIMARY KEY (`id`), KEY `fk.t.p` (`p`), CONSTRAINT `fk.t.p` FOREIGN KEY (`p`) REFERENCES `parent` (`id`))',
            ['IS \'implicit foreign key index\''],
        ];
    }

    /**
     * The rewriter expects statements the translator already quoted with double quotes
     */
    private static function translated(string $sql): string
    {
        return str_replace('`', '"', $sql);
    }
}
