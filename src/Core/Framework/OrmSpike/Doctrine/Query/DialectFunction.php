<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Query;

use Doctrine\ORM\Query\AST\ASTException;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Log\Package;

/**
 * DQL has no JSON functions and no portable case-insensitive compare, so the dialect of the project is exposed as DQL functions:
 * `SW_CI(expr)`, `SW_PRICE(own, parent, 'cCURRENCY')` (gross price of a currency, own or inherited json column) and `SW_TOTAL()`
 * (`COUNT(*) OVER()`, the total of a page in the same statement).
 *
 * @internal
 */
#[Package('framework')]
final class DialectFunction extends FunctionNode
{
    /**
     * @var list<Node|string>
     */
    private array $args = [];

    public function __construct(string $name, private readonly string $kind)
    {
        parent::__construct($name);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        if ($this->kind !== 'total') {
            $this->args[] = $parser->StringPrimary();
        }
        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->args[] = $parser->StringPrimary();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    /**
     * @throws ASTException
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        if ($this->kind === 'total') {
            return 'COUNT(*) OVER()';
        }

        $dialect = SqlDialectFactory::getDefault();
        $args = array_map(static fn (Node|string $arg): string => $arg instanceof Node ? $sqlWalker->walkStringPrimary($arg) : $arg, $this->args);

        if ($this->kind === 'ci') {
            return $dialect->caseInsensitive($args[0]);
        }

        // SW_PRICE(own, parent, 'cCurrency'): the literal arrives as a quoted SQL string
        return 'CAST(' . $dialect->jsonExtractNumber('COALESCE(' . $args[0] . ', ' . $args[1] . ')', '$.' . trim($args[2], '\'') . '.gross') . ' AS DECIMAL(20,4))';
    }
}
