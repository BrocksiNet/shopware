<?php declare(strict_types=1);

namespace Shopware\Core\DevOps\StaticAnalyze\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Rector\Rector\AbstractRector;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites the binary uuid helpers in SQL code to `IdBinding`, which binds ids per database (docs/15-id-spike.md).
 *
 * - `Uuid::fromHexToBytes($x)`      -> `IdBinding::value($x)`
 * - `Uuid::fromHexToBytesList($x)`  -> `IdBinding::values($x)`
 * - `Uuid::fromBytesToHex($x)`      -> `IdBinding::toHex($x)`
 * - `Uuid::fromBytesToHexList($x)`  -> `IdBinding::toHexList($x)`
 * - `ArrayParameterType::BINARY` / `ParameterType::BINARY` -> `IdBinding::listType()` / `IdBinding::type()`, only inside a method that also binds ids through one of the helpers above.
 *
 * - `'... LOWER(HEX(col)) ...'` (plain quoted strings) -> `'...' . IdBinding::text('col') . '...'`
 *
 * - the same inside heredoc, nowdoc and interpolated strings (only the literal parts are split)
 *
 * Not covered: `UNHEX()`, expressions other than a plain column reference.
 *
 * @internal
 */
#[Package('framework')]
final class BinaryUuidToIdBindingRector extends AbstractRector
{
    private const LOWER_HEX = '/LOWER\(\s*HEX\(\s*([\w.`]+|\w+\([^()]*\))\s*\)\s*\)/i';

    private const BINDING_CALLS = ['fromHexToBytes', 'fromHexToBytesList'];

    private const RENAME = [
        'fromhextobytes' => 'value',
        'fromhextobyteslist' => 'values',
        'frombytestohex' => 'toHex',
        'frombytestohexlist' => 'toHexList',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Bind ids through IdBinding instead of the binary uuid helpers', []);
    }

    public function getNodeTypes(): array
    {
        return [StaticCall::class, ClassMethod::class, String_::class, InterpolatedString::class, ClassConst::class, Const_::class, Property::class, Param::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof ClassConst || $node instanceof Const_ || $node instanceof Property || $node instanceof Param) {
            // a constant expression cannot call IdBinding::text(), mark the strings below as untouchable
            (new NodeTraverser(new class extends NodeVisitorAbstract {
                public function enterNode(Node $n): ?Node
                {
                    if ($n instanceof String_ || $n instanceof InterpolatedString) {
                        $n->setAttribute('idbinding_skip', true);
                    }

                    return null;
                }
            }))->traverse([$node]);

            return null;
        }

        if (($node instanceof String_ || $node instanceof InterpolatedString) && $node->getAttribute('idbinding_skip') === true) {
            return null;
        }

        if ($node instanceof StaticCall) {
            if (!$this->isName($node->class, Uuid::class) || !$node->name instanceof Identifier) {
                return null;
            }

            $new = self::RENAME[strtolower($node->name->name)] ?? null;
            if ($new === null) {
                return null;
            }

            $node->class = new FullyQualified(IdBinding::class);
            $node->name = new Identifier($new);

            return $node;
        }

        if ($node instanceof String_) {
            return $this->rewriteSqlText($node);
        }

        if ($node instanceof InterpolatedString) {
            return $this->rewriteInterpolatedSqlText($node);
        }

        if (!$node instanceof ClassMethod || !$this->bindsIds($node)) {
            return null;
        }

        $changed = false;
        $traverser = new NodeTraverser(new class(static function () use (&$changed): void {
            $changed = true;
        }) extends NodeVisitorAbstract {
            public function __construct(private readonly \Closure $onChange)
            {
            }

            public function leaveNode(Node $n): ?Node
            {
                if (!$n instanceof ClassConstFetch || !$n->name instanceof Identifier || $n->name->name !== 'BINARY' || !$n->class instanceof Name) {
                    return null;
                }

                $class = $n->class->toString();
                $isList = $class === 'Doctrine\DBAL\ArrayParameterType' || $class === 'ArrayParameterType';
                if (!$isList && $class !== 'Doctrine\DBAL\ParameterType' && $class !== 'ParameterType') {
                    return null;
                }

                ($this->onChange)();

                return new StaticCall(new FullyQualified(IdBinding::class), $isList ? 'listType' : 'type');
            }
        });
        $node->stmts = $traverser->traverse((array) $node->stmts);

        return $changed ? $node : null;
    }

    /**
     * `"... LOWER(HEX(col)) {$x} ..."` and heredocs with interpolation: only the literal parts are split, the interpolated expressions stay where they are.
     */
    private function rewriteInterpolatedSqlText(InterpolatedString $node): ?Node
    {
        $result = null;
        $pending = [];
        $changed = false;
        $flush = static function () use (&$pending, &$result): void {
            if ($pending === []) {
                return;
            }

            $expr = new InterpolatedString($pending);
            $result = $result === null ? $expr : new Concat($result, $expr);
            $pending = [];
        };

        foreach ($node->parts as $part) {
            $split = $part instanceof InterpolatedStringPart ? preg_split(self::LOWER_HEX, $part->value, -1, \PREG_SPLIT_DELIM_CAPTURE) : false;
            if ($split === false || \count($split) < 3) {
                $pending[] = $part;

                continue;
            }

            $changed = true;
            foreach ($split as $i => $chunk) {
                if ($i % 2 === 0) {
                    if ($chunk !== '') {
                        $pending[] = new InterpolatedStringPart($chunk);
                    }

                    continue;
                }

                $flush();
                $text = new StaticCall(new FullyQualified(IdBinding::class), 'text', [new Arg(new String_($chunk))]);
                $result = $result === null ? $text : new Concat($result, $text);
            }
        }

        if (!$changed) {
            return null;
        }

        $flush();

        return $result;
    }

    private function rewriteSqlText(String_ $node): ?Node
    {
        $parts = preg_split(self::LOWER_HEX, $node->value, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || \count($parts) < 3) {
            return null;
        }

        $result = null;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0 && $part === '') {
                continue;
            }

            $expr = $i % 2 === 0
                ? new String_($part)
                : new StaticCall(new FullyQualified(IdBinding::class), 'text', [new Arg(new String_($part))]);
            $result = $result === null ? $expr : new Concat($result, $expr);
        }

        return $result;
    }

    private function bindsIds(ClassMethod $method): bool
    {
        return (new NodeFinder())->findFirst((array) $method->stmts, function (Node $n): bool {
            if (!$n instanceof StaticCall || !$n->name instanceof Identifier) {
                return false;
            }
            if (!\in_array($n->name->name, self::BINDING_CALLS, true) && $n->name->name !== 'value' && $n->name->name !== 'values') {
                return false;
            }

            return $this->isName($n->class, Uuid::class) || $this->isName($n->class, IdBinding::class);
        }) !== null;
    }
}
