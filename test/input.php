<?php
declare(strict_types=1);

include './lib.php';

dll_import('kernel32.dll', 'GetTickCount');
dll_import('user32.dll', 'MessageBoxA', 'stdcall', 'int', ['int', 'string', 'string', 'int'], 'MessageBoxA');
dll_import('msvcrt.dll', 'puts', 'cdecl', 'int', ['string'], 'puts');

$gInts = [10, 20, 30, 40];
$gFloats = [1.25, 2.5, 5.0];
$gStrings = ['alpha', 'beta', 'gamma'];
$gScale = 2.5;

function sum_to(int $n): int {
    $i = 0;
    $s = 0;
    while ($i < $n) {
        $s = $s + $i;
        $i = $i + 1;
    }
    return $s;
}

function bump(int $x): int {
    if ($x < 5) {
        $x = $x + 10;
    }
    return $x;
}

#[intrinsic('heap_alloc_t')]
#[ReturnType('hptr<int>')]
function halloc_i(#[Type('int')] $count) { return typed('hptr<int>', 0); }

#[intrinsic('heap_alloc_t')]
#[ReturnType('hptr<float>')]
function halloc_f(#[Type('int')] $count) { return typed('hptr<float>', 0); }

#[intrinsic('heap_alloc_t')]
#[ReturnType('hptr<string>')]
function halloc_s(#[Type('int')] $count) { return typed('hptr<string>', 0); }

#[intrinsic('heap_alloc_t')]
#[ReturnType('hptr<hptr<int>>')]
function halloc_pi(#[Type('int')] $count) { return typed('hptr<hptr<int>>', 0); }

#[intrinsic('heap_free')]
function hfree_i(#[Type('hptr<int>')] $p): int { return 0; }

#[intrinsic('heap_free')]
function hfree_f(#[Type('hptr<float>')] $p): int { return 0; }

#[intrinsic('heap_free')]
function hfree_s(#[Type('hptr<string>')] $p): int { return 0; }

#[intrinsic('heap_free')]
function hfree_pi(#[Type('hptr<hptr<int>>')] $p): int { return 0; }

#[intrinsic('ptr_get')]
function pget_i(#[Type('hptr<int>')] $p, int $idx): int { return 0; }

#[intrinsic('ptr_set')]
function pset_i(#[Type('hptr<int>')] $p, int $idx, int $v): int { return 0; }

#[intrinsic('ptr_get')]
function pget_f(#[Type('hptr<float>')] $p, int $idx): float { return 0.0; }

#[intrinsic('ptr_set')]
function pset_f(#[Type('hptr<float>')] $p, int $idx, float $v): float { return 0.0; }

#[intrinsic('ptr_get')]
function pget_s(#[Type('hptr<string>')] $p, int $idx): string { return ''; }

#[intrinsic('ptr_set')]
function pset_s(#[Type('hptr<string>')] $p, int $idx, string $v): string { return ''; }

#[intrinsic('ptr_get')]
#[ReturnType('hptr<int>')]
function pget_pi(#[Type('hptr<hptr<int>>')] $p, int $idx) { return typed('hptr<int>', 0); }

#[intrinsic('ptr_set')]
#[ReturnType('hptr<int>')]
function pset_pi(#[Type('hptr<hptr<int>>')] $p, int $idx, #[Type('hptr<int>')] $v) { return typed('hptr<int>', 0); }

#[Generic('int')]
#[ReturnType('hptr<T>')]
function ptr_roundtrip(#[Type('hptr<T>')] $p) {
    return $p;
}

function ptr_sum2(#[Type('hptr<int>')] $p): int {
    return pget_i($p, 0) + pget_i($p, 1);
}

#[import('msvcrt.dll', 'puts')]
#[ABI('cdecl')]
function puts2(#[Type('string')] $s): int { return 0; }

function main(): int {
    $gScale;
    $tick = GetTickCount();
    $a = 0;
    $d = [1, 2, 3, 4, 5, 6, 7, 8, 9];
    $sf = scale($gScale);

    if ($tick > 0) {
        printf('tick=%d sf=%f\n', $tick, $sf);
    }

    for ($i = 0; $i < 4; $i = $i + 1) {
        $a = $a + $i;
        printf('%d\n', pick($d, $i));
    }

    $b = sum_to(5);
    $c = bump($a);
    $name = $gStrings[1];
    printf('a=%d b=%d c=%d name=%s g0=%d gf=%f\n', add($a, 2555), $b, $c, $name, $gInts[0], $gFloats[1]);

    $p = halloc_i(4);
    $p2 = ptr_roundtrip($p);
    pset_i($p2, 0, 111);
    pset_i($p2, 1, 222);
    $m0 = pget_i($p2, 0);
    $m1 = pget_i($p2, 1);
    $ms = ptr_sum2($p2);
    printf('heap values: %d %d\n', $m0, $m1);
    printf('heap sum: %d\n', $ms);

    $pf = halloc_f(2);
    pset_f($pf, 0, 1.5);
    pset_f($pf, 1, 2.25);
    $f0 = pget_f($pf, 0);
    $f1 = pget_f($pf, 1);
    printf('heap floats: %f %f\n', $f0, $f1);

    $ps = halloc_s(2);
    pset_s($ps, 0, 'heap-A');
    pset_s($ps, 1, 'heap-B');
    $s0 = pget_s($ps, 0);
    $s1 = pget_s($ps, 1);
    printf('heap strings: %s %s\n', $s0, $s1);

    $ppi = halloc_pi(1);
    pset_pi($ppi, 0, $p2);
    $p3 = pget_pi($ppi, 0);
    printf('nested ptr value: %d\n', pget_i($p3, 1));

    $freed = hfree_i($p2) + hfree_f($pf) + hfree_s($ps) + hfree_pi($ppi);

    MessageBoxA(0, 'phpasm test', 'DLL import call', 0);
    $r = puts2('puts from cdecl import');
    return $r + $c + $freed;
}
