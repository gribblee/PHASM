<?php
declare(strict_types=1);

namespace PhpAsm\IR;

final class Program
{
    /**
     * @param list<GlobalVar> $globals
     * @param list<ImportFunc> $imports
     * @param list<FunctionDef> $functions
     */
    public function __construct(
        public array $globals,
        public array $imports,
        public array $functions,
        public bool $usesPrintf,
        public bool $usesHeap
    ) {}
}

final class GlobalVar
{
    public function __construct(
        public string $name,
        public string $type,
        public Expr $initializer
    ) {}
}

final class FunctionDef
{
    /**
     * @param list<array{name: string, type: string}> $params
     * @param list<Stmt> $body
     */
    public function __construct(
        public string $name,
        public array $params,
        public string $returnType,
        public array $body
    ) {}
}

final class ImportFunc
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
