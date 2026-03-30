<?php
declare(strict_types=1);

namespace PhpAsm;

use PhpAsm\Codegen\FasmGenerator;
use PhpAsm\IR\IRGenerator;
use PhpAsm\Link\ModuleLinker;
use PhpAsm\Lexer\Tokenizer;
use PhpAsm\Parser\Parser;
use PhpAsm\Source\IncludeResolver;
use PhpAsm\Source\ModuleResolver;

final class Compiler
{
    public function compile(string $source, string $baseDir): string
    {
        $resolver = new IncludeResolver();
        $resolved = "<?php\n" . $resolver->resolve($source, $baseDir);

        $tokenizer = new Tokenizer();
        $tokens = $tokenizer->meaningful($tokenizer->tokenize($resolved));

        $parser = new Parser();
        $ast = $parser->parse($tokens, $resolved);

        $irGen = new IRGenerator();
        $ir = $irGen->generate($ast);

        $codegen = new FasmGenerator();
        return $codegen->generate($ir);
    }

    public function compileProject(string $entryPath): BuildResult
    {
        $resolver = new ModuleResolver();
        $modules = $resolver->resolve($entryPath);

        $tokenizer = new Tokenizer();
        $parser = new Parser();

        $parsed = [];
        foreach ($modules as $module) {
            $tokens = $tokenizer->meaningful($tokenizer->tokenize($module->source));
            $parsed[] = [
                'path' => $module->path,
                'program' => $parser->parse($tokens, $module->source),
            ];
        }

        $linker = new ModuleLinker();
        $linked = $linker->link($parsed);

        $irGen = new IRGenerator();
        $ir = $irGen->generate($linked->program);

        $codegen = new FasmGenerator();
        $asm = $codegen->generate($ir);

        return new BuildResult($asm, $this->extractModuleAsm($asm, $linked->functionModules));
    }

    /**
     * @param array<string, string> $functionModules
     * @return array<string, string>
     */
    private function extractModuleAsm(string $asm, array $functionModules): array
    {
        $lines = preg_split("/\\r\\n|\\n|\\r/", $asm) ?: [];
        $fnBlocks = [];
        $currentFn = null;

        foreach ($lines as $line) {
            if (preg_match('/^fn_([a-zA-Z0-9_]+):$/', trim($line), $m) === 1) {
                $currentFn = strtolower($m[1]);
                $fnBlocks[$currentFn] = [$line];
                continue;
            }
            if ($currentFn !== null) {
                $fnBlocks[$currentFn][] = $line;
                if (trim($line) === '') {
                    $currentFn = null;
                }
            }
        }

        $moduleToFns = [];
        foreach ($functionModules as $fn => $modulePath) {
            $moduleToFns[$modulePath] ??= [];
            $moduleToFns[$modulePath][] = $fn;
        }

        $out = [];
        foreach ($moduleToFns as $modulePath => $fns) {
            $chunk = [];
            $chunk[] = '; phpasm module artifact';
            $chunk[] = '; module: ' . $modulePath;
            $chunk[] = '; note: linker output subset (not standalone object)';
            $chunk[] = '';
            foreach ($fns as $fn) {
                if (!isset($fnBlocks[$fn])) {
                    continue;
                }
                foreach ($fnBlocks[$fn] as $line) {
                    $chunk[] = $line;
                }
            }
            $out[$modulePath] = implode("\n", $chunk);
        }

        return $out;
    }
}
