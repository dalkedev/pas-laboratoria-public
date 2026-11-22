<?php

declare(strict_types=1);
require_once __DIR__ . '/media_storage.php';

function media_route_login(): void
{
    media_auth_form();
}

function media_route_register(): void
{
    media_auth_form(true);
}

function media_route_verify(): void
{
    media_require_https();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') redirect(media_url('login.php'));
    media_csrf();
    media_throttle('login');
    $user = authenticate(post_string('user'), post_string('pass'));
    if ($user === null) {
        throttle_failure('login');
        http_response_code(401);
        media_auth_form(false, 'Invalid username or password.');
        return;
    }
    throttle_clear('login');
    session_regenerate_id(true);
    $_SESSION = ['task' => app_task(), 'loggedin' => true, 'user_id' => $user['id'], 'username' => $user['username'], 'last_active' => time(), 'csrf' => bin2hex(random_bytes(32))];
    redirect(media_url());
}

function media_route_add(): void
{
    media_require_https();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') redirect(media_url('register.php'));
    media_csrf();
    media_throttle('registration');
    $username = post_string('user');
    $password = post_string('pass');
    $error = '';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,128}$/D', $username)) $error = 'Username must contain 3 to 128 letters, digits, dots, underscores or hyphens.';
    elseif ($password === '' || strlen($password) > 72 || preg_match('//u', $password) !== 1 || str_contains($password, "\0")) $error = 'Invalid password.';
    elseif (!hash_equals($password, post_string('pass_repeat'))) $error = 'Passwords do not match.';
    if ($error !== '') {
        throttle_failure('registration');
        http_response_code(422);
        media_auth_form(true, $error, media_clip($username, 128));
        return;
    }
    try {
        $stmt = db()->prepare('INSERT INTO users (username,password) VALUES (?,?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '23000') throw $error;
        throttle_failure('registration');
        http_response_code(409);
        media_auth_form(true, 'Choose a different username.', $username);
        return;
    }
    throttle_failure('registration');
    redirect(media_url('login.php', ['registered' => 1]));
}

function media_route_logout(): void
{
    media_post();
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => app_session_path(), 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    redirect(media_url('login.php'));
}

function media_route_index(): void
{
    media_require_login();
    $config = media_config();
    $q = is_string($_GET['q'] ?? null) ? media_clip(trim($_GET['q']), 128) : '';
    $items = media_items($q);
    $stmt = db()->prepare('SELECT p.*,u.username AS owner,(SELECT COUNT(*) FROM playlistdatabase a WHERE a.idpl=p.idpl) AS item_count FROM playlistname p JOIN users u ON u.id=p.idu WHERE p.idu=? OR p.public=1 ORDER BY (p.idu=?) DESC,p.datetime DESC,p.idpl DESC LIMIT 100');
    $stmt->execute([(int) $_SESSION['user_id'], (int) $_SESSION['user_id']]);
    $playlists = $stmt->fetchAll();
    media_page('Library');
    echo '<form class="library-toolbar d-flex flex-wrap gap-2 mb-4" method="get"><label class="visually-hidden" for="q">Search by title, artist or genre</label><input class="form-control" id="q" name="q" maxlength="128" placeholder="Search title, ' . $config['creator'] . ' or genre" value="' . e($q) . '"><button class="btn btn-primary" type="submit">Search</button></form>';
    media_player($items);
    echo '<section class="my-4"><h2 class="h5 mb-3">Playlists</h2><div class="library-grid">';
    foreach ($playlists as $playlist) echo '<article class="library-card"><h3 class="h6"><a href="' . e(media_url('playlist.php', ['id' => $playlist['idpl']])) . '">' . e($playlist['name']) . '</a></h3><p class="library-meta">' . e($playlist['owner']) . ' · ' . ((int) $playlist['public'] === 1 ? 'Public' : 'Private') . ' · ' . (int) $playlist['item_count'] . '</p></article>';
    if ($playlists === []) echo '<p>No playlists yet.</p>';
    echo '</div></section><section><h2 class="h5 mb-3">' . ($config['kind'] === 'audio' ? 'Songs' : 'Films') . '</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Title</th><th>' . ucfirst($config['creator']) . '</th><th>Genre</th><th>Uploaded by</th><th></th></tr></thead><tbody>';
    foreach ($items as $item) {
        echo '<tr data-media-id="' . (int) $item[$config['id']] . '"><td><a href="' . e(media_url('play.php', ['id' => $item[$config['id']]])) . '">' . e($item['title']) . '</a></td><td>' . e($item[$config['creator']]) . '</td><td>' . e($item['genre']) . '</td><td>' . e($item['owner']) . '</td><td>';
        if ((int) $item['idu'] === (int) $_SESSION['user_id']) echo '<form method="post" action="' . e(media_url('media_delete.php')) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $item[$config['id']] . '"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';
    media_end();
}

function media_route_select(): void
{
    media_require_login();
    $config = media_config();
    $types = db()->query('SELECT * FROM ' . $config['types'] . ' ORDER BY ' . $config['type_id'])->fetchAll();
    media_page($config['kind'] === 'audio' ? 'Upload song' : 'Upload film');
    echo '<form class="library-panel col-12 col-lg-7" method="post" enctype="multipart/form-data" action="' . e(media_url('upload.php')) . '">' . csrf_field();
    foreach (['title' => 'Title', $config['creator'] => ucfirst($config['creator'])] as $name => $label) echo '<div class="mb-3"><label class="form-label" for="' . $name . '">' . $label . '</label><input class="form-control" id="' . $name . '" name="' . $name . '" maxlength="255" required></div>';
    echo '<div class="mb-3"><label class="form-label" for="genre">Genre</label><select class="form-select" id="genre" name="' . $config['type_id'] . '" required>';
    foreach ($types as $type) echo '<option value="' . (int) $type[$config['type_id']] . '">' . e($type['name']) . '</option>';
    echo '</select></div><div class="mb-3"><label class="form-label" for="file">' . strtoupper($config['extension']) . ' file</label><input class="form-control" id="file" type="file" name="file" accept=".' . $config['extension'] . ',' . $config['mime'] . '" required></div><div class="mb-3"><label class="form-label" for="media-text">' . ($config['kind'] === 'audio' ? 'Lyrics (optional)' : 'Subtitles (optional, WebVTT or SRT)') . '</label><textarea class="form-control" id="media-text" name="' . $config['text'] . '" rows="7" maxlength="60000"></textarea></div><button class="btn btn-primary" type="submit">Upload</button></form>';
    media_end();
}

function media_route_upload(): void
{
    media_post();
    $config = media_config();
    $path = null;
    try {
        $title = media_text('title', 255);
        $creator = media_text($config['creator'], 255);
        $text = media_text($config['text'], 60000, false);
        $type = media_id(post_string($config['type_id']));
        $stmt = db()->prepare('SELECT ' . $config['type_id'] . ' FROM ' . $config['types'] . ' WHERE ' . $config['type_id'] . '=?');
        $stmt->execute([$type]);
        if ($stmt->fetchColumn() === false) throw new InvalidArgumentException('Choose a valid genre.');
        $upload = media_upload_file();
        $filename = bin2hex(random_bytes(24)) . '.' . $config['extension'];
        $candidate = media_storage() . '/' . $filename;
        if (file_exists($candidate) || is_link($candidate)) throw new RuntimeException('Upload unavailable.');
        if (!move_uploaded_file($upload['source'], $candidate)) throw new RuntimeException('Upload unavailable.');
        $path = $candidate;
        @chmod($path, 0600);
        $stmt = db()->prepare('INSERT INTO ' . $config['table'] . ' (title,' . $config['creator'] . ',idu,filename,' . $config['text'] . ',' . $config['type_id'] . ',mime,size) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$title, $creator, (int) $_SESSION['user_id'], $filename, $text, $type, $upload['mime'], $upload['size']]);
    } catch (InvalidArgumentException $error) {
        if ($path !== null && is_file($path)) @unlink($path);
        media_fail(422, $error->getMessage());
    } catch (Throwable $error) {
        if ($path !== null && is_file($path)) @unlink($path);
        throw $error;
    }
    redirect(media_url());
}

function media_route_play(): void
{
    media_require_login();
    try { $item = media_item(media_id($_GET['id'] ?? null)); }
    catch (InvalidArgumentException $error) { $item = null; }
    if ($item === null) media_fail(404, 'Media not found');
    media_page($item['title']);
    media_player([$item]);
    media_end();
}

function media_route_stream(): void
{
    media_require_login();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        return;
    }
    try { $item = media_item(media_id($_GET['id'] ?? null)); }
    catch (InvalidArgumentException $error) { $item = null; }
    if ($item === null) {
        http_response_code(404);
        return;
    }
    media_send_file($item);
}

function media_route_subtitles(): void
{
    media_require_login();
    if (media_config()['kind'] !== 'video') {
        http_response_code(404);
        return;
    }
    try { $item = media_item(media_id($_GET['id'] ?? null)); }
    catch (InvalidArgumentException $error) { $item = null; }
    if ($item === null || $item['subtitles'] === '') {
        http_response_code(404);
        return;
    }
    header('Content-Type: text/vtt; charset=UTF-8');
    echo media_vtt($item['subtitles']);
}

function media_route_media_delete(): void
{
    media_post();
    try { $item = media_item(media_id(post_string('id')), true); }
    catch (InvalidArgumentException $error) { $item = null; }
    if ($item === null) media_fail(404, 'Media not found');
    $path = media_file($item);
    $config = media_config();
    $stmt = db()->prepare('DELETE FROM ' . $config['table'] . ' WHERE ' . $config['id'] . '=? AND idu=?');
    $stmt->execute([$item[$config['id']], (int) $_SESSION['user_id']]);
    if ($stmt->rowCount() === 1 && $path !== null) @unlink($path);
    redirect(media_url());
}

function media_route_playlist(): void
{
    media_require_login();
    $playlist = null;
    if (isset($_GET['id'])) {
        try { $playlist = media_playlist(media_id($_GET['id'])); }
        catch (InvalidArgumentException $error) { $playlist = null; }
        if ($playlist === null) media_fail(404, 'Playlist not found');
    }
    $own = $playlist === null || (int) $playlist['idu'] === (int) $_SESSION['user_id'];
    media_page($playlist['name'] ?? 'New playlist');
    if ($playlist !== null) {
        echo '<p class="library-meta">' . e($playlist['owner']) . ' · ' . ((int) $playlist['public'] === 1 ? 'Public' : 'Private') . '</p>';
        media_player(media_playlist_items((int) $playlist['idpl']));
    }
    if ($own) {
        echo '<form class="library-panel col-12 col-md-7 my-4" method="post" action="' . e(media_url('playlist_save.php')) . '">' . csrf_field();
        if ($playlist !== null) echo '<input type="hidden" name="idpl" value="' . (int) $playlist['idpl'] . '">';
        echo '<div class="mb-3"><label class="form-label" for="name">Playlist name</label><input class="form-control" id="name" name="name" maxlength="255" value="' . e($playlist['name'] ?? '') . '" required></div><div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="public" name="public" value="1"' . ((int) ($playlist['public'] ?? 0) === 1 ? ' checked' : '') . '><label class="form-check-label" for="public">Public</label></div><button class="btn btn-primary" type="submit">Save playlist</button></form>';
        if ($playlist !== null) {
            $config = media_config();
            echo '<form class="library-panel col-12 col-md-7 mb-4" method="post" action="' . e(media_url('playlist_add.php')) . '">' . csrf_field() . '<input type="hidden" name="idpl" value="' . (int) $playlist['idpl'] . '"><div class="mb-3"><label class="form-label" for="media-item">Add from your library</label><select class="form-select" name="' . $config['id'] . '" id="media-item" required>';
            foreach (media_items() as $item) echo '<option value="' . (int) $item[$config['id']] . '">' . e($item['title'] . ' / ' . $item[$config['creator']]) . '</option>';
            echo '</select></div><button class="btn btn-primary" type="submit">Add to playlist</button></form><ul class="list-group mb-4">';
            foreach (media_playlist_items((int) $playlist['idpl']) as $item) echo '<li class="list-group-item d-flex justify-content-between align-items-center">' . e($item['title']) . '<form method="post" action="' . e(media_url('playlist_remove.php')) . '">' . csrf_field() . '<input type="hidden" name="idpl" value="' . (int) $playlist['idpl'] . '"><input type="hidden" name="idpldb" value="' . (int) $item['idpldb'] . '"><button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form></li>';
            echo '</ul><form method="post" action="' . e(media_url('playlist_delete.php')) . '">' . csrf_field() . '<input type="hidden" name="idpl" value="' . (int) $playlist['idpl'] . '"><button class="btn btn-outline-danger" type="submit">Delete playlist</button></form>';
        }
    }
    media_end();
}

function media_route_playlist_save(): void
{
    media_post();
    try { $name = media_text('name', 255); }
    catch (InvalidArgumentException $error) { media_fail(422, $error->getMessage()); }
    $public = post_string('public') === '1' ? 1 : 0;
    if (post_string('idpl') !== '') {
        try { $id = media_id(post_string('idpl')); }
        catch (InvalidArgumentException $error) { media_fail(422, $error->getMessage()); }
        if (media_playlist($id, true) === null) media_fail(404, 'Playlist not found');
        $stmt = db()->prepare('UPDATE playlistname SET name=?,public=? WHERE idpl=? AND idu=?');
        $stmt->execute([$name, $public, $id, (int) $_SESSION['user_id']]);
    } else {
        $stmt = db()->prepare('INSERT INTO playlistname (idu,name,public) VALUES (?,?,?)');
        $stmt->execute([(int) $_SESSION['user_id'], $name, $public]);
        $id = (int) db()->lastInsertId();
    }
    redirect(media_url('playlist.php', ['id' => $id]));
}

function media_route_playlist_add(): void
{
    media_post();
    $config = media_config();
    try {
        $id = media_id(post_string('idpl'));
        $item = media_id(post_string($config['id']));
    } catch (InvalidArgumentException $error) { media_fail(422, $error->getMessage()); }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (media_playlist($id, true, true) === null || media_item($item) === null) {
            $pdo->rollBack();
            media_fail(404, 'Playlist or media not found');
        }
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM playlistdatabase WHERE idpl=?');
        $stmt->execute([$id]);
        $position = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO playlistdatabase (idpl,' . $config['id'] . ',position) VALUES (?,?,?)');
        $stmt->execute([$id, $item, $position]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error->getCode() === '23000') media_fail(409, 'Media already in this playlist');
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    redirect(media_url('playlist.php', ['id' => $id]));
}

function media_route_playlist_remove(): void
{
    media_post();
    try { $id = media_id(post_string('idpl')); $entry = media_id(post_string('idpldb')); }
    catch (InvalidArgumentException $error) { media_fail(422, $error->getMessage()); }
    if (media_playlist($id, true) === null) media_fail(404, 'Playlist not found');
    $stmt = db()->prepare('DELETE FROM playlistdatabase WHERE idpl=? AND idpldb=?');
    $stmt->execute([$id, $entry]);
    if ($stmt->rowCount() === 0) media_fail(404, 'Playlist entry not found');
    redirect(media_url('playlist.php', ['id' => $id]));
}

function media_route_playlist_delete(): void
{
    media_post();
    try { $id = media_id(post_string('idpl')); }
    catch (InvalidArgumentException $error) { media_fail(422, $error->getMessage()); }
    $stmt = db()->prepare('DELETE FROM playlistname WHERE idpl=? AND idu=?');
    $stmt->execute([$id, (int) $_SESSION['user_id']]);
    if ($stmt->rowCount() === 0) media_fail(404, 'Playlist not found');
    redirect(media_url());
}
