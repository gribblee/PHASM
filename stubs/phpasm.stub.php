<?php
declare(strict_types=1);

/**
 * IDE stubs for phpasm DSL.
 *
 * This file is for static analysis and autocomplete only.
 * Do not include it in runtime execution.
 */

/**
 * @template T
 */
final class hptr {}

/**
 * @template T
 */
final class ptr {}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_PARAMETER)]
final class Type
{
    public function __construct(public string $type) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
final class ReturnType
{
    public function __construct(public string $type) {}
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Generic
{
    public function __construct(
        public string $nameOrType,
        public ?string $type = null
    ) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
final class ABI
{
    public function __construct(public string $convention) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
final class intrinsic
{
    public function __construct(public string $op) {}
}

#[Attribute(Attribute::TARGET_FUNCTION)]
final class import
{
    /**
     * @param list<string>|null $argTypes
     */
    public function __construct(
        public string $dll,
        public string $symbol,
        public string $convention = 'stdcall',
        public string $returnType = 'int',
        public ?array $argTypes = null,
        public ?string $localName = null
    ) {}
}

/**
 * Registers imported DLL symbol for codegen.
 *
 * @param 'stdcall'|'cdecl' $convention
 * @param list<string>|null $argTypes
 */
function dll_import(
    string $dll,
    string $symbol,
    string $convention = 'stdcall',
    string $returnType = 'int',
    ?array $argTypes = null,
    ?string $localName = null
): void {}

/**
 * Typed annotation helper for scalar/pointer reinterpret in phpasm.
 *
 * @template T
 * @param string $type
 * @param mixed $expr
 * @return T
 */
function typed(string $type, mixed $expr): mixed
{
    return $expr;
}

/**
 * @return hptr<int>
 */
function heap_alloc(int $bytes): hptr
{
    return new hptr();
}

/**
 * @template T
 * @param int $count
 * @return hptr<T>
 */
function heap_alloc_t(int $count): hptr
{
    return new hptr();
}

/**
 * @template T
 * @param hptr<T> $p
 */
function heap_free(hptr $p): int
{
    return 0;
}

/**
 * @template T
 * @param hptr<T> $p
 * @return T
 */
function ptr_get(hptr $p, int $idx): mixed
{
    return null;
}

/**
 * @template T
 * @param hptr<T> $p
 * @param T $v
 * @return T
 */
function ptr_set(hptr $p, int $idx, mixed $v): mixed
{
    return $v;
}
