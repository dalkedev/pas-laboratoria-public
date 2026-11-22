<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_login();
$lock = null;
try {
    $folder = z5_folder($_GET['dir'] ?? '');
    $lock = z5_lock_home(null, false);
    $directory = z5_directory($lock, $folder);
    $items = [];
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_link($path) || (!is_file($path) && !is_dir($path))) continue;
        $items[] = ['name' => $name, 'folder' => is_dir($path), 'size' => is_file($path) ? filesize($path) : null, 'mime' => is_file($path) ? z5_mime($path) : ''];
    }
    usort($items, static fn (array $a, array $b): int => ($b['folder'] <=> $a['folder']) ?: strnatcasecmp($a['name'], $b['name']));
} catch (InvalidArgumentException $error) { z5_fail(422, $error->getMessage()); }
catch (OutOfBoundsException $error) { z5_fail(404, $error->getMessage()); }
finally { if ($lock !== null) z5_unlock($lock); }
z5_page_start($folder === '' ? 'moje pliki' : $folder, true);
?>
<div class="d-flex flex-wrap gap-2 mb-4 z5-toolbar">
<?php if ($folder !== ''): ?><a class="btn btn-outline-secondary" href="/z5/" aria-label="katalog nadrzędny">↑ katalog główny</a><?php endif; ?>
<a class="btn btn-primary" href="/z5/select.php?<?= e(http_build_query(['dir' => $folder])) ?>">↑ prześlij plik</a>
</div>
<?php if ($folder === ''): ?>
<form method="post" action="/z5/mkdir.php" class="col-md-6 mb-4 z5-folder-form"><?= csrf_field() ?><input type="hidden" name="dir" value=""><label class="form-label" for="folder-name">nowy katalog:</label><div class="input-group"><input class="form-control" name="name" id="folder-name" maxlength="255" required><button class="btn btn-primary" type="submit">+ utwórz</button></div></form>
<?php endif; ?>
<div class="table-responsive mb-4"><table class="table table-striped table-bordered table-hover align-middle"><thead class="table-light"><tr><th scope="col">nazwa</th><th scope="col">rozmiar</th><th scope="col">podgląd</th><th scope="col">usuń</th></tr></thead><tbody>
<?php foreach ($items as $item): $name = $item['name']; $url = z5_file_url($folder, $name); $inline = z5_file_url($folder, $name, true); $media = z5_media_type($item['mime']); ?>
<tr><td class="text-break"><?php if ($item['folder']): ?><a href="/z5/?<?= e(http_build_query(['dir' => $name])) ?>">📁 <?= e($name) ?></a><?php else: ?><a href="<?= e($url) ?>"><?= e($name) ?></a><?php endif; ?></td>
<td><?= $item['size'] === null ? 'katalog' : e(number_format((float) $item['size'], 0, ',', ' ') . ' b') ?></td>
<td><?php if ($media === 'image'): ?><a href="/z5/view.php?<?= e(http_build_query(['dir' => $folder, 'name' => $name])) ?>"><img class="z5-thumbnail rounded" src="<?= e($inline) ?>" alt="<?= e($name) ?>" loading="lazy"></a><?php elseif ($media === 'audio'): ?><audio class="z5-preview-audio" controls preload="none" src="<?= e($inline) ?>"></audio><?php elseif ($media === 'video'): ?><video class="z5-preview-video" controls preload="none" src="<?= e($inline) ?>"></video><?php endif; ?></td>
<td><form method="post" action="/z5/delete.php"><?= csrf_field() ?><input type="hidden" name="dir" value="<?= e($folder) ?>"><input type="hidden" name="name" value="<?= e($name) ?>"><button class="btn btn-sm btn-outline-danger" type="submit" aria-label="usuń <?= e($name) ?>">×</button></form></td></tr>
<?php endforeach; ?>
<?php if ($items === []): ?><tr><td colspan="4">brak plików</td></tr><?php endif; ?>
</tbody></table></div>
<?php z5_page_end(); ?>
