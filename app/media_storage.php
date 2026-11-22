<?php

declare(strict_types=1);
require_once __DIR__ . '/media.php';

function media_storage(): string
{
    $base = rtrim(config('MEDIA_STORAGE_ROOT', dirname(__DIR__) . '/storage'), '/');
    if ($base === '' || $base[0] !== '/' || str_contains($base, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $base)) throw new RuntimeException('Invalid private storage.');
    $path = $base . '/' . media_config()['folder'];
    $current = '';
    foreach (explode('/', ltrim($path, '/')) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) throw new RuntimeException('Invalid private storage.');
    }
    $roots = [realpath(dirname(__DIR__) . '/public'), realpath(dirname(__DIR__) . '/public_html'), realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''))];
    foreach ($roots as $root) if ($root !== false && ($path === $root || str_starts_with($path, $root . '/'))) throw new RuntimeException('Storage must be private.');
    if (!is_dir($path) && !@mkdir($path, 0700, true)) throw new RuntimeException('Storage unavailable.');
    $real = realpath($path);
    if ($real === false || !is_writable($real)) throw new RuntimeException('Storage unavailable.');
    foreach ($roots as $root) if ($root !== false && ($real === $root || str_starts_with($real, $root . '/'))) throw new RuntimeException('Storage must be private.');
    @chmod($real, 0700);
    return $real;
}

function media_file(array $item): ?string
{
    $config = media_config();
    if (!preg_match('/^[a-f0-9]{48}\.' . $config['extension'] . '$/D', $item['filename'])) return null;
    $path = media_storage() . '/' . $item['filename'];
    return !is_link($path) && is_file($path) && realpath($path) === $path ? $path : null;
}

function media_upload_file(): array
{
    $config = media_config();
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name'] ?? null)) throw new InvalidArgumentException('Choose a valid ' . strtoupper($config['extension']) . ' file.');
    $name = $file['name'];
    if (preg_match('~[\\\\/\x00-\x1f\x7f]~', $name) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $config['extension']) throw new InvalidArgumentException('Invalid file extension.');
    $size = filesize($file['tmp_name']);
    $limit = (int) config('MEDIA_MAX_BYTES', (string) $config['limit']);
    if ($size === false || $size < 1 || $limit < 1 || $size > min($limit, 1024 * 1024 * 1024)) throw new InvalidArgumentException('File is empty or too large.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $header = file_get_contents($file['tmp_name'], false, null, 0, 32);
    if ($mime !== $config['mime'] || !is_string($header)) throw new InvalidArgumentException('Invalid media content.');
    if ($config['kind'] === 'audio' && !str_starts_with($header, 'ID3') && !(strlen($header) >= 2 && ord($header[0]) === 255 && (ord($header[1]) & 224) === 224)) throw new InvalidArgumentException('Invalid MP3 content.');
    if ($config['kind'] === 'video' && substr($header, 4, 4) !== 'ftyp') throw new InvalidArgumentException('Invalid MP4 content.');
    return ['source' => $file['tmp_name'], 'size' => $size, 'mime' => $config['mime']];
}

function media_send_file(array $item): never
{
    $path = media_file($item);
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        http_response_code(404);
        exit;
    }
    $stat = fstat($handle);
    $size = (int) ($stat['size'] ?? 0);
    if ($size < 1) {
        fclose($handle);
        http_response_code(404);
        exit;
    }
    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    header('Content-Type: ' . media_config()['mime']);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="' . media_config()['folder'] . '.' . media_config()['extension'] . '"');
    if ($range !== '') {
        if (!is_string($range) || !preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) || ($parts[1] === '' && $parts[2] === '') || strlen($parts[1]) > 18 || strlen($parts[2]) > 18) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        if ($parts[1] === '') {
            $suffix = (int) $parts[2];
            if ($suffix < 1) $start = $size;
            else $start = max(0, $size - $suffix);
        } else {
            $start = (int) $parts[1];
            if ($parts[2] !== '') $end = min($size - 1, (int) $parts[2]);
        }
        if ($start >= $size || $end < $start) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $remaining = $end - $start + 1;
    header('Content-Length: ' . $remaining);
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        fclose($handle);
        exit;
    }
    fseek($handle, $start);
    while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, min(65536, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

function media_vtt(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", ltrim($text, "\xEF\xBB\xBF"));
    if (str_starts_with($text, 'WEBVTT')) return $text;
    if (preg_match('/\d{2}:\d{2}:\d{2},\d{3}\s+-->/', $text)) return "WEBVTT\n\n" . preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
    return "WEBVTT\n\n00:00:00.000 --> 99:00:00.000\n" . strip_tags($text) . "\n";
}
