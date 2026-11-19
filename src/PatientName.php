<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Helper format PatientName.
 *
 * Temuan lapangan: modalitas DX memotong PatientName pada karakter koma,
 * sehingga "NAMA, NY" tersimpan/tampil sebagai "NAMA" saja.
 * Karena itu gelar digabung tanpa koma.
 */
final class PatientName
{
    /**
     * Format yang aman untuk modalitas: "TARYA BIN WARDI TN".
     */
    public static function safe(string $name, string $title = ''): string
    {
        $cleanName = self::normalize($name);
        $cleanTitle = self::normalizeTitle($title);

        if ($cleanTitle === '' || str_ends_with(mb_strtoupper($cleanName), mb_strtoupper($cleanTitle))) {
            return $cleanName;
        }

        return $cleanName . ' ' . $cleanTitle;
    }

    /**
     * Format PN terstruktur (komponen suffix): "TARYA BIN WARDI^^^^TN".
     * Dipakai hanya bila modalitas mendukung rendering suffix.
     */
    public static function withSuffix(string $name, string $title): string
    {
        return self::normalize($name) . '^^^^' . self::normalizeTitle($title);
    }

    private static function normalize(string $name): string
    {
        $name = str_replace([',', '^', '_'], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private static function normalizeTitle(string $title): string
    {
        $title = trim(str_replace([',', '^'], ' ', $title));
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return mb_strtoupper(trim($title));
    }
}
