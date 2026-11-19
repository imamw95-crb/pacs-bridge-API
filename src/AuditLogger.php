<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Audit log sederhana (JSON Lines) di `data/audit.log`.
 *
 * Setiap baris mencatat siapa (sesi user / API key / IP), kapan, aksi apa,
 * dan konteksnya (mis. accession number). Dipakai untuk:
 *   - menutup kebutuhan audit ("siapa membuat/menghapus order"),
 *   - menelusuri status order (`worklist.create` / `worklist.delete`).
 *
 * File dirotasi otomatis ke `audit.log.1` bila melewati 5 MB.
 */
final class AuditLogger
{
    private const MAX_BYTES = 5_242_880; // 5 MB
    private const TAIL_BYTES = 524_288;  // 512 KB terakhir untuk pembacaan

    private static ?string $path = null;

    /** @var array{via: string, user: string|null, role: string|null} */
    private static array $auth = ['via' => 'none', 'user' => null, 'role' => null];

    private static float $startedAt = 0.0;

    public static function boot(?string $path = null): void
    {
        self::$path = $path ?? dirname(__DIR__) . '/data/audit.log';
        self::$startedAt = microtime(true);
    }

    public static function setAuth(string $via, ?string $user, ?string $role): void
    {
        self::$auth = ['via' => $via, 'user' => $user, 'role' => $role];
    }

    public static function actor(): string
    {
        if (self::$auth['via'] === 'session') {
            return 'session:' . (self::$auth['user'] ?? '-');
        }

        return self::$auth['via'];
    }

    /**
     * Catat aksi penting (login, CRUD order, perubahan pengaturan, dll).
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $event, array $data = []): void
    {
        self::write(['event' => $event] + $data);
    }

    /**
     * Catat satu request HTTP (dipanggil saat shutdown, status akhir sudah diketahui).
     */
    public static function request(string $method, string $path, int $status, ?string $accession = null, ?float $startedAt = null, ?string $query = null): void
    {
        $start = $startedAt ?? self::$startedAt;
        $entry = [
            'event'       => 'request',
            'method'      => $method,
            'path'        => $path,
            'status'      => $status,
            'duration_ms' => $start > 0 ? (int) round((microtime(true) - $start) * 1000) : null,
        ];
        if ($query !== null && $query !== '') {
            $entry['query'] = $query;
        }
        if ($accession !== null && $accession !== '') {
            $entry['accession'] = $accession;
        }

        self::write($entry);
    }

    /**
     * Riwayat event untuk satu accession (untuk timeline status order).
     *
     * @return list<array<string, mixed>> urut lama → baru
     */
    public static function history(string $accession, int $limit = 50): array
    {
        $rows = self::tail($limit, ['accession' => $accession]);

        return array_reverse($rows);
    }

    /**
     * Baca entri terakhir (urut lama → baru) dengan filter opsional.
     *
     * @param array<string, string> $filters kandidat: event, accession, method, status, user, auth
     * @return list<array<string, mixed>>
     */
    public static function tail(int $limit = 50, array $filters = []): array
    {
        $path = self::$path ?? dirname(__DIR__) . '/data/audit.log';
        if (!is_file($path)) {
            return [];
        }

        $size = (int) filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        if ($size > self::TAIL_BYTES) {
            fseek($fh, -self::TAIL_BYTES, SEEK_END);
            fgets($fh); // buang baris pertama yang mungkin terpotong
        }

        $rows = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            foreach ($filters as $key => $value) {
                if ($value === '' || !array_key_exists($key, $row)) {
                    continue 2;
                }
                if ((string) $row[$key] !== $value) {
                    continue 2;
                }
            }
            $rows[] = $row;
        }
        fclose($fh);

        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function write(array $entry): void
    {
        $path = self::$path ?? dirname(__DIR__) . '/data/audit.log';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            @rename($path, $path . '.1');
        }

        $entry = [
            'ts'   => date('c'),
            'auth' => self::$auth['via'],
            'user' => self::$auth['user'],
            'role' => self::$auth['role'],
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ] + $entry;

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        @file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
    }
}
