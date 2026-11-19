<?php

declare(strict_types=1);

/**
 * Kirim **callback / webhook** ke SIMRS saat ada study baru (stabil) di Orthanc.
 *
 * Cara kerja:
 *   1. Baca `/changes` Orthanc sejak sequence terakhir (state di data/notify-state.json).
 *   2. Untuk tiap `StableStudy`, susun payload dan POST ke `callback_url` (dari Pengaturan).
 *   3. Tanda tangan HMAC-SHA256 di header `X-Bridge-Signature` (kunci: `callback_key`).
 *
 * Jalankan terjadwal (lihat deploy/cron-pacs-notify), contoh:
 *   php tools/notify-studies.php --quiet
 *   php tools/notify-studies.php --dry-run          # lihat payload tanpa kirim
 *   php tools/notify-studies.php --latest           # kirim ulang study terbaru (uji)
 *   php tools/notify-studies.php --since=12345      # paksa mulai dari sequence tertentu
 */

require dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\Bridge;
use Simrs\Pacs\OrthancException;
use Simrs\Pacs\Settings;
use Simrs\Pacs\StudyService;

$options = getopt('', ['dry-run', 'latest', 'quiet', 'since::', 'limit::', 'max::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Penggunaan: php tools/notify-studies.php [opsi]

  --dry-run        tampilkan payload tanpa mengirim
  --latest         kirim payload study terbaru saja (uji callback)
  --since=SEQ      mulai dari sequence Orthanc tertentu (abaikan state)
  --limit=N        jumlah changes per halaman (default 100)
  --max=N          batas callback per eksekusi (default 200)
  --quiet          hanya cetak bila ada aktivitas atau error
  --help           tampilkan bantuan

TXT);
    exit(0);
}

$dryRun = isset($options['dry-run']);
$quiet = isset($options['quiet']);
$latest = isset($options['latest']);
$limit = isset($options['limit']) ? max(1, min(1000, (int) $options['limit'])) : 100;
$max = isset($options['max']) ? max(1, (int) $options['max']) : 200;

$out = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
};
$err = static function (string $line): void {
    fwrite(STDERR, $line . PHP_EOL);
};

$dataDir = dirname(__DIR__) . '/data';
$statePath = $dataDir . '/notify-state.json';
$lockPath = $dataDir . '/notify-studies.lock';
$logPath = $dataDir . '/callback.log';

$settings = Settings::default()->all();
$envUrl = getenv('PACS_CALLBACK_URL');
$envKey = getenv('PACS_CALLBACK_KEY');
$url = trim((string) ($envUrl !== false && $envUrl !== '' ? $envUrl : ($settings['callback_url'] ?? '')));
$key = (string) ($envKey !== false && $envKey !== '' ? $envKey : ($settings['callback_key'] ?? ''));

if ($url === '') {
    $err('callback_url belum diisi (dashboard → Pengaturan → panel API Bridge / Callback).');
    exit(2);
}
if ($key === '' && !$dryRun) {
    $err('callback_key belum diisi — callback tanpa tanda tangan HMAC tidak diizinkan.');
    exit(2);
}

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $out('lewati: proses notifikasi lain masih berjalan');
    exit(0);
}

$bridge = Bridge::fromSettingFile();
$client = $bridge->client();
$studySvc = $bridge->studies();

/**
 * @param array<string, mixed> $study
 * @return array<string, mixed>
 */
function buildPayload(array $study, StudyService $studySvc): array
{
    $studyId = (string) ($study['ID'] ?? '');
    $withModality = $studySvc->attachModalities([$study])[0] ?? $study;

    $images = 0;
    try {
        $images = count($studySvc->instances($studyId));
    } catch (Throwable $e) {
        // jumlah gambar bersifat informatif
    }

    return [
        'event'             => 'study.received',
        'sent_at'           => date('c'),
        'accession_number'  => (string) ($withModality['MainDicomTags']['AccessionNumber'] ?? ''),
        'patient_id'        => (string) ($withModality['PatientMainDicomTags']['PatientID'] ?? ''),
        'patient_name'      => (string) ($withModality['PatientMainDicomTags']['PatientName'] ?? ''),
        'study_id'          => $studyId,
        'study_instance_uid'=> (string) ($withModality['MainDicomTags']['StudyInstanceUID'] ?? ''),
        'study_date'        => (string) ($withModality['MainDicomTags']['StudyDate'] ?? ''),
        'modalities'        => array_values(array_filter(array_map(
            static fn ($m): string => strtoupper(trim((string) $m)),
            explode('/', (string) ($withModality['MainDicomTags']['Modality'] ?? ''))
        ))),
        'images'            => $images,
        'status'            => 'completed',
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function deliver(string $url, string $key, array $payload, bool $dryRun, string $logPath, bool $quiet): bool
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return false;
    }

    if ($dryRun) {
        fwrite(STDOUT, '[dry-run] POST ' . $url . PHP_EOL . $body . PHP_EOL);
        return true;
    }

    $signature = 'sha256=' . hash_hmac('sha256', $body, $key);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: pacs-bridge-notifier/1.0',
        'X-Bridge-Event: study.received',
        'X-Bridge-Key: ' . $key,
        'X-Bridge-Signature: ' . $signature,
    ];

    $attempt = 0;
    $status = 0;
    $error = '';
    do {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $response === false ? curl_error($ch) : '';
        curl_close($ch);

        $ok = $response !== false && $status >= 200 && $status < 300;
        if ($ok) {
            break;
        }
        usleep(500_000 * $attempt);
    } while ($attempt < 3);

    $log = [
        'ts'        => date('c'),
        'event'     => $payload['event'] ?? 'study.received',
        'accession' => $payload['accession_number'] ?? null,
        'study_id'  => $payload['study_id'] ?? null,
        'url'       => $url,
        'status'    => $status,
        'attempts'  => $attempt,
        'ok'        => $status >= 200 && $status < 300,
        'error'     => $error,
    ];
    $line = json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        @file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    if (!$quiet) {
        fwrite(STDOUT, sprintf(
            "%s %s -> HTTP %d%s\n",
            $log['ok'] ? '[OK]' : '[GAGAL]',
            (string) ($payload['accession_number'] ?? '-'),
            $status,
            $error !== '' ? ' (' . $error . ')' : ''
        ));
    }

    return (bool) $log['ok'];
}

$readState = static function () use ($statePath): array {
    if (!is_file($statePath)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($statePath), true);

    return is_array($data) ? $data : [];
};

$writeState = static function (array $state) use ($statePath): void {
    @file_put_contents(
        $statePath,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
};

$sent = 0;
$failed = 0;

try {
    if ($latest) {
        $recent = $studySvc->recent(1);
        if ($recent === []) {
            $out('tidak ada study di Orthanc');
            exit(0);
        }
        $payload = buildPayload($recent[0], $studySvc);
        $ok = deliver($url, $key, $payload, $dryRun, $logPath, $quiet);
        exit($ok || $dryRun ? 0 : 1);
    }

    $state = $readState();
    $since = isset($options['since']) ? max(0, (int) $options['since']) : (int) ($state['last_seq'] ?? -1);

    if ($since < 0) {
        // Pertama kali: mulai dari posisi terakhir supaya tidak membanjiri SIMRS dengan studi lama.
        $since = $client->lastSequence();
        $writeState(['last_seq' => $since, 'initialized_at' => date('c')]);
        $out('state awal dibuat pada sequence ' . $since . ' (tidak ada callback untuk studi lama)');
        exit(0);
    }

    $pages = 0;
    $seen = [];
    do {
        $pages++;
        $result = $client->changes($since, $limit, true);
        $changes = $result['Changes'];
        $last = (int) $result['Last'];
        $done = (bool) $result['Done'];

        foreach ($changes as $change) {
            $seq = (int) ($change['Seq'] ?? $since);
            $since = max($since, $seq);

            if (($change['ChangeType'] ?? '') !== 'StableStudy') {
                continue;
            }

            $studyId = (string) ($change['ID'] ?? '');
            if ($studyId === '' || isset($seen[$studyId])) {
                continue;
            }
            $seen[$studyId] = true;

            if ($sent >= $max) {
                $out('batas --max=' . $max . ' tercapai, sisanya diproses pada jadwal berikutnya');
                break 2;
            }

            try {
                $study = $client->study($studyId);
            } catch (OrthancException $e) {
                $err('lewati study ' . ($change['ID'] ?? '?') . ': ' . $e->getMessage());
                continue;
            }

            $payload = buildPayload($study, $studySvc);
            $ok = deliver($url, $key, $payload, $dryRun, $logPath, $quiet);
            if ($ok) {
                $sent++;
                $writeState(['last_seq' => $since, 'updated_at' => date('c')]);
            } else {
                $failed++;
                break 2; // jangan lewati perubahan ini; dicoba lagi pada jadwal berikutnya
            }
        }

        if ($failed === 0) {
            $since = max($since, $last);
            $writeState(['last_seq' => $since, 'updated_at' => date('c')]);
        }
    } while (!$done && $pages < 20);
} catch (Throwable $e) {
    $err('notify-studies gagal: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$out(sprintf('selesai: %d callback terkirim, %d gagal', $sent, $failed));
exit($failed === 0 ? 0 : 1);
