<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_operation(static function (string $directory, string $folder): void {
    if ($folder !== '') throw new InvalidArgumentException('podkatalogi tylko w katalogu głównym.');
    $name = z5_name($_POST['name'] ?? null);
    $path = $directory . '/' . $name;
    if (file_exists($path) || is_link($path) || !@mkdir($path, 0700)) throw new DomainException('nazwa jest zajęta.');
});
