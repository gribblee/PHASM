<?php
declare(strict_types=1);

namespace PhpAsm\Link;

use PhpAsm\Ast\ArrayAccessExpr;
use PhpAsm\Ast\ArrayLiteralExpr;
use PhpAsm\Ast\AssignStmt;
use PhpAsm\Ast\BinaryExpr;
use PhpAsm\Ast\CallExpr;
use PhpAsm\Ast\Expr;
use PhpAsm\Ast\ExprStmt;
use PhpAsm\Ast\FloatExpr;
use PhpAsm\Ast\ForStmt;
use PhpAsm\Ast\FunctionDef;
use PhpAsm\Ast\GlobalAssign;
use PhpAsm\Ast\IfStmt;
use PhpAsm\Ast\ImportDecl;
use PhpAsm\Ast\IntExpr;
use PhpAsm\Ast\Program;
use PhpAsm\Ast\ReturnStmt;
use PhpAsm\Ast\Stmt;
use PhpAsm\Ast\StringExpr;
use PhpAsm\Ast\VarExpr;
use PhpAsm\Ast\WhileStmt;
use RuntimeException;

final class LinkedProgram
{
    /**
     * @param array<string, string> $functionModules
     */
    public function __construct(
        public Program $program,
        public array $functionModules
    ) {}
}

final class ModuleLinker
{
    /**
     * @param list<array{path: string, program: Program}> $modules
     */
    public function link(array $modules): LinkedProgram
    {
        $globals = [];
        $imports = [];
        $functions = [];
        $functionModules = [];

        foreach ($modules as $module) {
            $path = $module['path'];
            $program = $module['program'];

            foreach ($program->globals as $g) {
                $key = strtolower($g->name);
                if (isset($globals[$key])) {
                    throw new RuntimeException("Link error: duplicate global \${$g->name} in {$path}.");
                }
                $globals[$key] = $g;
            }

            foreach ($program->imports as $imp) {
                $key = strtolower($imp->localName);
                if (isset($imports[$key])) {
                    throw new RuntimeException("Link error: duplicate import alias {$imp->localName} in {$path}.");
                }
                $imports[$key] = $imp;
            }

            foreach ($program->functions as $fn) {
                $key = strtolower($fn->name);
                if (isset($functions[$key])) {
                    throw new RuntimeException("Link error: duplicate function {$fn->name} in {$path}.");
                }
                $functions[$key] = $fn;
                $functionModules[$key] = $path;
            }
        }

        $this->verifyCallsResolved($functions, $imports);

        return new LinkedProgram(
            new Program(
                true,
                array_values($globals),
                array_values($imports),
                array_values($functions)
            ),
            $functionModules
        );
    }

    /**
     * @param array<string, FunctionDef> $functions
     * @param array<string, ImportDecl> $imports
     */
    private function verifyCallsResolved(array $functions, array $imports): void
    {
        $builtins = [
            'printf' => true,
            'heap_alloc' => true,
            'heap_free' => true,
            'ptr_get' => true,
            'ptr_set' => true,
            'typed' => true,
        ];

        foreach (array_keys($functions) as $fnName) {
            if (str_starts_with($fnName, 'heap_alloc_t<')) {
                $builtins[$fnName] = true;
            }
        }

        $declared = [];
        foreach (array_keys($functions) as $name) {
            $declared[$name] = true;
        }
        foreach (array_keys($imports) as $name) {
            $declared[$name] = true;
        }

        foreach ($functions as $fn) {
            $calls = [];
            foreach ($fn->body as $stmt) {
                $this->collectCallsFromStmt($stmt, $calls);
            }
            foreach (array_keys($calls) as $name) {
                if (isset($builtins[$name])) {
                    continue;
                }
                if (preg_match('/^heap_alloc_t<.+>$/', $name) === 1) {
                    continue;
                }
                if (!isset($declared[$name])) {
                    throw new RuntimeException("Link error: unresolved symbol '{$name}' referenced from {$fn->name}.");
                }
            }
        }
    }

    /**
     * @param array<string, bool> $calls
     */
    private function collectCallsFromStmt(Stmt $stmt, array &$calls): void
    {
        if ($stmt instanceof AssignStmt) {
            $this->collectCallsFromExpr($stmt->expr, $calls);
            return;
        }
        if ($stmt instanceof ReturnStmt) {
            if ($stmt->expr !== null) {
                $this->collectCallsFromExpr($stmt->expr, $calls);
            }
            return;
        }
        if ($stmt instanceof ExprStmt) {
            $this->collectCallsFromExpr($stmt->expr, $calls);
            return;
        }
        if ($stmt instanceof IfStmt || $stmt instanceof WhileStmt) {
            $this->collectCallsFromExpr($stmt->condition, $calls);
            foreach ($stmt->body as $s) {
                $this->collectCallsFromStmt($s, $calls);
            }
            return;
        }
        if ($stmt instanceof ForStmt) {
            if ($stmt->init !== null) {
                $this->collectCallsFromStmt($stmt->init, $calls);
            }
            if ($stmt->condition !== null) {
                $this->collectCallsFromExpr($stmt->condition, $calls);
            }
            if ($stmt->post !== null) {
                $this->collectCallsFromStmt($stmt->post, $calls);
            }
            foreach ($stmt->body as $s) {
                $this->collectCallsFromStmt($s, $calls);
            }
        }
    }

    /**
     * @param array<string, bool> $calls
     */
    private function collectCallsFromExpr(Expr $expr, array &$calls): void
    {
        if ($expr instanceof CallExpr) {
            $calls[strtolower($expr->name)] = true;
            foreach ($expr->args as $arg) {
                $this->collectCallsFromExpr($arg, $calls);
            }
            return;
        }
        if ($expr instanceof BinaryExpr) {
            $this->collectCallsFromExpr($expr->left, $calls);
            $this->collectCallsFromExpr($expr->right, $calls);
            return;
        }
        if ($expr instanceof ArrayAccessExpr) {
            $this->collectCallsFromExpr($expr->arrayExpr, $calls);
            $this->collectCallsFromExpr($expr->indexExpr, $calls);
            return;
        }
        if ($expr instanceof ArrayLiteralExpr) {
            foreach ($expr->elements as $el) {
                $this->collectCallsFromExpr($el, $calls);
            }
            return;
        }

        if (
            $expr instanceof IntExpr ||
            $expr instanceof FloatExpr ||
            $expr instanceof StringExpr ||
            $expr instanceof VarExpr
        ) {
            return;
        }

        throw new RuntimeException('Link error: unknown expression type in call resolver.');
    }
}
