<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Pembuat arsip ZIP minimal (metode "store", tanpa ekstensi zip/zlib).
 *
 * Dipakai untuk membungkus gambar JPEG hasil render Orthanc menjadi satu
 * file ZIP tanpa menambah dependency atau ekstensi PHP.
 */
final class ZipWriter
{
    private string $local = '';
    private string $central = '';
    private int $count = 0;

    private int $dosTime;
    private int $dosDate;

    public function __construct(?int $timestamp = null)
    {
        $t = getdate($timestamp ?? time());
        $this->dosTime = (($t['hours'] ?? 0) << 11) | (($t['minutes'] ?? 0) << 5) | intdiv((int) ($t['seconds'] ?? 0), 2);
        $this->dosDate = (((int) ($t['year'] ?? 1980) - 1980) << 9) | ((int) ($t['mon'] ?? 1) << 5) | (int) ($t['mday'] ?? 1);
    }

    public function addFile(string $name, string $content): void
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $crc = crc32($content);
        $size = strlen($content);
        $offset = strlen($this->local);

        $this->local .= pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $this->dosTime,
            $this->dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0
        ) . $name . $content;

        $this->central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $this->dosTime,
            $this->dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;

        $this->count++;
    }

    /**
     * Tambahkan folder kosong (agar terlihat sebagai direktori di alat ekstrak).
     */
    public function addDirectory(string $name): void
    {
        $this->addFile(rtrim($name, '/') . '/', '');
    }

    public function finish(): string
    {
        $cdOffset = strlen($this->local);
        $cdSize = strlen($this->central);

        return $this->local . $this->central . pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $this->count,
            $this->count,
            $cdSize,
            $cdOffset,
            0
        );
    }
}
