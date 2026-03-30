<?php
declare(strict_types=1);

namespace PhpAsm\IR;

abstract class Stmt {}

final class AssignStmt extends Stmt
{
    public function __construct(
        public string $varName,
        public Expr $expr
    ) {}
}

final class ReturnStmt extends Stmt
{
    public function __construct(public ?Expr $expr) {}
}

final class ExprStmt extends Stmt
{
    public function __construct(public Expr $expr) {}
}

final class IfStmt extends Stmt
{
    /**
     * @param list<Stmt> $body
     */
    public function __construct(
        public Expr $condition,
        public array $body
    ) {}
}

final class WhileStmt extends Stmt
{
    /**
     * @param list<Stmt> $body
     */
    public function __construct(
        public Expr $condition,
        public array $body
    ) {}
}

final class ForStmt extends Stmt
{
    /**
     * @param list<Stmt> $body
     */
    public function __construct(
        public ?Stmt $init,
        public ?Expr $condition,
        public ?Stmt $post,
        public array $body
    ) {}
}

