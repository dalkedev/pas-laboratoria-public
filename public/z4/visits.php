<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4.php';
z4_require_login();
require_once dirname(__DIR__, 2) . '/app/z3_diagnostics.php';
z4_page_start('goście portalu', true);

$q = $_GET['q'] ?? '';
$q = is_string($q) ? z4_clip(trim($q), 128) : '';
$columns = ['id' => 'id', 'ipaddress' => 'ip', 'datetime' => 'data', 'browser' => 'przeglądarka', 'screen_width' => 'ekran', 'viewport_width' => 'okno', 'colors' => 'kolory', 'cookies_enabled' => 'ciasteczka', 'java_enabled' => 'java', 'language' => 'język'];
$sort = $_GET['sort'] ?? 'datetime';
$sort = is_string($sort) && isset($columns[$sort]) ? $sort : 'datetime';
$direction = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = $_GET['page'] ?? '1';
$page = is_string($page) && ctype_digit($page) && strlen($page) < 8 ? max(1, (int) $page) : 1;
$where = '';
$parameters = [];
if ($q !== '') {
    $where = " WHERE CONCAT_WS(' ', ipaddress, datetime, browser, user_agent, language, screen_width, screen_height, viewport_width, viewport_height, colors) LIKE ? ESCAPE '!'";
    $parameters[] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}
$stmt = db()->prepare('SELECT COUNT(*) FROM goscieportalu' . $where);
$stmt->execute($parameters);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / 10));
$page = min($page, $pages);
$order = $sort . ' ' . strtoupper($direction) . ($sort === 'id' ? '' : ', id DESC');
$stmt = db()->prepare('SELECT * FROM goscieportalu' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
$position = 1;
foreach ($parameters as $parameter) {
    $stmt->bindValue($position++, $parameter);
}
$stmt->bindValue($position++, 10, PDO::PARAM_INT);
$stmt->bindValue($position, ($page - 1) * 10, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$geolocation = [];
foreach ($rows as $row) {
    if (!isset($geolocation[$row['ipaddress']])) {
        $geolocation[$row['ipaddress']] = z3_geo($row['ipaddress']);
    }
}
$url = static function (array $changes) use ($q, $sort, $direction, $page): string {
    return '/z4/visits.php?' . http_build_query(array_replace(['q' => $q, 'sort' => $sort, 'dir' => $direction, 'page' => $page], $changes), '', '&', PHP_QUERY_RFC3986);
};
$dimension = static function (mixed $width, mixed $height): string {
    return $width === null || $height === null ? '' : (string) $width . ' x ' . (string) $height;
};
$boolean = static fn (mixed $value): string => $value === null ? '' : ((int) $value === 1 ? 'tak' : 'nie');
?>
<form method="get" action="/z4/visits.php" class="col-md-6 col-lg-4 mb-4 z4-filter">
<label class="form-label" for="q">filtr:</label>
<input class="form-control" id="q" name="q" maxlength="128" value="<?= e($q) ?>">
<input type="hidden" name="sort" value="<?= e($sort) ?>">
<input type="hidden" name="dir" value="<?= e($direction) ?>">
<button class="btn btn-primary mt-2" type="submit">szukaj</button>
</form>
<div class="table-responsive mb-4">
<table class="table table-striped table-bordered table-hover align-middle">
<thead class="table-light"><tr>
<?php foreach ($columns as $field => $label): ?>
<th scope="col"><a href="<?= e($url(['sort' => $field, 'dir' => $sort === $field && $direction === 'asc' ? 'desc' : 'asc', 'page' => 1])) ?>"><?= e($label) ?></a></th>
<?php endforeach; ?>
<th scope="col">miasto</th><th scope="col">region</th><th scope="col">kraj</th><th scope="col">współrzędne</th><th scope="col">mapa</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $row): $geo = $geolocation[$row['ipaddress']]; ?>
<tr data-visit-id="<?= e((string) $row['id']) ?>">
<td><?= e((string) $row['id']) ?></td>
<td><?= e($row['ipaddress']) ?></td>
<td><?= e($row['datetime']) ?></td>
<td><?= e($row['browser']) ?></td>
<td><?= e($dimension($row['screen_width'], $row['screen_height'])) ?></td>
<td><?= e($dimension($row['viewport_width'], $row['viewport_height'])) ?></td>
<td><?= e($row['colors'] === null ? '' : (string) $row['colors']) ?></td>
<td><?= e($boolean($row['cookies_enabled'])) ?></td>
<td><?= e($boolean($row['java_enabled'])) ?></td>
<td><?= e((string) ($row['language'] ?? '')) ?></td>
<td><?= e((string) ($geo['city'] ?? '')) ?></td>
<td><?= e((string) ($geo['region'] ?? '')) ?></td>
<td><?= e((string) ($geo['country'] ?? '')) ?></td>
<td><?= e((string) ($geo['loc'] ?? '')) ?></td>
<td><?php if (is_string($geo['loc'] ?? null) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?,-?[0-9]+(?:\.[0-9]+)?$/D', $geo['loc'])): ?><a href="<?= e('https://www.google.com/maps?q=' . rawurlencode($geo['loc'])) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">mapa</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<nav aria-label="strony">
<ul class="pagination flex-wrap">
<?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="<?= e($url(['page' => $page - 1])) ?>">poprzednia</a></li><?php endif; ?>
<li class="page-item"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
<?php if ($page < $pages): ?><li class="page-item"><a class="page-link" href="<?= e($url(['page' => $page + 1])) ?>">następna</a></li><?php endif; ?>
</ul>
</nav>
<?php z4_page_end(); ?>
