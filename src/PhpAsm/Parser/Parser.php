<?php
declare(strict_types=1);

namespace PhpAsm\Parser;

use PhpAsm\Ast\AssignStmt;
use PhpAsm\Ast\AttributeDecl;
use PhpAsm\Ast\Expr;
use PhpAsm\Ast\ExprStmt;
use PhpAsm\Ast\ForStmt;
use PhpAsm\Ast\FunctionDef;
use PhpAsm\Ast\GlobalAssign;
use PhpAsm\Ast\IfStmt;
use PhpAsm\Ast\ImportDecl;
use PhpAsm\Ast\Program;
use PhpAsm\Ast\ReturnStmt;
use PhpAsm\Ast\Stmt;
use PhpAsm\Ast\WhileStmt;
use RuntimeException;

final class Parser
{
    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    public function parse(array $tokens, string $source): Program
    {
        $hasStrictTypes = preg_match('/declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/i', $source) === 1;
        if (!$hasStrictTypes) {
            throw new RuntimeException("Source must contain declare(strict_types=1);");
        }

        $globals = [];
        $imports = [];
        $functions = [];
        $pendingAttributes = [];

        $i = 0;
        $count = count($tokens);
        while ($i < $count) {
            $token = $tokens[$i];
            $id = $this->tokenId($token);
            $text = $this->tokenText($token);

            if ($id === T_DECLARE) {
                if ($pendingAttributes !== []) {
                    throw new RuntimeException("Attributes must be followed by function declaration.");
                }
                while ($i < $count && $this->tokenText($tokens[$i]) !== ';') {
                    $i++;
                }
                $i++;
                continue;
            }

            if ($id === T_ATTRIBUTE || $text === '#[') {
                $pendingAttributes = array_merge($pendingAttributes, $this->parseAttributes($tokens, $i));
                continue;
            }

            if ($id === T_FUNCTION) {
                [$fn, $next] = $this->parseFunction($tokens, $i, $pendingAttributes);
                $pendingAttributes = [];
                $functions[] = $fn;
                $i = $next;
                continue;
            }

            if ($id === T_VARIABLE) {
                if ($pendingAttributes !== []) {
                    throw new RuntimeException("Attributes can be applied only to functions.");
                }
                [$global, $next] = $this->parseGlobalAssignment($tokens, $i);
                $globals[] = $global;
                $i = $next;
                continue;
            }

            if ($id === T_STRING && strtolower($text) === 'dll_import') {
                if ($pendingAttributes !== []) {
                    throw new RuntimeException("Attributes can be applied only to functions.");
                }
                [$import, $next] = $this->parseImport($tokens, $i);
                $imports[] = $import;
                $i = $next;
                continue;
            }

            if ($text === ';') {
                $i++;
                continue;
            }

            throw new RuntimeException("Unsupported top-level token: {$text}");
        }

        return new Program(true, $globals, $imports, $functions);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<AttributeDecl>
     */
    private function parseAttributes(array $tokens, int &$i): array
    {
        $attrs = [];
        $count = count($tokens);
        while ($i < $count && ($this->tokenId($tokens[$i]) === T_ATTRIBUTE || $this->tokenText($tokens[$i]) === '#[')) {
            $i++;
            if ($this->tokenId($tokens[$i] ?? null) !== T_STRING) {
                throw new RuntimeException("Expected attribute name.");
            }
            $name = strtolower($this->tokenText($tokens[$i]));
            $i++;

            $args = [];
            if ($this->tokenText($tokens[$i] ?? null) === '(') {
                $i++;
                $argTokens = $this->collectUntilToken($tokens, $i, ')', "Unclosed attribute arguments.");
                $args = $this->parseAttributeArgs($argTokens);
            }

            if ($this->tokenText($tokens[$i] ?? null) !== ']') {
                throw new RuntimeException("Expected ']' after attribute.");
            }
            $i++;
            $attrs[] = new AttributeDecl($name, $args);
        }
        return $attrs;
    }

    /**
     * @param array<int, array{int, string, int}|string> $argTokens
     * @return list<mixed>
     */
    private function parseAttributeArgs(array $argTokens): array
    {
        $parts = [];
        $current = [];
        $paren = 0;
        $bracket = 0;

        foreach ($argTokens as $t) {
            $text = $this->tokenText($t);
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren--;
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
            }

            if ($text === ',' && $paren === 0 && $bracket === 0) {
                $parts[] = $current;
                $current = [];
                continue;
            }
            $current[] = $t;
        }
        if ($current !== []) {
            $parts[] = $current;
        }

        $result = [];
        foreach ($parts as $part) {
            $result[] = $this->parseAttributeLiteral($part);
        }
        return $result;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseAttributeLiteral(array $tokens): mixed
    {
        $clean = array_values(array_filter($tokens, fn($t): bool => !$this->isIgnorable($t)));
        if ($clean === []) {
            throw new RuntimeException("Empty attribute argument.");
        }

        if (count($clean) === 1) {
            $id = $this->tokenId($clean[0]);
            $text = $this->tokenText($clean[0]);
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                return $this->decodePhpStringLiteral($text);
            }
            if ($id === T_LNUMBER) {
                return (int)$text;
            }
            if ($id === T_DNUMBER) {
                return (float)$text;
            }
            if ($id === T_STRING && strtolower($text) === 'null') {
                return null;
            }
        }

        if ($this->tokenText($clean[0]) === '[' && $this->tokenText($clean[count($clean) - 1]) === ']') {
            $inner = array_slice($clean, 1, -1);
            $items = [];
            if ($inner !== []) {
                $parts = [];
                $current = [];
                $paren = 0;
                $bracket = 0;
                foreach ($inner as $t) {
                    $text = $this->tokenText($t);
                    if ($text === '(') {
                        $paren++;
                    } elseif ($text === ')') {
                        $paren--;
                    } elseif ($text === '[') {
                        $bracket++;
                    } elseif ($text === ']') {
                        $bracket--;
                    }
                    if ($text === ',' && $paren === 0 && $bracket === 0) {
                        $parts[] = $current;
                        $current = [];
                        continue;
                    }
                    $current[] = $t;
                }
                if ($current !== []) {
                    $parts[] = $current;
                }
                foreach ($parts as $p) {
                    $items[] = $this->parseAttributeLiteral($p);
                }
            }
            return $items;
        }

        throw new RuntimeException("Unsupported attribute argument literal.");
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{FunctionDef, int}
     */
    private function parseFunction(array $tokens, int $start, array $attributes): array
    {
        $i = $start + 1;
        $count = count($tokens);

        if ($this->tokenId($tokens[$i] ?? null) !== T_STRING) {
            throw new RuntimeException("Expected function name.");
        }
        $name = strtolower($this->tokenText($tokens[$i]));
        $genericMap = $this->extractGenericMap($attributes, "function {$name}");
        $i++;

        if ($this->tokenText($tokens[$i] ?? null) !== '(') {
            throw new RuntimeException("Expected '(' after function name {$name}.");
        }
        $i++;

        $params = [];
        if ($this->tokenText($tokens[$i] ?? null) !== ')') {
            while (true) {
                $paramAttrs = [];
                while ($this->tokenId($tokens[$i] ?? null) === T_ATTRIBUTE || $this->tokenText($tokens[$i] ?? null) === '#[') {
                    $paramAttrs = array_merge($paramAttrs, $this->parseAttributes($tokens, $i));
                }

                $paramType = null;
                if ($this->tokenId($tokens[$i] ?? null) === T_STRING) {
                    $paramType = $this->parseDeclaredType($tokens, $i, true);
                }
                if ($this->tokenId($tokens[$i] ?? null) !== T_VARIABLE) {
                    throw new RuntimeException("Expected parameter name in function {$name}.");
                }
                $paramName = substr($this->tokenText($tokens[$i]), 1);
                $i++;

                $attrTypeRaw = $this->extractTypeAttribute($paramAttrs, "parameter \${$paramName} in {$name}");
                $declaredType = $paramType !== null ? $this->resolveGenericTypeString($paramType, $genericMap, false, "parameter \${$paramName} in {$name}") : null;
                $attrType = $attrTypeRaw !== null ? $this->resolveGenericTypeString($attrTypeRaw, $genericMap, false, "parameter \${$paramName} in {$name}") : null;
                if ($declaredType !== null && $attrType !== null && strtolower($declaredType) !== strtolower($attrType)) {
                    throw new RuntimeException("Type mismatch between declared and #[Type] for parameter \${$paramName} in {$name}.");
                }
                $finalType = $declaredType ?? $attrType;
                if ($finalType === null) {
                    throw new RuntimeException("Parameter \${$paramName} in {$name} must have declared type or #[Type('...')].");
                }
                $params[] = ['name' => $paramName, 'type' => $finalType];

                if ($this->tokenText($tokens[$i] ?? null) === ',') {
                    $i++;
                    continue;
                }
                break;
            }
        }

        if ($this->tokenText($tokens[$i] ?? null) !== ')') {
            throw new RuntimeException("Expected ')' in function {$name}.");
        }
        $i++;

        $declaredReturnTypeRaw = null;
        if ($this->tokenText($tokens[$i] ?? null) === ':') {
            $i++;
            $declaredReturnTypeRaw = $this->parseDeclaredType($tokens, $i, true);
        }

        $declaredReturnType = $declaredReturnTypeRaw !== null
            ? $this->resolveGenericTypeString($declaredReturnTypeRaw, $genericMap, true, "function {$name}")
            : null;
        $attrReturnTypeRaw = $this->extractReturnTypeAttribute($attributes, "function {$name}");
        $attrReturnType = $attrReturnTypeRaw !== null
            ? $this->resolveGenericTypeString($attrReturnTypeRaw, $genericMap, true, "function {$name}")
            : null;
        if ($declaredReturnType !== null && $attrReturnType !== null && strtolower($declaredReturnType) !== strtolower($attrReturnType)) {
            throw new RuntimeException("Return type mismatch between declared and #[ReturnType] in function {$name}.");
        }
        $returnType = $declaredReturnType ?? $attrReturnType;
        if ($returnType === null) {
            throw new RuntimeException("Function {$name} must have return type or #[ReturnType('...')].");
        }

        if ($this->tokenText($tokens[$i] ?? null) !== '{') {
            throw new RuntimeException("Expected '{' in function {$name}.");
        }
        $i++;

        $depth = 1;
        $bodyTokens = [];
        while ($i < $count) {
            $text = $this->tokenText($tokens[$i]);
            if ($text === '{') {
                $depth++;
                $bodyTokens[] = $tokens[$i];
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $bodyTokens[] = $tokens[$i];
            } else {
                $bodyTokens[] = $tokens[$i];
            }
            $i++;
        }
        if ($depth !== 0) {
            throw new RuntimeException("Unbalanced braces in function {$name}.");
        }

        $body = $this->parseStatements($bodyTokens, $name);
        return [new FunctionDef($name, $params, $returnType, $attributes, $body), $i + 1];
    }

    /**
     * @param list<AttributeDecl> $attributes
     */
    private function extractTypeAttribute(array $attributes, string $context): ?string
    {
        $matched = null;
        foreach ($attributes as $attr) {
            $name = strtolower($attr->name);
            if ($name !== 'type') {
                throw new RuntimeException("Unsupported parameter attribute #[$attr->name] in {$context}. Only #[Type('...')] is allowed.");
            }
            if (count($attr->args) !== 1 || !is_string($attr->args[0])) {
                throw new RuntimeException("#[Type] in {$context} requires exactly one string argument.");
            }
            $type = strtolower(trim($attr->args[0]));
            if ($matched !== null) {
                throw new RuntimeException("Duplicate #[Type] attribute in {$context}.");
            }
            $matched = $type;
        }
        return $matched;
    }

    /**
     * @param list<AttributeDecl> $attributes
     */
    private function extractReturnTypeAttribute(array $attributes, string $context): ?string
    {
        $matched = null;
        foreach ($attributes as $attr) {
            $name = strtolower($attr->name);
            if ($name !== 'returntype') {
                continue;
            }
            if (count($attr->args) !== 1 || !is_string($attr->args[0])) {
                throw new RuntimeException("#[ReturnType] in {$context} requires exactly one string argument.");
            }
            $type = strtolower(trim($attr->args[0]));
            if ($matched !== null) {
                throw new RuntimeException("Duplicate #[ReturnType] attribute in {$context}.");
            }
            $matched = $type;
        }
        return $matched;
    }

    /**
     * @param list<AttributeDecl> $attributes
     * @return array<string, string>
     */
    private function extractGenericMap(array $attributes, string $context): array
    {
        $map = [];
        foreach ($attributes as $attr) {
            if (strtolower($attr->name) !== 'generic') {
                continue;
            }

            $key = 't';
            $value = null;
            if (count($attr->args) === 1 && is_string($attr->args[0])) {
                $value = strtolower(trim($attr->args[0]));
            } elseif (count($attr->args) === 2 && is_string($attr->args[0]) && is_string($attr->args[1])) {
                $key = strtolower(trim($attr->args[0]));
                $value = strtolower(trim($attr->args[1]));
            } else {
                throw new RuntimeException("#[Generic] in {$context} expects ('type') or ('T','type').");
            }

            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $key)) {
                throw new RuntimeException("Invalid generic name '{$key}' in {$context}.");
            }
            if (!$this->isValidTypeString($value, false)) {
                throw new RuntimeException("Invalid generic type '{$value}' in {$context}.");
            }
            if (isset($map[$key]) && $map[$key] !== $value) {
                throw new RuntimeException("Generic '{$key}' is defined with different types in {$context}.");
            }
            $map[$key] = $value;
        }
        return $map;
    }

    /**
     * @param array<string, string> $genericMap
     */
    private function resolveGenericTypeString(string $type, array $genericMap, bool $allowVoid, string $context): string
    {
        $resolved = strtolower(trim($type));
        foreach ($genericMap as $name => $mappedType) {
            $resolved = preg_replace('/\b' . preg_quote($name, '/') . '\b/i', $mappedType, $resolved) ?? $resolved;
        }
        if (!$this->isValidTypeString($resolved, $allowVoid)) {
            throw new RuntimeException("Unknown type '{$type}' in {$context}.");
        }
        return $resolved;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{GlobalAssign, int}
     */
    private function parseGlobalAssignment(array $tokens, int $start): array
    {
        $name = substr($this->tokenText($tokens[$start]), 1);
        $i = $start + 1;
        if ($this->tokenText($tokens[$i] ?? null) !== '=') {
            throw new RuntimeException("Only assignment is allowed at top-level for \${$name}.");
        }
        $i++;

        $exprTokens = $this->collectUntilSemicolon($tokens, $i, 'global');
        $expr = $this->parseExpressionFromTokens($exprTokens, 'global');
        return [new GlobalAssign($name, $expr), $i];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{ImportDecl, int}
     */
    private function parseImport(array $tokens, int $start): array
    {
        $i = $start;
        $stmtTokens = $this->collectUntilSemicolon($tokens, $i, 'import');
        $expr = $this->parseExpressionFromTokens($stmtTokens, 'import');

        if (!($expr instanceof \PhpAsm\Ast\CallExpr) || strtolower($expr->name) !== 'dll_import') {
            throw new RuntimeException("Invalid import declaration.");
        }

        if (count($expr->args) < 2) {
            throw new RuntimeException("dll_import requires at least 2 args: dll and symbol.");
        }
        if (!($expr->args[0] instanceof \PhpAsm\Ast\StringExpr) || !($expr->args[1] instanceof \PhpAsm\Ast\StringExpr)) {
            throw new RuntimeException("dll_import first args must be string literals.");
        }

        $dll = $expr->args[0]->value;
        $symbol = $expr->args[1]->value;
        $localName = $symbol;
        $convention = 'stdcall';
        $returnType = 'int';
        $argTypes = null;

        if (isset($expr->args[2])) {
            if (!($expr->args[2] instanceof \PhpAsm\Ast\StringExpr)) {
                throw new RuntimeException("dll_import arg #3 (convention) must be string.");
            }
            $convention = strtolower($expr->args[2]->value);
            if (!in_array($convention, ['stdcall', 'cdecl'], true)) {
                throw new RuntimeException("dll_import convention must be 'stdcall' or 'cdecl'.");
            }
        }

        if (isset($expr->args[3])) {
            if (!($expr->args[3] instanceof \PhpAsm\Ast\StringExpr)) {
                throw new RuntimeException("dll_import arg #4 (return type) must be string.");
            }
            $returnType = strtolower($expr->args[3]->value);
            $this->assertKnownType($returnType, true);
        }

        if (isset($expr->args[4])) {
            if (!($expr->args[4] instanceof \PhpAsm\Ast\ArrayLiteralExpr)) {
                throw new RuntimeException("dll_import arg #5 (arg types) must be string array literal.");
            }
            $argTypes = [];
            foreach ($expr->args[4]->elements as $el) {
                if (!($el instanceof \PhpAsm\Ast\StringExpr)) {
                    throw new RuntimeException("dll_import arg types must be strings.");
                }
                $t = strtolower($el->value);
                $this->assertKnownType($t, false);
                $argTypes[] = $t;
            }
        }

        if (isset($expr->args[5])) {
            if (!($expr->args[5] instanceof \PhpAsm\Ast\StringExpr)) {
                throw new RuntimeException("dll_import arg #6 (local name) must be string.");
            }
            $localName = $expr->args[5]->value;
        }

        return [new ImportDecl($dll, $symbol, $localName, $convention, $returnType, $argTypes), $i];
    }

    /**
     * @param array<int, array{int, string, int}|string> $bodyTokens
     * @return list<Stmt>
     */
    private function parseStatements(array $bodyTokens, string $functionName): array
    {
        $tokens = [];
        foreach ($bodyTokens as $token) {
            if ($this->isIgnorable($token)) {
                continue;
            }
            $tokens[] = $token;
        }

        $stmts = [];
        $i = 0;
        $count = count($tokens);
        while ($i < $count) {
            $stmt = $this->parseStatementAt($tokens, $i, $functionName);
            if ($stmt !== null) {
                $stmts[] = $stmt;
            }
        }
        return $stmts;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseStatementAt(array $tokens, int &$i, string $functionName): ?Stmt
    {
        $count = count($tokens);
        if ($i >= $count) {
            return null;
        }

        if ($this->tokenText($tokens[$i]) === ';') {
            $i++;
            return null;
        }

        $id = $this->tokenId($tokens[$i]);
        if ($id === T_IF) {
            return $this->parseIfStatement($tokens, $i, $functionName);
        }
        if ($id === T_WHILE) {
            return $this->parseWhileStatement($tokens, $i, $functionName);
        }
        if ($id === T_FOR) {
            return $this->parseForStatement($tokens, $i, $functionName);
        }
        if ($id === T_RETURN) {
            $i++;
            $exprTokens = $this->collectUntilSemicolon($tokens, $i, $functionName);
            if ($exprTokens === []) {
                return new ReturnStmt(null);
            }
            return new ReturnStmt($this->parseExpressionFromTokens($exprTokens, $functionName));
        }

        $stmtTokens = $this->collectUntilSemicolon($tokens, $i, $functionName);
        return $this->parseSimpleStatementTokens($stmtTokens, $functionName);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseIfStatement(array $tokens, int &$i, string $functionName): Stmt
    {
        $i++;
        $this->expectTokenText($tokens, $i, '(', "Expected '(' after if in {$functionName}.");
        $condTokens = $this->collectUntilMatchingParen($tokens, $i, $functionName);
        $condition = $this->parseExpressionFromTokens($condTokens, $functionName);
        $body = $this->parseBlock($tokens, $i, $functionName);
        return new IfStmt($condition, $body);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseWhileStatement(array $tokens, int &$i, string $functionName): Stmt
    {
        $i++;
        $this->expectTokenText($tokens, $i, '(', "Expected '(' after while in {$functionName}.");
        $condTokens = $this->collectUntilMatchingParen($tokens, $i, $functionName);
        $condition = $this->parseExpressionFromTokens($condTokens, $functionName);
        $body = $this->parseBlock($tokens, $i, $functionName);
        return new WhileStmt($condition, $body);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseForStatement(array $tokens, int &$i, string $functionName): Stmt
    {
        $i++;
        $this->expectTokenText($tokens, $i, '(', "Expected '(' after for in {$functionName}.");

        $initTokens = $this->collectUntilSeparator($tokens, $i, ';', $functionName);
        $condTokens = $this->collectUntilSeparator($tokens, $i, ';', $functionName);
        $postTokens = $this->collectUntilMatchingParen($tokens, $i, $functionName);

        $init = $initTokens === [] ? null : $this->parseSimpleStatementTokens($initTokens, $functionName);
        $condition = $condTokens === [] ? null : $this->parseExpressionFromTokens($condTokens, $functionName);
        $post = $postTokens === [] ? null : $this->parseSimpleStatementTokens($postTokens, $functionName);
        $body = $this->parseBlock($tokens, $i, $functionName);

        return new ForStmt($init, $condition, $post, $body);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<Stmt>
     */
    private function parseBlock(array $tokens, int &$i, string $functionName): array
    {
        $this->expectTokenText($tokens, $i, '{', "Expected '{' in {$functionName}.");
        $start = $i;
        $depth = 1;
        $count = count($tokens);

        while ($i < $count) {
            $text = $this->tokenText($tokens[$i]);
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    $blockTokens = array_slice($tokens, $start, $i - $start);
                    $i++;
                    return $this->parseStatements($blockTokens, $functionName);
                }
            }
            $i++;
        }

        throw new RuntimeException("Unbalanced block braces in {$functionName}.");
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private function collectUntilSemicolon(array $tokens, int &$i, string $context): array
    {
        $out = [];
        $paren = 0;
        $bracket = 0;
        $count = count($tokens);

        while ($i < $count) {
            $text = $this->tokenText($tokens[$i]);
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren--;
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
            }

            if ($text === ';' && $paren === 0 && $bracket === 0) {
                $i++;
                return $out;
            }

            $out[] = $tokens[$i];
            $i++;
        }

        throw new RuntimeException("Missing ';' in {$context}.");
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private function collectUntilSeparator(array $tokens, int &$i, string $separator, string $functionName): array
    {
        $out = [];
        $paren = 0;
        $bracket = 0;
        $count = count($tokens);
        while ($i < $count) {
            $text = $this->tokenText($tokens[$i]);
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren--;
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
            }
            if ($text === $separator && $paren === 0 && $bracket === 0) {
                $i++;
                return $out;
            }
            $out[] = $tokens[$i];
            $i++;
        }
        throw new RuntimeException("Expected '{$separator}' in for header in {$functionName}.");
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private function collectUntilMatchingParen(array $tokens, int &$i, string $functionName): array
    {
        $out = [];
        $depth = 1;
        $count = count($tokens);
        while ($i < $count) {
            $text = $this->tokenText($tokens[$i]);
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    $i++;
                    return $out;
                }
            }
            $out[] = $tokens[$i];
            $i++;
        }
        throw new RuntimeException("Unbalanced parentheses in {$functionName}.");
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseSimpleStatementTokens(array $tokens, string $functionName): Stmt
    {
        if ($tokens === []) {
            throw new RuntimeException("Empty statement in function {$functionName}.");
        }
        if ($this->tokenId($tokens[0]) === T_VARIABLE && $this->tokenText($tokens[1] ?? null) === '=') {
            $name = substr($this->tokenText($tokens[0]), 1);
            $expr = $this->parseExpressionFromTokens(array_slice($tokens, 2), $functionName);
            return new AssignStmt($name, $expr);
        }
        return new ExprStmt($this->parseExpressionFromTokens($tokens, $functionName));
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseExpressionFromTokens(array $tokens, string $functionName): Expr
    {
        $exprParser = new ExprParser($tokens);
        $expr = $exprParser->parseExpression();
        if (!$exprParser->isAtEnd()) {
            throw new RuntimeException("Unexpected token in expression in {$functionName}.");
        }
        return $expr;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseDeclaredType(array &$tokens, int &$i, bool $allowArray): string
    {
        $base = $this->parseTypeCore($tokens, $i, true);
        if ($allowArray && $this->tokenText($tokens[$i] ?? null) === '[' && $this->tokenText($tokens[$i + 1] ?? null) === ']') {
            $i += 2;
            return $base . '[]';
        }
        return $base;
    }

    private function assertKnownType(string $type, bool $allowVoid): void
    {
        if (!$this->isValidTypeString($type, $allowVoid)) {
            throw new RuntimeException("Unknown type: {$type}");
        }
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function parseTypeCore(array &$tokens, int &$i, bool $allowVoid): string
    {
        if ($this->tokenId($tokens[$i] ?? null) !== T_STRING) {
            throw new RuntimeException("Expected type name.");
        }
        $name = strtolower($this->tokenText($tokens[$i]));
        $i++;

        if ($allowVoid && $name === 'void') {
            return 'void';
        }

        if ($name === 'ptr' || $name === 'hptr') {
            if ($this->tokenText($tokens[$i] ?? null) !== '<') {
                throw new RuntimeException("Expected '<' after {$name}.");
            }
            $i++;
            $inner = $this->parseTypeCore($tokens, $i, false);
            $this->consumeGenericClose($tokens, $i, "Expected '>' after {$name}<...>.");
            return $name . '<' . $inner . '>';
        }

        if (!in_array($name, ['int', 'float', 'string'], true)) {
            throw new RuntimeException("Unsupported type: {$name}");
        }
        return $name;
    }

    private function isValidTypeString(string $type, bool $allowVoid): bool
    {
        $type = strtolower(trim($type));
        if ($allowVoid && $type === 'void') {
            return true;
        }
        if (str_ends_with($type, '[]')) {
            $base = substr($type, 0, -2);
            return in_array($base, ['int', 'float', 'string'], true);
        }
        if (preg_match('/^(ptr|hptr)<(.+)>$/', $type, $m) === 1) {
            return $this->isValidTypeString($m[2], false);
        }
        return in_array($type, ['int', 'float', 'string'], true);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private function collectUntilToken(array $tokens, int &$i, string $endToken, string $error): array
    {
        $out = [];
        $paren = 1;
        $bracket = 0;
        $count = count($tokens);
        while ($i < $count) {
            $text = $this->tokenText($tokens[$i]);
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren--;
                if ($paren === 0 && $endToken === ')') {
                    $i++;
                    return $out;
                }
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
            }
            $out[] = $tokens[$i];
            $i++;
        }
        throw new RuntimeException($error);
    }

    private function decodePhpStringLiteral(string $lit): string
    {
        $q = $lit[0] ?? '';
        if ($q !== "'" && $q !== '"') {
            return $lit;
        }
        $inner = substr($lit, 1, -1);
        if ($q === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
        }
        return stripcslashes($inner);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function consumeGenericClose(array &$tokens, int &$i, string $error): void
    {
        $text = $this->tokenText($tokens[$i] ?? null);
        if ($text === '>') {
            $i++;
            return;
        }
        if ($text === '>>') {
            // Consume one '>' and keep one '>' for the outer generic parser.
            $tokens[$i] = '>';
            return;
        }
        throw new RuntimeException($error);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function expectTokenText(array $tokens, int &$i, string $expected, string $error): void
    {
        if ($this->tokenText($tokens[$i] ?? null) !== $expected) {
            throw new RuntimeException($error);
        }
        $i++;
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function isIgnorable(array|string $token): bool
    {
        $id = $this->tokenId($token);
        return in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
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
}
