<?php
declare(strict_types=1);

$base = __DIR__ . '/PhpAsm';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    if (strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $files[] = $file->getPathname();
}
sort($files);
foreach ($files as $file) {
    require_once $file;
}
