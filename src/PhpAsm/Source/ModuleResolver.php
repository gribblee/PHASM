<?php
declare(strict_types=1);

namespace PhpAsm\Source;

use RuntimeException;

final class ModuleUnit
{
    public function __construct(
        public string $path,
        public string $source
    ) {}
}

final class ModuleResolver
{
    /**
     * @var array<string, bool>
     */
    private array $seen = [];

    /**
     * @return list<ModuleUnit>
     */
    public function resolve(string $entryPath): array
    {
        $entryReal = realpath($entryPath);
        if ($entryReal === false) {
            throw new RuntimeException("Entry file not found: {$entryPath}");
        }
        return $this->loadModule($entryReal);
    }

    /**
     * @return list<ModuleUnit>
     */
    private function loadModule(string $path): array
    {
        $real = realpath($path) ?: $path;
        if (isset($this->seen[$real])) {
            return [];
        }
        $this->seen[$real] = true;

        $source = file_get_contents($real);
        if ($source === false) {
            throw new RuntimeException("Failed to read module: {$real}");
        }
        $source = $this->stripBom($source);

        [$stripped, $includes] = $this->stripIncludesAndCollect($source, dirname($real));
        $out = [new ModuleUnit($real, $stripped)];
        foreach ($includes as $inc) {
            foreach ($this->loadModule($inc) as $nested) {
                $out[] = $nested;
            }
        }
        return $out;
    }

    /**
     * @return array{string, list<string>}
     */
    private function stripIncludesAndCollect(string $source, string $baseDir): array
    {
        $tokens = token_get_all($source);
        $out = '';
        $includes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $this->tokenId($token);

            if (!in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $out .= $this->tokenText($token);
                continue;
            }

            $j = $i + 1;
            while ($j < $count && $this->isIgnorable($tokens[$j])) {
                $j++;
            }

            $hasParen = false;
            if ($this->tokenText($tokens[$j] ?? null) === '(') {
                $hasParen = true;
                $j++;
                while ($j < $count && $this->isIgnorable($tokens[$j])) {
                    $j++;
                }
            }

            if ($this->tokenId($tokens[$j] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                throw new RuntimeException("include/require path must be a string literal.");
            }
            $relPath = $this->decodeString($this->tokenText($tokens[$j]));
            $j++;

            while ($j < $count && $this->isIgnorable($tokens[$j])) {
                $j++;
            }

            if ($hasParen) {
                if ($this->tokenText($tokens[$j] ?? null) !== ')') {
                    throw new RuntimeException("Expected ')' after include path.");
                }
                $j++;
                while ($j < $count && $this->isIgnorable($tokens[$j])) {
                    $j++;
                }
            }

            if ($this->tokenText($tokens[$j] ?? null) !== ';') {
                throw new RuntimeException("Expected ';' after include/require.");
            }

            $includePath = $this->normalizePath($baseDir, $relPath);
            if (!is_file($includePath)) {
                throw new RuntimeException("Included file not found: {$includePath}");
            }
            $includes[] = realpath($includePath) ?: $includePath;

            $i = $j;
        }

        return [$out, $includes];
    }

    private function stripBom(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        return $text;
    }

    private function normalizePath(string $baseDir, string $relative): string
    {
        if (preg_match('/^[a-zA-Z]:\\\\/', $relative) === 1 || str_starts_with($relative, '\\\\')) {
            return $relative;
        }
        return rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . $relative;
    }

    private function decodeString(string $lit): string
    {
        $q = $lit[0] ?? '';
        $inner = substr($lit, 1, -1);
        if ($q === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
        }
        return stripcslashes($inner);
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
     * @param array{int, string, int}|string $token
     */
    private function isIgnorable(array|string $token): bool
    {
        $id = $this->tokenId($token);
        return in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
