<?php

declare(strict_types=1);
require_once __DIR__ . '/z4.php';

function z4_is_admin(): bool
{
    start_app_session();
    $ids = array_filter(array_map('trim', explode(',', config('Z4_ADMIN_USER_IDS'))), 'ctype_digit');
    return in_array((string) ($_SESSION['user_id'] ?? ''), $ids, true)
        || (is_local() && ($_SESSION['username'] ?? '') === 'admin');
}

function z4_require_admin(): void
{
    z4_require_login();
    if (!z4_is_admin()) {
        http_response_code(403);
        z4_page_start('brak dostępu', true);
        z4_page_end();
        exit;
    }
}

function z4_owner_clause(string $prefix = ''): array
{
    return z4_is_admin() ? ['1=1', []] : [$prefix . 'user_id = ?', [(int) $_SESSION['user_id']]];
}

function z4_domains(): array
{
    [$where, $parameters] = z4_owner_clause('d.');
    $stmt = db()->prepare('SELECT d.*, u.username, TIMESTAMPDIFF(SECOND,d.failure,NOW()) AS outage_seconds FROM domeny d JOIN users u ON u.id=d.user_id WHERE ' . $where . ' ORDER BY d.id LIMIT 100');
    $stmt->execute($parameters);
    return $stmt->fetchAll();
}

function z4_domain(int $id): ?array
{
    [$where, $parameters] = z4_owner_clause();
    $stmt = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND,failure,NOW()) AS outage_seconds FROM domeny WHERE id = ? AND ' . $where);
    $stmt->execute(array_merge([$id], $parameters));
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function z4_host_port(): array
{
    $host = strtolower(rtrim(trim(post_string('host')), '.'));
    $port = post_string('port');
    if ($host === '' || strlen($host) > 253 || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host))) {
        throw new InvalidArgumentException('nieprawidłowy host.');
    }
    if (!ctype_digit($port) || strlen($port) > 5 || (int) $port < 1 || (int) $port > 65535) {
        throw new InvalidArgumentException('nieprawidłowy port.');
    }
    return [$host, (int) $port];
}

function z4_host_form(string $action, string $heading, array $row = [], string $error = '', bool $staff = false): void
{
    z4_page_start($heading, true);
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    echo '<form method="post" action="' . e($action) . '" class="col-md-6 col-lg-4 mb-3 z4-host-form">' . csrf_field();
    if (isset($row['id'])) {
        echo '<input type="hidden" name="id" value="' . e((string) $row['id']) . '">';
    }
    echo '<div class="mb-3"><label class="form-label" for="host">host:</label><input class="form-control" id="host" name="host" maxlength="253" value="' . e((string) ($row['host'] ?? '')) . '" required></div><div class="mb-3"><label class="form-label" for="port">port:</label><input class="form-control" type="number" id="port" name="port" min="1" max="65535" value="' . e((string) ($row['port'] ?? '80')) . '" required></div>';
    if ($staff) {
        $workers = db()->query('SELECT id,username FROM users WHERE worker=1 ORDER BY username')->fetchAll();
        echo '<div class="mb-3"><label class="form-label" for="user_id">pracownik:</label><select class="form-select" id="user_id" name="user_id" required>';
        foreach ($workers as $worker) {
            echo '<option value="' . e((string) $worker['id']) . '">' . e($worker['username']) . '</option>';
        }
        echo '</select></div>';
    }
    echo '<button class="btn btn-primary" type="submit">' . ($heading === 'usuń host' ? 'usuń' : 'zapisz') . '</button></form><p><a class="btn btn-outline-secondary" href="/z4/index1.php">powrót</a></p>';
    z4_page_end();
}

function z4_allowed_target(string $host, int $port, ?string &$target = null): ?string
{
    $target = null;
    $ports = array_map('intval', explode(',', config('Z4_ALLOWED_PORTS', '80,443,65534')));
    if (!in_array($port, $ports, true)) {
        return 'niedozwolony port';
    }
    $hosts = array_map(static fn (string $value): string => strtolower(rtrim(trim($value), '.')), explode(',', config('Z4_ALLOWED_HOSTS', 'dalke-124382.hox.pl,localhost,127.0.0.1')));
    $trusted = in_array($host, $hosts, true);
    if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return 'niedozwolony host';
    }
    $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($host === 'localhost' ? ['127.0.0.1'] : @gethostbynamel($host));
    if (!$addresses) {
        throw new RuntimeException('nie można rozwiązać nazwy hosta (DNS).');
    }
    foreach ($addresses as $address) {
        $packed = inet_pton($address);
        $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && $packed !== false
            && (strlen($packed) === 4 ? ord($packed[0]) < 224 : (ord($packed[0]) & 0xe0) === 0x20);
        if (!$trusted && !$public) {
            return 'niedozwolony host';
        }
    }
    $target = $addresses[0];
    return null;
}

function z4_probe_one(int $id): ?array
{
    $pdo = db();
    [$where, $parameters] = z4_owner_clause();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT *, TIMESTAMPDIFF(SECOND,checked_at,NOW()) AS age FROM domeny WHERE id = ? AND ' . $where . ' FOR UPDATE');
        $stmt->execute(array_merge([$id], $parameters));
        $row = $stmt->fetch();
        if ($row === false) {
            $pdo->rollBack();
            return null;
        }
        if ($row['age'] !== null && (int) $row['age'] < 10) {
            $pdo->commit();
            return z4_domain($id);
        }
        $stmt = $pdo->prepare('UPDATE domeny SET checked_at=NOW() WHERE id=?');
        $stmt->execute([$id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $error = '';
    $target = null;
    try {
        $blocked = z4_allowed_target($row['host'], (int) $row['port'], $target);
    } catch (RuntimeException $exception) {
        $blocked = 'awaria';
        $error = $exception->getMessage();
    }
    $state = $blocked ?? 'awaria';
    if ($blocked === null) {
        if (!function_exists('fsockopen')) {
            $state = 'niedostępne';
        } else {
            $errno = 0;
            $message = '';
            $target = str_contains($target, ':') ? '[' . $target . ']' : $target;
            $connection = @fsockopen($target, (int) $row['port'], $errno, $message, 0.6);
            if (is_resource($connection)) {
                fclose($connection);
                $state = 'ok';
            } else {
                $error = z4_clip((string) $errno . ' ' . $message, 200);
            }
        }
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT host,port FROM domeny WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if ($current === false || $current['host'] !== $row['host'] || (int) $current['port'] !== (int) $row['port']) {
            $pdo->rollBack();
            return null;
        }
        if ($state === 'ok') {
            $stmt = $pdo->prepare('UPDATE domeny SET status=?,last_error=?,errors=0,failure=NULL WHERE id=?');
        } elseif ($state === 'awaria') {
            $stmt = $pdo->prepare('UPDATE domeny SET status=?,last_error=?,errors=LEAST(errors+1,4294967295),total_errors=LEAST(total_errors+1,4294967295),failure=COALESCE(failure,NOW()) WHERE id=?');
        } else {
            $stmt = $pdo->prepare('UPDATE domeny SET status=?,last_error=? WHERE id=?');
        }
        $stmt->execute([$state, $error, $id]);
        $stmt = $pdo->prepare('INSERT INTO z4_probe_history (domena_id,state,error) VALUES (?,?,?)');
        $stmt->execute([$id, $state, $error]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    return z4_domain($id);
}

function z4_duration(int $seconds): string
{
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
