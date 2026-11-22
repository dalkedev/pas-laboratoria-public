<?php

declare(strict_types=1);
require_once __DIR__ . '/z5.php';

function z5_name(mixed $value): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 255 || $value === '.' || $value === '..'
        || preg_match('//u', $value) !== 1 || preg_match('~[\\\\/\x00-\x1f\x7f]~', $value)) {
        throw new InvalidArgumentException('nieprawidłowa nazwa.');
    }
    return $value;
}

function z5_folder(mixed $value): string
{
    return $value === '' ? '' : z5_name($value);
}

function z5_cloud_user(): array
{
    $stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $user = $stmt->fetch();
    if ($user === false) {
        $_SESSION = [];
        redirect('/z5/logowanie.php');
    }
    return ['id' => (int) $user['id'], 'username' => (string) $user['username']];
}

function z5_private_root(): string
{
    $path = rtrim(config('CLOUD_STORAGE_ROOT', dirname(__DIR__) . '/storage/mycloud'), '/');
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
        throw new RuntimeException('Invalid storage configuration.');
    }
    $current = '';
    foreach (explode('/', ltrim($path, '/')) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) {
            throw new RuntimeException('Invalid storage directory.');
        }
    }
    $publicRoots = [realpath(dirname(__DIR__) . '/public'), realpath(dirname(__DIR__) . '/public_html')];
    $configuredDocumentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($configuredDocumentRoot !== '') $publicRoots[] = realpath($configuredDocumentRoot);
    foreach ($publicRoots as $documentRoot) {
        if ($documentRoot !== false && ($path === $documentRoot || str_starts_with($path, $documentRoot . '/'))) throw new RuntimeException('Storage must be private.');
    }
    if (!is_dir($path) && !@mkdir($path, 0700, true)) {
        throw new RuntimeException('Storage unavailable.');
    }
    $root = realpath($path);
    if ($root === false || !is_writable($root)) throw new RuntimeException('Storage unavailable.');
    foreach ($publicRoots as $documentRoot) {
        if ($documentRoot !== false && ($root === $documentRoot || str_starts_with($root, $documentRoot . '/'))) throw new RuntimeException('Storage must be private.');
    }
    @chmod($root, 0700);
    return $root;
}

function z5_lock_home(?array $user = null, bool $exclusive = true): array
{
    $user ??= z5_cloud_user();
    $name = (string) $user['username'];
    if (!preg_match('/^[A-Za-z0-9_.-]{3,128}$/D', $name) || $name === '.' || $name === '..') {
        throw new RuntimeException('Invalid account directory.');
    }
    $root = z5_private_root();
    $metadata = dirname($root) . '/.mycloud-meta-' . substr(hash('sha256', $root), 0, 16);
    if (is_link($metadata) || (!is_dir($metadata) && !@mkdir($metadata, 0700))) {
        throw new RuntimeException('Storage unavailable.');
    }
    @chmod($metadata, 0700);
    $lockPath = $metadata . '/' . hash('sha256', $name) . '.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Invalid storage lock.');
    }
    $handle = @fopen($lockPath, 'c+b');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Storage unavailable.');
    }
    @chmod($lockPath, 0600);
    $home = $root . '/' . $name;
    $created = false;
    try {
        rewind($handle);
        $owner = trim((string) stream_get_contents($handle, 128));
        if ($owner !== '' && $owner !== (string) $user['id']) {
            throw new RuntimeException('Account directory belongs to another account.');
        }
        if (is_link($home) || (file_exists($home) && !is_dir($home)) || ($owner === '' && is_dir($home))) {
            throw new RuntimeException('Invalid account directory.');
        }
        if (!is_dir($home)) {
            if (!@mkdir($home, 0700)) throw new RuntimeException('Storage unavailable.');
            $created = true;
        }
        if (realpath($home) !== $home) throw new RuntimeException('Invalid account directory.');
        if ($owner === '') {
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, (string) $user['id']) === false || !fflush($handle)) throw new RuntimeException('Storage unavailable.');
        }
        if (!$exclusive) flock($handle, LOCK_SH);
        return ['handle' => $handle, 'home' => $home, 'created' => $created];
    } catch (Throwable $error) {
        if ($created) @rmdir($home);
        flock($handle, LOCK_UN);
        fclose($handle);
        throw $error;
    }
}

function z5_unlock(array $lock, bool $rollback = false): void
{
    if ($rollback && $lock['created'] && @rmdir($lock['home'])) {
        rewind($lock['handle']);
        ftruncate($lock['handle'], 0);
    }
    flock($lock['handle'], LOCK_UN);
    fclose($lock['handle']);
}

function z5_directory(array $lock, string $folder): string
{
    $path = $lock['home'] . ($folder === '' ? '' : '/' . z5_name($folder));
    if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
        throw new OutOfBoundsException('nie znaleziono katalogu.');
    }
    return $path;
}

function z5_mime(string $path): string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return is_string($mime) ? $mime : 'application/octet-stream';
}

function z5_media_type(string $mime): string
{
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)) return 'image';
    if (in_array($mime, ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/flac', 'audio/mp4'], true)) return 'audio';
    if (in_array($mime, ['video/mp4', 'video/webm', 'video/ogg'], true)) return 'video';
    return '';
}

function z5_file_url(string $folder, string $name, bool $inline = false): string
{
    return '/z5/file.php?' . http_build_query(['dir' => $folder, 'name' => $name, $inline ? 'inline' : 'download' => '1'], '', '&', PHP_QUERY_RFC3986);
}

function z5_fail(int $status, string $message): never
{
    http_response_code($status);
    z5_page_start($message, true);
    echo '<a class="btn btn-outline-secondary" href="/z5/">powrót</a>';
    z5_page_end();
    exit;
}

function z5_operation(callable $operation): never
{
    z5_require_login();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        z5_fail(405, 'wymagane post.');
    }
    z5_check_csrf();
    $lock = null;
    try {
        $folder = z5_folder($_POST['dir'] ?? '');
        $lock = z5_lock_home();
        $directory = z5_directory($lock, $folder);
        $operation($directory, $folder);
        z5_unlock($lock);
        redirect('/z5/?' . http_build_query(['dir' => $folder], '', '&', PHP_QUERY_RFC3986));
    } catch (InvalidArgumentException $error) {
        if ($lock !== null) z5_unlock($lock);
        z5_fail(422, $error->getMessage());
    } catch (OutOfBoundsException $error) {
        if ($lock !== null) z5_unlock($lock);
        z5_fail(404, $error->getMessage());
    } catch (DomainException $error) {
        if ($lock !== null) z5_unlock($lock);
        z5_fail(409, $error->getMessage());
    } catch (Throwable $error) {
        if ($lock !== null) z5_unlock($lock);
        throw $error;
    }
}

function z5_log_login(string $username, bool $success): void
{
    $stmt = db()->prepare("INSERT INTO goscieportalu (ipaddress, user_agent, browser, application, event_type, login_name, login_success) VALUES (?, ?, ?, 'z5', 'login', ?, ?)");
    $stmt->execute([z5_client_ip(), z5_clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 512), 'nieustalona', z5_clip($username, 128), (int) $success]);
}
