<?php
declare(strict_types=1);

namespace PhpAsm\Ast;

abstract class Expr {}

final class IntExpr extends Expr
{
    public function __construct(public int $value) {}
}

final class FloatExpr extends Expr
{
    public function __construct(public float $value) {}
}

final class StringExpr extends Expr
{
    public function __construct(public string $value) {}
}

final class VarExpr extends Expr
{
    public function __construct(public string $name) {}
}

final class ArrayLiteralExpr extends Expr
{
    /**
     * @param list<IntExpr|FloatExpr|StringExpr> $elements
     */
    public function __construct(
        public string $type,
        public array $elements
    ) {}
}

final class ArrayAccessExpr extends Expr
{
    public function __construct(
        public Expr $arrayExpr,
        public Expr $indexExpr
    ) {}
}

final class BinaryExpr extends Expr
{
    public function __construct(
        public string $op,
        public Expr $left,
        public Expr $right
    ) {}
}

final class CallExpr extends Expr
{
    /**
     * @param list<Expr> $args
     */
    public function __construct(
        public string $name,
        public array $args
    ) {}
}

