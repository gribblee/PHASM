<?php
declare(strict_types=1);

namespace PhpAsm\IR;

use PhpAsm\Ast\ArrayAccessExpr as AstArrayAccessExpr;
use PhpAsm\Ast\ArrayLiteralExpr as AstArrayLiteralExpr;
use PhpAsm\Ast\AssignStmt as AstAssignStmt;
use PhpAsm\Ast\BinaryExpr as AstBinaryExpr;
use PhpAsm\Ast\CallExpr as AstCallExpr;
use PhpAsm\Ast\Expr as AstExpr;
use PhpAsm\Ast\ExprStmt as AstExprStmt;
use PhpAsm\Ast\FloatExpr as AstFloatExpr;
use PhpAsm\Ast\ForStmt as AstForStmt;
use PhpAsm\Ast\FunctionDef as AstFunctionDef;
use PhpAsm\Ast\GlobalAssign as AstGlobalAssign;
use PhpAsm\Ast\IfStmt as AstIfStmt;
use PhpAsm\Ast\ImportDecl as AstImportDecl;
use PhpAsm\Ast\IntExpr as AstIntExpr;
use PhpAsm\Ast\Program as AstProgram;
use PhpAsm\Ast\ReturnStmt as AstReturnStmt;
use PhpAsm\Ast\Stmt as AstStmt;
use PhpAsm\Ast\StringExpr as AstStringExpr;
use PhpAsm\Ast\VarExpr as AstVarExpr;
use PhpAsm\Ast\WhileStmt as AstWhileStmt;
use RuntimeException;

final class IRGenerator
{
    /**
     * @var array<string, AstFunctionDef>
     */
    private array $fnMap = [];

    /**
     * @var array<string, string>
     */
    private array $globalTypes = [];

    private bool $usesPrintf = false;
    private bool $usesHeap = false;

    /**
     * @var array<string, ImportFunc>
     */
    private array $imports = [];
    /**
     * @var array<string, array{op: string, params: list<array{name: string, type: string}>, returnType: string}>
     */
    private array $intrinsicAliases = [];

    /**
     * @var array<string, bool>
     */
    private array $globalNames = [];

    public function generate(AstProgram $program): Program
    {
        foreach ($program->functions as $fn) {
            $intrinsicAttr = $this->findAttribute($fn, 'intrinsic');
            $importAttr = $this->findAttribute($fn, 'import');
            if ($intrinsicAttr !== null && $importAttr !== null) {
                throw new RuntimeException("Function {$fn->name} cannot be both intrinsic and import.");
            }

            if ($intrinsicAttr !== null) {
                if (count($intrinsicAttr->args) !== 1 || !is_string($intrinsicAttr->args[0])) {
                    throw new RuntimeException("Attribute intrinsic on {$fn->name} expects one string argument.");
                }
                $alias = $fn->name;
                if (isset($this->intrinsicAliases[$alias])) {
                    throw new RuntimeException("Duplicate intrinsic alias: {$alias}");
                }
                $this->intrinsicAliases[$alias] = [
                    'op' => strtolower($intrinsicAttr->args[0]),
                    'params' => $fn->params,
                    'returnType' => $fn->returnType,
                ];
                continue;
            }

            if ($importAttr !== null) {
                $decl = $this->buildImportFromAttribute($fn, $importAttr->args);
                $irImport = $this->buildImport($decl);
                if (isset($this->imports[$irImport->localName])) {
                    throw new RuntimeException("Duplicate imported function alias: {$irImport->localName}");
                }
                $this->imports[$irImport->localName] = $irImport;
                continue;
            }

            if (isset($this->fnMap[$fn->name])) {
                throw new RuntimeException("Duplicate function: {$fn->name}");
            }
            $this->fnMap[$fn->name] = $fn;
        }

        if (!isset($this->fnMap['main'])) {
            throw new RuntimeException("Function main not found.");
        }
        if ($this->fnMap['main']->returnType !== 'int' || $this->fnMap['main']->params !== []) {
            throw new RuntimeException("main() must be: function main(): int");
        }

        $irGlobals = [];
        foreach ($program->globals as $g) {
            if (isset($this->globalNames[$g->name])) {
                throw new RuntimeException("Duplicate global variable: \${$g->name}");
            }
            $irGlobal = $this->buildGlobal($g);
            $irGlobals[] = $irGlobal;
            $this->globalTypes[$irGlobal->name] = $irGlobal->type;
            $this->globalNames[$g->name] = true;
        }

        $irImports = [];
        foreach ($this->imports as $impFromAttr) {
            $irImports[] = $impFromAttr;
        }
        foreach ($program->imports as $imp) {
            $irImport = $this->buildImport($imp);
            if (isset($this->fnMap[$irImport->localName])) {
                throw new RuntimeException("Imported function name conflicts with user function: {$irImport->localName}");
            }
            if (isset($this->imports[$irImport->localName])) {
                throw new RuntimeException("Duplicate imported function alias: {$irImport->localName}");
            }
            $this->imports[$irImport->localName] = $irImport;
            $irImports[] = $irImport;
        }

        $irFunctions = [];
        foreach ($program->functions as $fn) {
            if ($this->findAttribute($fn, 'intrinsic') !== null || $this->findAttribute($fn, 'import') !== null) {
                continue;
            }
            $irFunctions[] = $this->buildFunction($fn);
        }

        return new Program($irGlobals, $irImports, $irFunctions, $this->usesPrintf, $this->usesHeap);
    }

    private function buildImport(AstImportDecl $import): ImportFunc
    {
        $localName = strtolower($import->localName);
        if ($import->returnType === 'float') {
            throw new RuntimeException("Imported float return ABI is not implemented yet for {$localName}.");
        }
        if ($import->argTypes !== null) {
            foreach ($import->argTypes as $t) {
                if ($t === 'float') {
                    throw new RuntimeException("Imported float arguments ABI is not implemented yet for {$localName}.");
                }
            }
        }
        return new ImportFunc(
            $import->dll,
            $import->symbol,
            $localName,
            $import->convention,
            $import->returnType,
            $import->argTypes
        );
    }

    private function buildGlobal(AstGlobalAssign $global): GlobalVar
    {
        $expr = $global->expr;
        if ($expr instanceof AstIntExpr) {
            return new GlobalVar($global->name, 'int', new IntExpr($expr->value));
        }
        if ($expr instanceof AstFloatExpr) {
            return new GlobalVar($global->name, 'float', new FloatExpr($expr->value));
        }
        if ($expr instanceof AstStringExpr) {
            return new GlobalVar($global->name, 'string', new StringExpr($expr->value));
        }
        if ($expr instanceof AstArrayLiteralExpr) {
            $elements = [];
            foreach ($expr->elements as $e) {
                if ($e instanceof AstIntExpr) {
                    $elements[] = new IntExpr($e->value);
                } elseif ($e instanceof AstFloatExpr) {
                    $elements[] = new FloatExpr($e->value);
                } elseif ($e instanceof AstStringExpr) {
                    $elements[] = new StringExpr($e->value);
                } else {
                    throw new RuntimeException("Unsupported global array element.");
                }
            }
            return new GlobalVar($global->name, $expr->type . '[]', new ArrayLiteralExpr($expr->type, $elements));
        }
        throw new RuntimeException("Global \${$global->name} must be static literal.");
    }

    private function buildFunction(AstFunctionDef $fn): FunctionDef
    {
        $scope = new Scope($fn->name, $fn->returnType, $this->globalTypes, $fn->params);
        $body = $this->buildStatements($fn->body, $scope, $fn->returnType);
        return new FunctionDef($fn->name, $fn->params, $fn->returnType, $body);
    }

    /**
     * @param list<AstStmt> $stmts
     * @return list<Stmt>
     */
    private function buildStatements(array $stmts, Scope $scope, string $returnType): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof AstAssignStmt) {
                $expr = $this->buildExpr($stmt->expr, $scope);
                $resolved = $scope->resolveVarType($stmt->varName);
                if ($resolved !== null && $resolved !== $expr->type) {
                    throw new RuntimeException("Type mismatch for \${$stmt->varName} in {$scope->functionName}.");
                }
                if ($scope->hasLocal($stmt->varName) || $resolved === null) {
                    $scope->assignLocal($stmt->varName, $expr->type);
                }
                $out[] = new AssignStmt($stmt->varName, $expr);
                continue;
            }

            if ($stmt instanceof AstReturnStmt) {
                if ($stmt->expr === null) {
                    if ($returnType !== 'void') {
                        throw new RuntimeException("Non-void function {$scope->functionName} must return value.");
                    }
                    $out[] = new ReturnStmt(null);
                    continue;
                }
                $expr = $this->buildExpr($stmt->expr, $scope);
                if ($expr->type !== $returnType) {
                    throw new RuntimeException("Return type mismatch in {$scope->functionName}.");
                }
                $out[] = new ReturnStmt($expr);
                continue;
            }

            if ($stmt instanceof AstExprStmt) {
                $out[] = new ExprStmt($this->buildExpr($stmt->expr, $scope));
                continue;
            }

            if ($stmt instanceof AstIfStmt) {
                $cond = $this->buildExpr($stmt->condition, $scope);
                if ($cond->type !== 'int') {
                    throw new RuntimeException("if condition must be int in {$scope->functionName}.");
                }
                $branchScope = $scope->copy();
                $body = $this->buildStatements($stmt->body, $branchScope, $returnType);
                $scope->mergeMaySkip($branchScope);
                $out[] = new IfStmt($cond, $body);
                continue;
            }

            if ($stmt instanceof AstWhileStmt) {
                $cond = $this->buildExpr($stmt->condition, $scope);
                if ($cond->type !== 'int') {
                    throw new RuntimeException("while condition must be int in {$scope->functionName}.");
                }
                $loopScope = $scope->copy();
                $body = $this->buildStatements($stmt->body, $loopScope, $returnType);
                $scope->mergeMaySkip($loopScope);
                $out[] = new WhileStmt($cond, $body);
                continue;
            }

            if ($stmt instanceof AstForStmt) {
                $init = $stmt->init === null ? null : $this->buildStatements([$stmt->init], $scope, $returnType)[0];
                $loopScope = $scope->copy();
                $cond = $stmt->condition === null ? null : $this->buildExpr($stmt->condition, $loopScope);
                if ($cond !== null && $cond->type !== 'int') {
                    throw new RuntimeException("for condition must be int in {$scope->functionName}.");
                }
                $body = $this->buildStatements($stmt->body, $loopScope, $returnType);
                $post = $stmt->post === null ? null : $this->buildStatements([$stmt->post], $loopScope, $returnType)[0];
                $scope->mergeMaySkip($loopScope);
                $out[] = new ForStmt($init, $cond, $post, $body);
                continue;
            }

            throw new RuntimeException("Unsupported statement in {$scope->functionName}.");
        }
        return $out;
    }

    private function buildExpr(AstExpr $expr, Scope $scope): Expr
    {
        if ($expr instanceof AstIntExpr) {
            return new IntExpr($expr->value);
        }
        if ($expr instanceof AstFloatExpr) {
            return new FloatExpr($expr->value);
        }
        if ($expr instanceof AstStringExpr) {
            return new StringExpr($expr->value);
        }
        if ($expr instanceof AstArrayLiteralExpr) {
            $items = [];
            foreach ($expr->elements as $el) {
                $items[] = $this->buildExpr($el, $scope);
            }
            return new ArrayLiteralExpr($expr->type, $items);
        }
        if ($expr instanceof AstVarExpr) {
            $type = $scope->resolveVarType($expr->name);
            if ($type === null) {
                throw new RuntimeException("Undefined variable \${$expr->name} in {$scope->functionName}.");
            }
            if (!$scope->isDefinitelyAssigned($expr->name)) {
                throw new RuntimeException("Variable \${$expr->name} may be uninitialized in {$scope->functionName}.");
            }
            return new VarExpr($expr->name, $type);
        }
        if ($expr instanceof AstArrayAccessExpr) {
            $arr = $this->buildExpr($expr->arrayExpr, $scope);
            if (!str_ends_with($arr->type, '[]')) {
                throw new RuntimeException("Array access on non-array type {$arr->type}.");
            }
            $idx = $this->buildExpr($expr->indexExpr, $scope);
            if ($idx->type !== 'int') {
                throw new RuntimeException("Array index must be int.");
            }
            return new ArrayAccessExpr($arr, $idx, substr($arr->type, 0, -2));
        }
        if ($expr instanceof AstBinaryExpr) {
            $left = $this->buildExpr($expr->left, $scope);
            $right = $this->buildExpr($expr->right, $scope);
            if ($left->type !== 'int' || $right->type !== 'int') {
                throw new RuntimeException("Binary operator {$expr->op} supports only int operands.");
            }
            return new BinaryExpr($expr->op, $left, $right);
        }
        if ($expr instanceof AstCallExpr) {
            $name = strtolower($expr->name);
            $args = [];
            foreach ($expr->args as $a) {
                $args[] = $this->buildExpr($a, $scope);
            }

            if ($name === 'typed') {
                if (count($expr->args) !== 2 || !($expr->args[0] instanceof AstStringExpr)) {
                    throw new RuntimeException("typed(...) expects (string type, expression).");
                }
                $targetType = strtolower(trim($expr->args[0]->value));
                if (!$this->isKnownValueType($targetType)) {
                    throw new RuntimeException("typed(...) target type is unknown: {$targetType}");
                }
                $source = $args[1];
                if (!$this->canReinterpretType($source->type, $targetType)) {
                    throw new RuntimeException("typed(...) cannot reinterpret {$source->type} as {$targetType}.");
                }
                return new CallExpr('__intrinsic_typed', [$source], $targetType);
            }

            if (isset($this->intrinsicAliases[$name])) {
                return $this->buildIntrinsicAliasCall($name, $args);
            }

            if ($name === 'printf') {
                if (count($args) < 1 || $args[0]->type !== 'string') {
                    throw new RuntimeException("printf first argument must be string.");
                }
                for ($i = 1, $n = count($args); $i < $n; $i++) {
                    if (!in_array($args[$i]->type, ['int', 'float', 'string'], true)) {
                        throw new RuntimeException("Unsupported printf argument type {$args[$i]->type}.");
                    }
                }
                $this->usesPrintf = true;
                return new CallExpr($name, $args, 'int');
            }

            if ($name === 'heap_alloc') {
                if (count($args) !== 1 || $args[0]->type !== 'int') {
                    throw new RuntimeException("heap_alloc expects 1 int argument.");
                }
                $this->usesHeap = true;
                return new CallExpr($name, $args, 'hptr<int>');
            }

            if (preg_match('/^heap_alloc_t<([a-z]+)>$/', $name, $m) === 1) {
                $elemType = $m[1];
                if (!in_array($elemType, ['int', 'float', 'string'], true)) {
                    throw new RuntimeException("Unsupported heap_alloc_t element type: {$elemType}");
                }
                if (count($args) !== 1 || $args[0]->type !== 'int') {
                    throw new RuntimeException("heap_alloc_t<T> expects 1 int count argument.");
                }
                $this->usesHeap = true;
                return new CallExpr($name, $args, "hptr<{$elemType}>");
            }

            if ($name === 'heap_free') {
                if (count($args) !== 1 || !preg_match('/^hptr<(int|float|string)>$/', $args[0]->type)) {
                    throw new RuntimeException("heap_free expects 1 hptr<T> argument.");
                }
                $this->usesHeap = true;
                return new CallExpr($name, $args, 'int');
            }

            if ($name === 'ptr_get') {
                if (count($args) !== 2 || $args[1]->type !== 'int') {
                    throw new RuntimeException("ptr_get expects (hptr<T> ptr, int index).");
                }
                if (preg_match('/^hptr<(int|float|string)>$/', $args[0]->type, $m) !== 1) {
                    throw new RuntimeException("ptr_get expects first arg hptr<T>.");
                }
                return new CallExpr($name, $args, $m[1]);
            }

            if ($name === 'ptr_set') {
                if (count($args) !== 3 || $args[1]->type !== 'int') {
                    throw new RuntimeException("ptr_set expects (hptr<T> ptr, int index, T value).");
                }
                if (preg_match('/^hptr<(int|float|string)>$/', $args[0]->type, $m) !== 1) {
                    throw new RuntimeException("ptr_set expects first arg hptr<T>.");
                }
                $elemType = $m[1];
                if ($args[2]->type !== $elemType) {
                    throw new RuntimeException("ptr_set value type must match pointer element type {$elemType}.");
                }
                return new CallExpr($name, $args, $elemType);
            }

            $imp = $this->imports[$name] ?? null;
            if ($imp !== null) {
                if ($imp->argTypes !== null) {
                    if (count($imp->argTypes) !== count($args)) {
                        throw new RuntimeException("Imported function {$imp->localName} expects " . count($imp->argTypes) . " args.");
                    }
                    foreach ($imp->argTypes as $idx => $type) {
                        if ($args[$idx]->type !== $type) {
                            throw new RuntimeException("Argument #" . ($idx + 1) . " type mismatch in {$imp->localName}.");
                        }
                    }
                }
                return new CallExpr($name, $args, $imp->returnType);
            }

            $fn = $this->fnMap[$name] ?? null;
            if ($fn === null) {
                throw new RuntimeException("Call to undefined function {$expr->name}.");
            }
            if (count($fn->params) !== count($args)) {
                throw new RuntimeException("Function {$fn->name} expects " . count($fn->params) . " args.");
            }
            foreach ($fn->params as $idx => $p) {
                if ($args[$idx]->type !== $p['type']) {
                    throw new RuntimeException("Argument #" . ($idx + 1) . " type mismatch in {$fn->name}.");
                }
            }
            return new CallExpr($name, $args, $fn->returnType);
        }

        throw new RuntimeException("Unknown expression kind.");
    }

    /**
     * @param list<Expr> $args
     */
    private function buildIntrinsicAliasCall(string $alias, array $args): CallExpr
    {
        $spec = $this->intrinsicAliases[$alias];
        if (count($spec['params']) !== count($args)) {
            throw new RuntimeException("Intrinsic alias {$alias} expects " . count($spec['params']) . " args.");
        }
        foreach ($spec['params'] as $idx => $p) {
            if ($args[$idx]->type !== $p['type']) {
                throw new RuntimeException("Argument #" . ($idx + 1) . " type mismatch in intrinsic alias {$alias}.");
            }
        }

        $op = strtolower($spec['op']);
        $ret = $spec['returnType'];

        if ($op === 'heap_alloc') {
            if (count($args) !== 1 || $args[0]->type !== 'int') {
                throw new RuntimeException("Intrinsic heap_alloc expects int bytes.");
            }
            if ($ret !== 'hptr<int>') {
                throw new RuntimeException("Intrinsic heap_alloc must return hptr<int>.");
            }
            $this->usesHeap = true;
            return new CallExpr('__intrinsic_heap_alloc', $args, $ret);
        }

        if ($op === 'heap_alloc_t') {
            if (count($args) !== 1 || $args[0]->type !== 'int') {
                throw new RuntimeException("Intrinsic heap_alloc_t expects int count.");
            }
            if (!$this->isHeapPtrType($ret)) {
                throw new RuntimeException("Intrinsic heap_alloc_t must return hptr<T>.");
            }
            $this->usesHeap = true;
            return new CallExpr('__intrinsic_heap_alloc_t', $args, $ret);
        }

        if ($op === 'heap_free') {
            if (count($args) !== 1 || !$this->isHeapPtrType($args[0]->type)) {
                throw new RuntimeException("Intrinsic heap_free expects hptr<T>.");
            }
            if ($ret !== 'int') {
                throw new RuntimeException("Intrinsic heap_free must return int.");
            }
            $this->usesHeap = true;
            return new CallExpr('__intrinsic_heap_free', $args, 'int');
        }

        if ($op === 'ptr_get') {
            if (count($args) !== 2 || $args[1]->type !== 'int' || !$this->isHeapPtrType($args[0]->type)) {
                throw new RuntimeException("Intrinsic ptr_get expects (hptr<T>, int).");
            }
            $elem = $this->heapPtrPointeeType($args[0]->type);
            if ($elem === null || $ret !== $elem) {
                throw new RuntimeException("Intrinsic ptr_get return type must match pointee type {$elem}.");
            }
            return new CallExpr('__intrinsic_ptr_get', $args, $elem);
        }

        if ($op === 'ptr_set') {
            if (count($args) !== 3 || $args[1]->type !== 'int' || !$this->isHeapPtrType($args[0]->type)) {
                throw new RuntimeException("Intrinsic ptr_set expects (hptr<T>, int, T).");
            }
            $elem = $this->heapPtrPointeeType($args[0]->type);
            if ($elem === null || $args[2]->type !== $elem || $ret !== $elem) {
                throw new RuntimeException("Intrinsic ptr_set type mismatch, expected value/return {$elem}.");
            }
            return new CallExpr('__intrinsic_ptr_set', $args, $elem);
        }

        throw new RuntimeException("Unknown intrinsic op '{$op}' on alias {$alias}.");
    }

    private function isHeapPtrType(string $type): bool
    {
        return $this->heapPtrPointeeType($type) !== null;
    }

    private function heapPtrPointeeType(string $type): ?string
    {
        $type = strtolower($type);
        if (!str_starts_with($type, 'hptr<') || !str_ends_with($type, '>')) {
            return null;
        }
        $inner = substr($type, 5, -1);
        if ($inner === '') {
            return null;
        }
        return $inner;
    }

    private function isKnownValueType(string $type): bool
    {
        $type = strtolower(trim($type));
        if (str_ends_with($type, '[]')) {
            $base = substr($type, 0, -2);
            return in_array($base, ['int', 'float', 'string'], true);
        }
        if ((str_starts_with($type, 'ptr<') || str_starts_with($type, 'hptr<')) && str_ends_with($type, '>')) {
            $inner = substr($type, strpos($type, '<') + 1, -1);
            return $inner !== '' && $this->isKnownValueType($inner);
        }
        return in_array($type, ['int', 'float', 'string'], true);
    }

    private function isPointerLikeType(string $type): bool
    {
        $type = strtolower(trim($type));
        return (str_starts_with($type, 'ptr<') || str_starts_with($type, 'hptr<')) && str_ends_with($type, '>');
    }

    private function canReinterpretType(string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        if ($from === $to) {
            return true;
        }
        if ($from === 'int' && $this->isPointerLikeType($to)) {
            return true;
        }
        if ($to === 'int' && $this->isPointerLikeType($from)) {
            return true;
        }
        return false;
    }

    private function findAttribute(AstFunctionDef $fn, string $name): ?\PhpAsm\Ast\AttributeDecl
    {
        foreach ($fn->attributes as $attr) {
            if (strtolower($attr->name) === strtolower($name)) {
                return $attr;
            }
        }
        return null;
    }

    /**
     * @param list<mixed> $args
     */
    private function buildImportFromAttribute(AstFunctionDef $fn, array $args): AstImportDecl
    {
        if (count($args) < 2) {
            throw new RuntimeException("Attribute import on {$fn->name} needs at least dll and symbol.");
        }
        if (!is_string($args[0]) || !is_string($args[1])) {
            throw new RuntimeException("Attribute import arguments 1 and 2 must be strings.");
        }
        $dll = $args[0];
        $symbol = $args[1];
        $abiFromAttr = $this->extractAbiAttribute($fn);
        $conventionFromImportArg = isset($args[2]) && is_string($args[2]) ? strtolower($args[2]) : null;
        if ($conventionFromImportArg !== null && $abiFromAttr !== null && $conventionFromImportArg !== $abiFromAttr) {
            throw new RuntimeException("Conflicting ABI on {$fn->name}: import attr has '{$conventionFromImportArg}', #[ABI] has '{$abiFromAttr}'.");
        }
        $convention = $conventionFromImportArg ?? $abiFromAttr ?? 'stdcall';
        if (!in_array($convention, ['stdcall', 'cdecl'], true)) {
            throw new RuntimeException("ABI for {$fn->name} must be 'stdcall' or 'cdecl'.");
        }
        $returnType = isset($args[3]) && is_string($args[3]) ? strtolower($args[3]) : $fn->returnType;
        $argTypes = null;
        if (isset($args[4])) {
            if ($args[4] !== null && !is_array($args[4])) {
                throw new RuntimeException("Attribute import arg #5 must be string[] or null.");
            }
            if (is_array($args[4])) {
                $argTypes = [];
                foreach ($args[4] as $v) {
                    if (!is_string($v)) {
                        throw new RuntimeException("Attribute import arg types must be strings.");
                    }
                    $argTypes[] = strtolower($v);
                }
            }
        }
        if ($argTypes === null) {
            $argTypes = array_map(static fn(array $p): string => $p['type'], $fn->params);
        }
        $localName = isset($args[5]) && is_string($args[5]) ? $args[5] : $fn->name;

        return new AstImportDecl($dll, $symbol, $localName, $convention, $returnType, $argTypes);
    }

    private function extractAbiAttribute(AstFunctionDef $fn): ?string
    {
        $abi = null;
        foreach ($fn->attributes as $attr) {
            if (strtolower($attr->name) !== 'abi') {
                continue;
            }
            if (count($attr->args) !== 1 || !is_string($attr->args[0])) {
                throw new RuntimeException("Attribute ABI on {$fn->name} expects one string argument.");
            }
            $current = strtolower($attr->args[0]);
            if (!in_array($current, ['stdcall', 'cdecl'], true)) {
                throw new RuntimeException("Attribute ABI on {$fn->name} must be 'stdcall' or 'cdecl'.");
            }
            if ($abi !== null && $abi !== $current) {
                throw new RuntimeException("Conflicting duplicate #[ABI] attributes on {$fn->name}.");
            }
            $abi = $current;
        }
        return $abi;
    }
}

final class Scope
{
    /**
     * @var array<string, string>
     */
    public array $localsTypes = [];

    /**
     * @var array<string, bool>
     */
    public array $assignedLocals = [];

    /**
     * @var array<string, string>
     */
    private array $params = [];

    /**
     * @param array<string, string> $globals
     * @param list<array{name: string, type: string}> $params
     */
    public function __construct(
        public string $functionName,
        public string $returnType,
        private array $globals,
        array $params
    ) {
        foreach ($params as $p) {
            $this->params[$p['name']] = $p['type'];
        }
    }

    public function resolveVarType(string $name): ?string
    {
        if (isset($this->localsTypes[$name])) {
            return $this->localsTypes[$name];
        }
        if (isset($this->params[$name])) {
            return $this->params[$name];
        }
        return $this->globals[$name] ?? null;
    }

    public function hasLocal(string $name): bool
    {
        return isset($this->localsTypes[$name]);
    }

    public function assignLocal(string $name, string $type): void
    {
        $this->localsTypes[$name] = $type;
        $this->assignedLocals[$name] = true;
    }

    public function isDefinitelyAssigned(string $name): bool
    {
        if (isset($this->localsTypes[$name])) {
            return isset($this->assignedLocals[$name]);
        }
        if (isset($this->params[$name])) {
            return true;
        }
        if (isset($this->globals[$name])) {
            return true;
        }
        return false;
    }

    public function copy(): self
    {
        $clone = new self($this->functionName, $this->returnType, $this->globals, []);
        $clone->params = $this->params;
        $clone->localsTypes = $this->localsTypes;
        $clone->assignedLocals = $this->assignedLocals;
        return $clone;
    }

    public function mergeMaySkip(self $branch): void
    {
        foreach ($branch->localsTypes as $name => $type) {
            if (isset($this->localsTypes[$name]) && $this->localsTypes[$name] !== $type) {
                throw new RuntimeException("Type mismatch for \${$name} across control-flow.");
            }
            if (!isset($this->localsTypes[$name])) {
                $this->localsTypes[$name] = $type;
            }
        }
        foreach (array_keys($this->assignedLocals) as $name) {
            if (!isset($branch->assignedLocals[$name])) {
                unset($this->assignedLocals[$name]);
            }
        }
    }
}
