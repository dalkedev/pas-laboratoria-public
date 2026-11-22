<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_login();
$lock = null;
try {
    $folder = z5_folder($_GET['dir'] ?? '');
    $name = z5_name($_GET['name'] ?? null);
    $lock = z5_lock_home(null, false);
    $path = z5_directory($lock, $folder) . '/' . $name;
    if (is_link($path) || !is_file($path)) throw new OutOfBoundsException('nie znaleziono pliku.');
    $media = z5_media_type(z5_mime($path));
} catch (InvalidArgumentException $error) { z5_fail(422, $error->getMessage()); }
catch (OutOfBoundsException $error) { z5_fail(404, $error->getMessage()); }
finally { if ($lock !== null) z5_unlock($lock); }
if ($media === '') redirect(z5_file_url($folder, $name));
z5_page_start($name, true);
$url = e(z5_file_url($folder, $name, true));
if ($media === 'image') echo '<img class="img-fluid rounded z5-full-image" src="' . $url . '" alt="' . e($name) . '">';
elseif ($media === 'audio') echo '<audio class="z5-player-audio" controls preload="none" src="' . $url . '"></audio>';
else echo '<video class="z5-player" controls preload="metadata" src="' . $url . '"></video>';
echo '<p class="mt-3"><a class="btn btn-outline-secondary" href="/z5/?' . e(http_build_query(['dir' => $folder])) . '">powrót</a></p>';
z5_page_end();
