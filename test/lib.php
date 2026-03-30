<?php
declare(strict_types=1);

function add(int $a, int $b): int {
    return $a + $b;
}

function scale(float $x): float {
    return $x;
}

function pick(int[] $arr, int $idx): int {
    return $arr[$idx];
}
