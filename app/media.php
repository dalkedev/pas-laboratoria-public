<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

function media_config(): array
{
    if (MEDIA_TASK === 'z6a') {
        return ['task' => 'z6a', 'brand' => 'MySpotify', 'kind' => 'audio', 'table' => 'song', 'id' => 'ids', 'creator' => 'musician', 'text' => 'lyrics', 'types' => 'musictype', 'type_id' => 'idmt', 'folder' => 'songs', 'extension' => 'mp3', 'mime' => 'audio/mpeg', 'limit' => 50 * 1024 * 1024];
    }
    return ['task' => 'z6b', 'brand' => 'MyNetflix', 'kind' => 'video', 'table' => 'film', 'id' => 'idf', 'creator' => 'director', 'text' => 'subtitles', 'types' => 'filmtype', 'type_id' => 'idft', 'folder' => 'films', 'extension' => 'mp4', 'mime' => 'video/mp4', 'limit' => 200 * 1024 * 1024];
}

function media_url(string $file = '', array $parameters = []): string
{
    return '/' . MEDIA_TASK . '/' . $file . ($parameters === [] ? '' : '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));
}

function media_clip(string $value, int $max): string
{
    if (preg_match('//u', $value) !== 1) return '';
    $value = substr($value, 0, $max);
    while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
    return $value;
}

function media_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (is_local() && config('APP_TRUST_PROXY') === '1' && filter_var($_SERVER['HTTP_X_REAL_IP'] ?? '', FILTER_VALIDATE_IP)) $ip = (string) $_SERVER['HTTP_X_REAL_IP'];
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function media_visit(): array
{
    static $visit = null;
    if ($visit !== null) return $visit;
    start_app_session();
    $stmt = db()->prepare('INSERT INTO goscieportalu (ipaddress,user_agent,browser) VALUES (?,?,?)');
    $stmt->execute([media_client_ip(), media_clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 512), 'Unknown']);
    $id = db()->lastInsertId();
    $nonce = bin2hex(random_bytes(32));
    $pending = $_SESSION['media_visits'] ?? [];
    if (!is_array($pending)) $pending = [];
    foreach ($pending as $key => $entry) if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < time()) unset($pending[$key]);
    $pending[$id] = ['nonce' => $nonce, 'expires' => time() + 1800];
    $_SESSION['media_visits'] = array_slice($pending, -20, null, true);
    return $visit = ['id' => $id, 'nonce' => $nonce];
}

function media_page(string $title, bool $authenticated = true): void
{
    $config = media_config();
    $visit = media_visit();
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="' . e(csrf_token()) . '"><meta name="media-visit-id" content="' . e($visit['id']) . '"><meta name="media-visit-nonce" content="' . e($visit['nonce']) . '"><meta name="media-visit-endpoint" content="' . e(media_url('visit.php')) . '"><title>' . e($config['brand']) . '</title><link rel="stylesheet" href="/z2/vendor/bootstrap.min.css"><link rel="stylesheet" href="/media-library/library.css"><script src="/z2/vendor/bootstrap.bundle.min.js" defer></script><script src="/z3/vendor/ua-parser.min.js" defer></script><script src="/media-library/player.js" defer></script></head><body class="media-library" data-library-variant="' . $config['kind'] . '">';
    echo '<header class="library-header"><nav class="navbar navbar-expand-md container library-nav"><a class="navbar-brand" href="' . e(media_url()) . '">' . e($config['brand']) . '</a>';
    if ($authenticated) {
        echo '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#library-nav" aria-controls="library-nav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="library-nav"><div class="navbar-nav me-auto"><a class="nav-link" href="' . e(media_url()) . '">Library</a><a class="nav-link" href="' . e(media_url('select.php')) . '">Upload</a><a class="nav-link" href="' . e(media_url('playlist.php')) . '">New playlist</a></div><span class="navbar-text me-3">' . e((string) ($_SESSION['username'] ?? '')) . '</span><form method="post" action="' . e(media_url('logout.php')) . '">' . csrf_field() . '<button class="btn btn-outline-secondary" type="submit">Sign out</button></form></div>';
    }
    if (!$authenticated) echo '<a class="nav-link ms-auto" href="/">All tasks</a>';
    echo '</nav></header><main class="container py-4 library-main"' . ($authenticated ? ' data-authenticated="true"' : '') . '><h1 class="h3 mb-4">' . e($title) . '</h1>';
}

function media_end(): void
{
    echo '</main></body></html>';
}

function media_fail(int $status, string $message, bool $authenticated = true): never
{
    http_response_code($status);
    media_page($message, $authenticated);
    echo '<a class="btn btn-outline-secondary" href="' . e(media_url()) . '">Back</a>';
    media_end();
    exit;
}

function media_require_https(): void
{
    if (!is_local() && !is_https()) media_fail(403, 'HTTPS required', false);
}

function media_require_login(): void
{
    media_require_https();
    start_app_session();
    if (($_SESSION['loggedin'] ?? false) !== true || !isset($_SESSION['user_id'], $_SESSION['username'])) redirect(media_url('login.php'));
    $stmt = db()->prepare('SELECT username FROM users WHERE id=?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    if ($stmt->fetchColumn() !== $_SESSION['username']) {
        $_SESSION = [];
        redirect(media_url('login.php'));
    }
}

function media_csrf(): void
{
    start_app_session();
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !is_string($_SESSION['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $token)) media_fail(403, 'Invalid form', ($_SESSION['loggedin'] ?? false) === true);
}

function media_post(): void
{
    media_require_login();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        media_fail(405, 'POST required');
    }
    media_csrf();
}

function media_throttle(string $scope): void
{
    $stmt = db()->prepare('SELECT blocked_until FROM auth_attempts WHERE bucket=?');
    $stmt->execute([throttle_bucket($scope)]);
    $until = (int) ($stmt->fetchColumn() ?: 0);
    if ($until > time()) {
        header('Retry-After: ' . ($until - time()));
        media_fail(429, 'Too many attempts. Try again in a minute.', false);
    }
}

function media_auth_form(bool $registration = false, string $error = '', string $username = ''): void
{
    media_require_https();
    start_app_session();
    media_page($registration ? 'Create account' : 'Sign in', false);
    if ($error !== '') echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    if (($_GET['registered'] ?? '') === '1') echo '<p class="alert alert-success" role="status">Account created.</p>';
    echo '<form class="col-12 col-md-6 col-lg-4 library-panel" method="post" action="' . e(media_url($registration ? 'add.php' : 'verify.php')) . '">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">Username</label><input class="form-control" id="user" name="user" autocomplete="username" maxlength="128" value="' . e($username) . '" required></div><div class="mb-3"><label class="form-label" for="pass">Password</label><input class="form-control" type="password" id="pass" name="pass" maxlength="72" autocomplete="' . ($registration ? 'new-password' : 'current-password') . '" required></div>';
    if ($registration) echo '<div class="mb-3"><label class="form-label" for="pass-repeat">Repeat password</label><input class="form-control" id="pass-repeat" name="pass_repeat" type="password" maxlength="72" autocomplete="new-password" required></div>';
    echo '<button class="btn btn-primary" type="submit">' . ($registration ? 'Create account' : 'Sign in') . '</button></form><p class="mt-3"><a href="' . e(media_url($registration ? 'login.php' : 'register.php')) . '">' . ($registration ? 'Sign in' : 'Create account') . '</a></p>';
    media_end();
}

function media_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false) throw new InvalidArgumentException('Invalid identifier.');
    return $id;
}

function media_text(string $name, int $limit, bool $required = true): string
{
    $text = trim(post_string($name));
    if (($required && $text === '') || strlen($text) > $limit || preg_match('//u', $text) !== 1 || str_contains($text, "\0")) throw new InvalidArgumentException('Invalid ' . $name . '.');
    return $text;
}

function media_access_sql(string $alias = 'm'): string
{
    $key = media_config()['id'];
    return '(' . $alias . '.idu=? OR EXISTS (SELECT 1 FROM playlistdatabase a JOIN playlistname p ON p.idpl=a.idpl WHERE a.' . $key . '=' . $alias . '.' . $key . ' AND (p.public=1 OR p.idu=?)))';
}

function media_item(int $id, bool $ownerOnly = false): ?array
{
    $config = media_config();
    $where = $ownerOnly ? 'm.idu=?' : media_access_sql();
    $params = [$id, (int) $_SESSION['user_id']];
    if (!$ownerOnly) $params[] = (int) $_SESSION['user_id'];
    $stmt = db()->prepare('SELECT m.*,t.name AS genre,u.username AS owner FROM ' . $config['table'] . ' m JOIN ' . $config['types'] . ' t ON t.' . $config['type_id'] . '=m.' . $config['type_id'] . ' JOIN users u ON u.id=m.idu WHERE m.' . $config['id'] . '=? AND ' . $where);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function media_items(string $query = ''): array
{
    $config = media_config();
    $params = [(int) $_SESSION['user_id'], (int) $_SESSION['user_id']];
    $sql = 'SELECT m.*,t.name AS genre,u.username AS owner FROM ' . $config['table'] . ' m JOIN ' . $config['types'] . ' t ON t.' . $config['type_id'] . '=m.' . $config['type_id'] . ' JOIN users u ON u.id=m.idu WHERE ' . media_access_sql();
    if ($query !== '') {
        $sql .= " AND CONCAT_WS(' ',m.title,m." . $config['creator'] . ",t.name) LIKE ? ESCAPE '!'";
        $params[] = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    $stmt = db()->prepare($sql . ' ORDER BY m.datetime DESC,m.' . $config['id'] . ' DESC LIMIT 100');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function media_playlist(int $id, bool $ownerOnly = false, bool $lock = false): ?array
{
    $stmt = db()->prepare('SELECT p.*,u.username AS owner FROM playlistname p JOIN users u ON u.id=p.idu WHERE p.idpl=? AND ' . ($ownerOnly ? 'p.idu=?' : '(p.idu=? OR p.public=1)') . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id, (int) $_SESSION['user_id']]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function media_playlist_items(int $id): array
{
    $config = media_config();
    $stmt = db()->prepare('SELECT m.*,a.idpldb,a.position,t.name AS genre FROM playlistdatabase a JOIN ' . $config['table'] . ' m ON m.' . $config['id'] . '=a.' . $config['id'] . ' JOIN ' . $config['types'] . ' t ON t.' . $config['type_id'] . '=m.' . $config['type_id'] . ' WHERE a.idpl=? ORDER BY a.position,a.idpldb');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function media_queue(array $items): array
{
    $config = media_config();
    return array_map(static fn (array $item): array => ['id' => (int) $item[$config['id']], 'title' => $item['title'], 'artist' => $item[$config['creator']], 'url' => media_url('stream.php', ['id' => $item[$config['id']]]), 'type' => $config['mime'], 'lyrics' => $config['kind'] === 'audio' ? $item['lyrics'] : '', 'subtitleUrl' => $config['kind'] === 'video' && $item['subtitles'] !== '' ? media_url('subtitles.php', ['id' => $item[$config['id']]]) : null], $items);
}

function media_player(array $items): void
{
    if ($items === []) return;
    $config = media_config();
    $tag = $config['kind'] === 'audio' ? 'audio' : 'video';
    echo '<section class="library-player library-panel" data-media-player><h2 class="h5" data-player-title>' . e($items[0]['title']) . '</h2><p class="library-meta" data-player-artist>' . e($items[0][$config['creator']]) . '</p><' . $tag . ' class="w-100" controls preload="metadata" data-player-media></' . $tag . '><div class="library-actions d-flex flex-wrap gap-2 mt-3"><button class="btn btn-outline-secondary" type="button" data-player-prev>Previous</button><button class="btn btn-primary" type="button" data-player-toggle>Play</button><button class="btn btn-outline-secondary" type="button" data-player-next>Next</button><button class="btn btn-outline-secondary" type="button" data-player-repeat aria-pressed="false">Repeat</button></div><p role="status" aria-live="polite" data-player-status></p><pre class="library-lyrics" data-player-text></pre><ol class="library-queue">';
    foreach ($items as $item) echo '<li><button class="btn btn-link library-track" type="button" data-track-id="' . (int) $item[$config['id']] . '">' . e($item['title']) . '</button></li>';
    echo '</ol><script type="application/json" data-player-queue>' . json_encode(media_queue($items), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . '</script></section>';
}
