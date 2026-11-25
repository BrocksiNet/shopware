<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Dbal;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;

/**
 * A unique key violation read from the driver message, the same for MySQL, MariaDB and PostgreSQL.
 * MySQL, MariaDB: `SQLSTATE[23000]: ... 1062 Duplicate entry 'SW1-x' for key 'product.uniq.product.product_number__version_id'`
 * (MySQL 8 prefixes the key with the table, MariaDB does not; the columns of the entry are joined with `-`).
 * PostgreSQL: `SQLSTATE[23505]: ... unique constraint "product.uniq.product.product_number__version_id" DETAIL: Key (product_number, version_id)=(SW1, x) already exists.`
 * (the index is named `<table>.<key>`, cut to 54 characters plus `_` and 8 hex digits of its md5 when it is longer than 63).
 *
 * @internal
 */
#[Package('framework')]
final readonly class DuplicateKeyViolation
{
    private const MAX_POSTGRESQL_IDENTIFIER_LENGTH = 63;

    /**
     * @param list<string>|null $columnValues one value per key column, null on MySQL and MariaDB, which join them with `-`
     */
    private function __construct(
        public string $key,
        public string $entry,
        public ?array $columnValues,
    ) {
    }

    public static function fromThrowable(\Throwable $e): ?self
    {
        $message = $e->getMessage();

        if (preg_match('/SQLSTATE\[23000]:.*1062 Duplicate entry \'(?<entry>.*)\' for key \'(?<key>[^\']*)\'/s', $message, $match)) {
            return new self($match['key'], $match['entry'], null);
        }

        if (preg_match('/SQLSTATE\[23505]:.*unique constraint "(?<key>[^"]+)".*Key \((?<columns>.*)\)=\((?<values>.*)\) already exists/s', $message, $match)) {
            // the values are listed with ", " between them; a value that contains ", " itself is split, too
            $values = explode(', ', $match['values']);

            return new self($match['key'], implode('-', $values), $values);
        }

        return null;
    }

    public static function matching(\Throwable $e, string $table, string $keyName): ?self
    {
        $violation = self::fromThrowable($e);

        return $violation !== null && $violation->isKey($table, $keyName) ? $violation : null;
    }

    public function isKey(string $table, string $keyName): bool
    {
        $qualified = $table . '.' . $keyName;
        if ($this->key === $keyName || $this->key === $qualified) {
            return true;
        }

        return \strlen($qualified) > self::MAX_POSTGRESQL_IDENTIFIER_LENGTH
            && $this->key === substr($qualified, 0, 54) . '_' . substr(Hasher::hash($qualified, 'md5'), 0, 8);
    }
}
