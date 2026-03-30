<?php
declare(strict_types=1);

namespace PhpAsm\Source;

use RuntimeException;

final class IncludeResolver
{
    /**
     * @param array<string, bool> $seenPaths
     */
    public function resolve(string $source, string $baseDir, array $seenPaths = []): string
    {
        $source = $this->stripBom($source);
        $tokens = token_get_all($source);
        $out = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $this->tokenId($token);

            if ($id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
                continue;
            }

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

            $real = realpath($includePath) ?: $includePath;
            if (!isset($seenPaths[$real])) {
                $seenPaths[$real] = true;
                $data = file_get_contents($includePath);
                if ($data === false) {
                    throw new RuntimeException("Failed to read included file: {$includePath}");
                }
                $out .= "\n" . $this->resolve($data, dirname($includePath), $seenPaths) . "\n";
            }

            $i = $j;
        }

        return $out;
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

    private function stripBom(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        return $text;
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

