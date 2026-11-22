<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_operation(static function (string $directory): void {
    $path = $directory . '/' . z5_name($_POST['name'] ?? null);
    if (is_link($path) || !file_exists($path)) throw new OutOfBoundsException('nie znaleziono.');
    if (is_dir($path)) {
        if (!@rmdir($path)) throw new DomainException('katalog nie jest pusty.');
    } elseif (!is_file($path) || !@unlink($path)) throw new DomainException('nie można usunąć pliku.');
});
