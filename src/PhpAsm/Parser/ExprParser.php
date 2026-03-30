<?php
declare(strict_types=1);

namespace PhpAsm\Parser;

use PhpAsm\Ast\ArrayAccessExpr;
use PhpAsm\Ast\ArrayLiteralExpr;
use PhpAsm\Ast\BinaryExpr;
use PhpAsm\Ast\CallExpr;
use PhpAsm\Ast\Expr;
use PhpAsm\Ast\FloatExpr;
use PhpAsm\Ast\IntExpr;
use PhpAsm\Ast\StringExpr;
use PhpAsm\Ast\VarExpr;
use RuntimeException;

final class ExprParser
{
    /**
     * @var array<int, array{int, string, int}|string>
     */
    private array $tokens;
    private int $pos = 0;

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = [];
        foreach ($tokens as $t) {
            $id = $this->tokenId($t);
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $this->tokens[] = $t;
        }
    }

    public function parseExpression(): Expr
    {
        return $this->parseBinary(0);
    }

    public function isAtEnd(): bool
    {
        return $this->peek() === null;
    }

    private function parseBinary(int $minPrec): Expr
    {
        $left = $this->parsePrimary();
        while (true) {
            $op = $this->tokenText($this->peek());
            $prec = $this->precedence($op);
            if ($prec < $minPrec) {
                break;
            }
            $this->advance();
            $right = $this->parseBinary($prec + 1);
            $left = new BinaryExpr($op, $left, $right);
        }
        return $left;
    }

    private function parsePrimary(): Expr
    {
        $token = $this->peek();
        if ($token === null) {
            throw new RuntimeException("Unexpected end of expression.");
        }

        $id = $this->tokenId($token);
        $text = $this->tokenText($token);

        if ($id === T_LNUMBER) {
            $this->advance();
            return new IntExpr((int)$text);
        }

        if ($id === T_DNUMBER) {
            $this->advance();
            return new FloatExpr((float)$text);
        }

        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $this->advance();
            return new StringExpr($this->decodeStringLiteral($text));
        }

        if ($id === T_VARIABLE) {
            $this->advance();
            $node = new VarExpr(substr($text, 1));
            if ($this->tokenText($this->peek()) === '[') {
                $this->advance();
                $idx = $this->parseExpression();
                $this->consumeText(']', "Expected ']' after array index.");
                return new ArrayAccessExpr($node, $idx);
            }
            return $node;
        }

        if ($id === T_STRING) {
            $name = $text;
            $this->advance();

            if ($this->tokenText($this->peek()) === '<') {
                $name .= '<' . $this->parseGenericTypeArg() . '>';
            }

            if ($this->tokenText($this->peek()) !== '(') {
                throw new RuntimeException("Unexpected identifier '{$name}' in expression.");
            }
            $this->advance();
            $args = [];
            if ($this->tokenText($this->peek()) !== ')') {
                while (true) {
                    $args[] = $this->parseExpression();
                    if ($this->tokenText($this->peek()) === ',') {
                        $this->advance();
                        continue;
                    }
                    break;
                }
            }
            $this->consumeText(')', "Expected ')' after function call args.");
            return new CallExpr($name, $args);
        }

        if ($text === '(') {
            $this->advance();
            $expr = $this->parseExpression();
            $this->consumeText(')', "Expected ')' after parenthesized expression.");
            return $expr;
        }

        if ($text === '[') {
            return $this->parseArrayLiteral();
        }

        throw new RuntimeException("Unsupported token in expression: {$text}");
    }

    private function parseGenericTypeArg(): string
    {
        $this->consumeText('<', "Expected '<' for generic type.");
        $id = $this->tokenId($this->peek());
        if ($id !== T_STRING) {
            throw new RuntimeException("Expected generic type name.");
        }
        $type = strtolower($this->tokenText($this->peek()));
        if (!in_array($type, ['int', 'float', 'string'], true)) {
            throw new RuntimeException("Unsupported generic type argument: {$type}");
        }
        $this->advance();
        $this->consumeText('>', "Expected '>' for generic type.");
        return $type;
    }

    private function parseArrayLiteral(): Expr
    {
        $this->consumeText('[', "Expected '['.");
        $elems = [];
        if ($this->tokenText($this->peek()) !== ']') {
            while (true) {
                $elem = $this->parseExpression();
                if (!($elem instanceof IntExpr) && !($elem instanceof FloatExpr) && !($elem instanceof StringExpr)) {
                    throw new RuntimeException("Array literal supports only int/float/string literals.");
                }
                $elems[] = $elem;
                if ($this->tokenText($this->peek()) === ',') {
                    $this->advance();
                    continue;
                }
                break;
            }
        }
        $this->consumeText(']', "Expected ']' after array literal.");

        if ($elems === []) {
            throw new RuntimeException("Empty array literal is not supported.");
        }

        $first = $elems[0];
        $type = $first instanceof IntExpr ? 'int' : ($first instanceof FloatExpr ? 'float' : 'string');
        foreach ($elems as $elem) {
            $elemType = $elem instanceof IntExpr ? 'int' : ($elem instanceof FloatExpr ? 'float' : 'string');
            if ($elemType !== $type) {
                throw new RuntimeException("Array literal elements must have same type.");
            }
        }

        return new ArrayLiteralExpr($type, $elems);
    }

    private function precedence(string $op): int
    {
        return match ($op) {
            '==', '!=', '<', '<=', '>', '>=' => 5,
            '+', '-' => 10,
            '*', '/' => 20,
            default => -1,
        };
    }

    private function consumeText(string $expected, string $error): void
    {
        if ($this->tokenText($this->peek()) !== $expected) {
            throw new RuntimeException($error);
        }
        $this->advance();
    }

    /**
     * @param array{int, string, int}|string|null $token
     */
    private function tokenText(array|string|null $token): string
    {
        if ($token === null) {
            return '';
        }
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array{int, string, int}|string|null $token
     */
    private function tokenId(array|string|null $token): ?int
    {
        if ($token === null || !is_array($token)) {
            return null;
        }
        return $token[0];
    }

    /**
     * @return array{int, string, int}|string|null
     */
    private function peek(): array|string|null
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function decodeStringLiteral(string $lit): string
    {
        $q = $lit[0] ?? '';
        $inner = substr($lit, 1, -1);
        if ($q === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
        }
        return stripcslashes($inner);
    }
}
