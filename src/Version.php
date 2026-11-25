<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Versi aplikasi bridge. Dipakai `/health` dan penanda di dashboard supaya
 * hasil deploy bisa diverifikasi dari jarak jauh tanpa login.
 */
final class Version
{
    public const VERSION = '1.3.1';
}
