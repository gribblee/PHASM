<?php
declare(strict_types=1);

namespace PhpAsm\Lexer;

final class Tokenizer
{
    /**
     * @return array<int, array{int, string, int}|string>
     */
    public function tokenize(string $source): array
    {
        return token_get_all($source);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array<int, array{int, string, int}|string>
     */
    public function meaningful(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $id = $this->tokenId($token);
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $out[] = $token;
        }
        return $out;
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

