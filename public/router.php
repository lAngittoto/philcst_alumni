<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$realPath = __DIR__ . $uri;

if ($uri !== '/' && file_exists($realPath) && !is_dir($realPath)) {
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'css' => 'text/css', 'js' => 'application/javascript',
        'ico' => 'image/x-icon', 'pdf' => 'application/pdf',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    readfile($realPath);
    return true;
}

require __DIR__ . '/index.php';