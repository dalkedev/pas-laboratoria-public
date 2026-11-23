<?php

declare(strict_types=1);

function avatar_upload(array $messages = []): ?string
{
    $messages += [
        'invalid' => 'Invalid avatar.',
        'size' => 'Avatar must be at most 2 MiB.',
        'format' => 'Avatar must be a JPEG or PNG image.',
        'dimensions' => 'Avatar must be at most 2000 × 2000 pixels.',
    ];
    $file = $_FILES['avatar'] ?? null;
    if ($file === null || (is_array($file) && ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE)) {
        return null;
    }
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
        throw new InvalidArgumentException($messages['invalid']);
    }
    $path = $file['tmp_name'];
    if (!is_uploaded_file($path)) {
        throw new InvalidArgumentException($messages['invalid']);
    }
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > 2 * 1024 * 1024) {
        throw new InvalidArgumentException($messages['size']);
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new InvalidArgumentException($messages['invalid']);
    }
    $info = @getimagesizefromstring($contents);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        throw new InvalidArgumentException($messages['format']);
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 2000 || $info[1] > 2000) {
        throw new InvalidArgumentException($messages['dimensions']);
    }
    $image = @imagecreatefromstring($contents);
    if ($image === false) {
        throw new InvalidArgumentException($messages['invalid']);
    }
    try {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > 256) {
            $ratio = 256 / max($width, $height);
            $scaled = imagescale($image, max(1, (int) floor($width * $ratio)), max(1, (int) floor($height * $ratio)));
            if ($scaled === false) {
                throw new InvalidArgumentException($messages['invalid']);
            }
            imagedestroy($image);
            $image = $scaled;
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        ob_start();
        $encoded = imagepng($image, null, 6);
        $png = ob_get_clean();
        if (!$encoded || !is_string($png) || strlen($png) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException($messages['invalid']);
        }
        return $png;
    } finally {
        imagedestroy($image);
    }
}
