<?php
declare(strict_types=1);

namespace PhpAsm\Ast;

final class Program
{
    /**
     * @param list<GlobalAssign> $globals
     * @param list<ImportDecl> $imports
     * @param list<FunctionDef> $functions
     */
    public function __construct(
        public bool $hasStrictTypes,
        public array $globals,
        public array $imports,
        public array $functions
    ) {}
}
