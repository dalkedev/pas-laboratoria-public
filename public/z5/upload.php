<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) z5_fail(413, 'przekroczono limit serwera.');
z5_operation(static function (string $directory): void {
    $file = $_FILES['fileToUpload'] ?? null;
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('przesyłanie nie powiodło się.');
    $name = z5_name($file['name'] ?? null);
    if (isset($file['full_path'])) z5_name($file['full_path']);
    $target = $directory . '/' . $name;
    if (is_link($target)) throw new DomainException('nazwa jest zajęta.');
    $output = @fopen($target, 'x+b');
    if (!is_resource($output)) throw new DomainException('nazwa jest zajęta.');
    @chmod($target, 0600);
    $input = @fopen($file['tmp_name'], 'rb');
    $complete = false;
    try {
        if (!is_resource($input)) throw new RuntimeException('Upload unavailable.');
        $copied = stream_copy_to_stream($input, $output);
        $expected = filesize($file['tmp_name']);
        if ($copied === false || $expected === false || $copied !== $expected || !fflush($output)) throw new RuntimeException('Upload incomplete.');
        $complete = true;
    } finally {
        if (is_resource($input)) fclose($input);
        fclose($output);
        if (!$complete) @unlink($target);
    }
});
