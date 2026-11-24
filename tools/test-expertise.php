<?php

declare(strict_types=1);

/**
 * Uji endpoint auto expertise (Ollama) lewat Bridge API.
 *
 *   php tools/test-expertise.php http://127.0.0.1:8081 <API_KEY> [STUDY_KEY] [--keep]
 *
 * Tanpa STUDY_KEY, study terbaru yang sudah punya gambar akan dipakai.
 * Draf hasil uji dihapus dari `data/expertise/` di akhir (kecuali `--keep`).
 * Membutuhkan server Ollama aktif dan `ollama_enabled` = true di Pengaturan.
 */

require dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\BridgeApiClient;

$args = array_slice($argv, 1);
$keep = in_array('--keep', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--keep'));
$baseUrl = $args[0] ?? '';
$apiKey = $args[1] ?? '';
$studyKey = $args[2] ?? '';

if ($baseUrl === '' || $apiKey === '') {
    fwrite(STDERR, "Penggunaan: php tools/test-expertise.php <baseUrl> <apiKey> [studyKey] [--keep]\n");
    exit(2);
}

$pass = 0;
$fail = 0;

/**
 * @param callable(): string $fn
 */
function check(string $label, callable $fn): void
{
    global $pass, $fail;

    try {
        $info = $fn();
        $pass++;
        printf("[PASS] %-46s %s\n", $label, $info);
    } catch (Throwable $e) {
        $fail++;
        printf("[FAIL] %-46s %s\n", $label, $e->getMessage());
    }
}

$client = new BridgeApiClient($baseUrl, $apiKey);

/** @var array<string, mixed> $status */
$status = [];
check('status server Ollama', static function () use ($client, &$status): string {
    $status = $client->expertiseStatus();
    if (($status['reachable'] ?? false) !== true) {
        throw new RuntimeException('tidak terjangkau: ' . (string) ($status['error'] ?? '?'));
    }
    $vision = is_array($status['vision_models'] ?? null) ? $status['vision_models'] : [];

    return 'v' . (string) ($status['version'] ?? '?') . ' · model vision: ' . ($vision === [] ? '-' : implode(', ', $vision));
});

check('auto expertise aktif', static function () use (&$status): string {
    if (($status['enabled'] ?? false) !== true) {
        throw new RuntimeException('ollama_enabled masih false — aktifkan di Pengaturan → Auto Expertise');
    }

    return 'ollama_enabled = true';
});

check('model aktif punya capability vision', static function () use (&$status): string {
    if (($status['model_ready'] ?? false) !== true) {
        throw new RuntimeException('model ' . (string) ($status['model'] ?? '?') . ' bukan model vision');
    }

    return (string) $status['model'];
});

if ($studyKey === '') {
    check('pilih study terbaru yang punya gambar', static function () use ($client, &$studyKey): string {
        foreach ($client->recentStudies('all', 10)['studies'] ?? [] as $study) {
            $tags = is_array($study['MainDicomTags'] ?? null) ? $study['MainDicomTags'] : [];
            $key = (string) ($tags['AccessionNumber'] ?? '');
            if ($key === '') {
                $key = (string) ($study['ID'] ?? '');
            }
            if ($key !== '' && $client->imagesByAccession($key) !== []) {
                $studyKey = $key;

                return $key;
            }
        }
        throw new RuntimeException('tidak ada study bergambar; kirim STUDY_KEY sebagai argumen ke-3');
    });
}

/** @var array<string, mixed> $draft */
$draft = [];
check('buat draf expertise (POST)', static function () use ($client, &$studyKey, &$draft): string {
    if ($studyKey === '') {
        throw new RuntimeException('tanpa study key');
    }
    $draft = $client->createExpertise($studyKey);
    $isi = is_array($draft['draft'] ?? null) ? $draft['draft'] : [];
    if (trim((string) ($isi['kesan'] ?? '') . (string) ($isi['temuan_utama'] ?? '')) === '') {
        throw new RuntimeException('draf kosong — cek model/timeout server Ollama');
    }

    return sprintf('%s · %s · %d ms', (string) ($draft['model'] ?? '?'), (string) ($draft['modality'] ?? '?'), (int) ($draft['duration_ms'] ?? 0));
});

check('draf tersimpan & bisa dibaca ulang (GET)', static function () use ($client, &$studyKey, &$draft): string {
    if ($studyKey === '') {
        throw new RuntimeException('tanpa study key');
    }
    $stored = $client->expertise($studyKey);
    if ($stored === null) {
        throw new RuntimeException('GET /studies/' . $studyKey . '/expertise -> 404');
    }
    if (($stored['generated_at'] ?? '') !== ($draft['generated_at'] ?? '')) {
        throw new RuntimeException('draf tersimpan berbeda dengan hasil POST');
    }

    return 'generated_at = ' . (string) $stored['generated_at'];
});

check('study tanpa draf -> null/404', static function () use ($client): string {
    if ($client->expertise('ZZTEST-NOPE-000') !== null) {
        throw new RuntimeException('seharusnya tidak ada draf untuk kunci uji');
    }

    return 'tanpa draf (sesuai harapan)';
});

if (!$keep && $studyKey !== '') {
    $file = dirname(__DIR__) . '/data/expertise/' . preg_replace('/[^A-Za-z0-9._-]/', '', $studyKey) . '.json';
    if (is_file($file) && unlink($file)) {
        echo "[INFO] draf uji dihapus: " . $file . "\n";
    }
}

printf("\n%d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
