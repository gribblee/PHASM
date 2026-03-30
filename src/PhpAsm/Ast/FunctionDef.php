<?php
declare(strict_types=1);

namespace PhpAsm\Ast;

final class FunctionDef
{
    /**
     * @param list<array{name: string, type: string}> $params
     * @param list<AttributeDecl> $attributes
     * @param list<Stmt> $body
     */
    public function __construct(
        public string $name,
        public array $params,
        public string $returnType,
        public array $attributes,
        public array $body
    ) {}
}

final class GlobalAssign
{
    public function __construct(
        public string $name,
        public Expr $expr
    ) {}
}

final class ImportDecl
{
    /**
     * @param list<string>|null $argTypes
     */
    public function __construct(
        public string $dll,
        public string $symbol,
        public string $localName,
        public string $convention,
        public string $returnType,
        public ?array $argTypes
    ) {}
}

final class AttributeDecl
{
    /**
     * @param list<mixed> $args
     */
    public function __construct(
        public string $name,
        public array $args
    ) {}
}
