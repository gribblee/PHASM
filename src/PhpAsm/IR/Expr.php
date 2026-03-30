<?php
declare(strict_types=1);

namespace PhpAsm\IR;

abstract class Expr
{
    public function __construct(public string $type) {}
}

final class IntExpr extends Expr
{
    public function __construct(public int $value) { parent::__construct('int'); }
}

final class FloatExpr extends Expr
{
    public function __construct(public float $value) { parent::__construct('float'); }
}

final class StringExpr extends Expr
{
    public function __construct(public string $value) { parent::__construct('string'); }
}

final class VarExpr extends Expr
{
    public function __construct(public string $name, string $type) { parent::__construct($type); }
}

final class ArrayLiteralExpr extends Expr
{
    /**
     * @param list<IntExpr|FloatExpr|StringExpr> $elements
     */
    public function __construct(
        public string $elementType,
        public array $elements
    ) {
        parent::__construct($elementType . '[]');
    }
}

final class ArrayAccessExpr extends Expr
{
    public function __construct(
        public Expr $arrayExpr,
        public Expr $indexExpr,
        string $type
    ) {
        parent::__construct($type);
    }
}

final class BinaryExpr extends Expr
{
    public function __construct(
        public string $op,
        public Expr $left,
        public Expr $right
    ) {
        parent::__construct('int');
    }
}

final class CallExpr extends Expr
{
    /**
     * @param list<Expr> $args
     */
    public function __construct(
        public string $name,
        public array $args,
        string $type
    ) {
        parent::__construct($type);
    }
}

