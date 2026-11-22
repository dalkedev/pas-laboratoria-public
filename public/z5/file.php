<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_login();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
$lock = null;
$stream = null;
try {
    $folder = z5_folder($_GET['dir'] ?? '');
    $name = z5_name($_GET['name'] ?? null);
    $lock = z5_lock_home(null, false);
    $path = z5_directory($lock, $folder) . '/' . $name;
    if (is_link($path) || !is_file($path)) throw new OutOfBoundsException('nie znaleziono pliku.');
    $before = lstat($path);
    $stream = @fopen($path, 'rb');
    $stat = is_resource($stream) ? fstat($stream) : false;
    if ($before === false || $stat === false || $before['ino'] !== $stat['ino'] || $before['dev'] !== $stat['dev']) throw new OutOfBoundsException('nie znaleziono pliku.');
    $size = (int) $stat['size'];
    $start = 0;
    $end = max(0, $size - 1);
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '') {
        if (!is_string($range) || !preg_match('/^bytes=([0-9]{0,18})-([0-9]{0,18})$/iD', trim($range), $parts) || ($parts[1] === '' && $parts[2] === '') || $size === 0) {
            http_response_code(416); header('Content-Range: bytes */' . $size); exit;
        }
        if ($parts[1] === '') {
            $suffix = (int) $parts[2];
            if ($suffix < 1) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
            $start = max(0, $size - $suffix);
        } else {
            $start = (int) $parts[1];
            if ($parts[2] !== '') $end = min($end, (int) $parts[2]);
        }
        if ($start >= $size || $end < $start) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $mime = z5_mime($path);
    $inline = ($_GET['inline'] ?? '') === '1' && z5_media_type($mime) !== '';
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="download"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($size === 0 ? 0 : $end - $start + 1));
    ini_set('zlib.output_compression', '0');
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && $size > 0) {
        fseek($stream, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($stream)) {
            $chunk = fread($stream, min(65536, $left));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $left -= strlen($chunk);
            if (connection_aborted()) break;
        }
    }
} catch (InvalidArgumentException $error) {
    z5_fail(422, $error->getMessage());
} catch (OutOfBoundsException $error) {
    z5_fail(404, $error->getMessage());
} finally {
    if (is_resource($stream)) fclose($stream);
    if ($lock !== null) z5_unlock($lock);
}
