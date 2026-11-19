<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use RuntimeException;

/**
 * Watermark identitas pasien pada gambar hasil render Orthanc.
 *
 * Membutuhkan ekstensi GD dengan FreeType (imagettftext) dan satu font TTF.
 * Tata letak mengikuti viewer dashboard: kiri = identitas + pemeriksaan,
 * kanan = institusi + dokter pengirim. Warna teks orange.
 */
final class WatermarkService
{
    /** @var array{0: int, 1: int, 2: int} */
    private const TEXT_COLOR = [255, 152, 0];

    private static ?string $font = null;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagettftext');
    }

    /**
     * @param array<string, string> $info
     */
    public function applyJpeg(string $bytes, array $info, int $quality = 90): string
    {
        if (!self::available()) {
            throw new RuntimeException('Watermark butuh ekstensi PHP GD dengan FreeType (imagettftext)');
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new RuntimeException('Gambar tidak dapat dibaca untuk watermark');
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $font = self::font();

        $name = strtoupper(trim((string) ($info['name'] ?? ''))) ?: '(TANPA NAMA)';
        $sex = strtoupper(trim((string) ($info['sex'] ?? '')));
        $date = trim((string) ($info['date'] ?? ''));
        $desc = trim((string) ($info['desc'] ?? ''));
        $rm = trim((string) ($info['pid'] ?? ''));

        $left = array_values(array_filter([
            self::join([$name, $sex, $date]),
            self::join([$desc, $date]),
            $rm !== '' ? 'No. RM: ' . $rm : '',
        ], static fn (string $line): bool => $line !== ''));

        $right = array_values(array_filter([
            trim((string) ($info['institution'] ?? '')),
            trim((string) ($info['physician'] ?? '')),
        ], static fn (string $line): bool => $line !== ''));

        $fs = max(11, (int) round($w * 0.0165));
        $padX = (int) round($w * 0.012);
        $padY = (int) round($h * 0.01);
        $lh = (int) round($fs * 1.5);
        $shift = max(1, (int) round($fs / 16));

        $text = imagecolorallocate($img, self::TEXT_COLOR[0], self::TEXT_COLOR[1], self::TEXT_COLOR[2]);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 55);

        foreach ($left as $i => $line) {
            $y = $padY + $fs + $i * $lh;
            imagettftext($img, $fs, 0, $padX + $shift, $y + $shift, $shadow, $font, $line);
            imagettftext($img, $fs, 0, $padX, $y, $text, $font, $line);
        }

        foreach ($right as $i => $line) {
            $box = imagettfbbox($fs, 0, $font, $line);
            $x = $w - $padX - abs($box[2] - $box[0]);
            $y = $padY + $fs + $i * $lh;
            imagettftext($img, $fs, 0, $x + $shift, $y + $shift, $shadow, $font, $line);
            imagettftext($img, $fs, 0, $x, $y, $text, $font, $line);
        }

        ob_start();
        imagejpeg($img, null, max(1, min(100, $quality)));
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /**
     * @param list<string> $parts
     */
    private static function join(array $parts): string
    {
        return implode(', ', array_values(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    private static function font(): string
    {
        if (self::$font !== null) {
            return self::$font;
        }

        $env = getenv('PACS_WATERMARK_FONT');
        $candidates = [];
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $candidates = array_merge($candidates, [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\segoeuib.ttf',
            'C:\\Windows\\Fonts\\segoeui.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/Library/Fonts/Arial Bold.ttf',
            '/Library/Fonts/Arial.ttf',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return self::$font = $path;
            }
        }

        throw new RuntimeException('Font TTF untuk watermark tidak ditemukan (set PACS_WATERMARK_FONT)');
    }
}
