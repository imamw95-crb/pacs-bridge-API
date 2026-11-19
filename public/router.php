<?php

declare(strict_types=1);

/**
 * Router server dev PHP: `php -S 0.0.0.0:8081 -t public public/router.php`.
 *
 * Server built-in PHP menganggap path yang memuat titik (mis.
 * `/studies/1.2.840.113619.../jpg`) sebagai file statis dan langsung membalas
 * 404 sebelum index.php dijalankan. Router ini meniru rewrite Apache di
 * .htaccess: file yang benar-benar ada disajikan apa adanya, sisanya ke API.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/' . ltrim($path, '/');

if ($path !== '/' && is_file($file)) {
    return false;
}

// Dengan router, server dev mengisi SCRIPT_NAME dengan URI permintaan (bukan
// /index.php seperti setelah rewrite Apache), sehingga index.php memotong path
// terlalu banyak. Samakan dengan perilaku Apache.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/index.php';
