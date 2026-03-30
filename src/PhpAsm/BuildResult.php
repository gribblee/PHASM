<?php
declare(strict_types=1);

namespace PhpAsm;

final class BuildResult
{
    /**
     * @param array<string, string> $moduleAsm
     */
    public function __construct(
        public string $asm,
        public array $moduleAsm
    ) {}
}
