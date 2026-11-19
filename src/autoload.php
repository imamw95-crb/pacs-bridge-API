<?php

declare(strict_types=1);

/**
 * Autoloader sederhana (tanpa Composer) untuk namespace Simrs\Pacs.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Simrs\\Pacs\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Zona waktu server (penting untuk StudyDate/StudyTime worklist & nomor accession).
$timezone = getenv('PACS_TIMEZONE');
date_default_timezone_set(is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Jakarta');
