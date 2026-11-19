<?php

declare(strict_types=1);

/**
 * Penarik study otomatis dari PACS sumber (default `SANTESRV1`) ke Orthanc lokal.
 *
 *   php tools/pull-santesrv1.php                      # study hari ini
 *   php tools/pull-santesrv1.php --date=20261112      # tanggal tertentu
 *   php tools/pull-santesrv1.php --dry-run            # hanya daftar, tidak menarik
 *   php tools/pull-santesrv1.php --source=SANTESRV1 --limit=20
 *
 * Dipasang sebagai cron tiap menit (near-realtime, lihat PACS-ORTHANC.md §14.3):
 *
 *   * * * * * php /opt/pacs-bridge/tools/pull-santesrv1.php --date=today --quiet >> /var/log/pacs-pull.log 2>&1
 *
 * Alur (sama dengan cron yang sudah berjalan di server):
 *   1. C-FIND Study Root ke PACS sumber: StudyDate = hari ini
 *   2. Study yang StudyInstanceUID-nya sudah ada di Orthanc dilewati
 *   3. Study baru di-C-GET (Orthanc jadi SCU) lalu job-nya ditunggu sampai selesai
 *
 * Aman dijalankan berulang: instance yang sudah ada tidak digandakan
 * (Orthanc `OverwriteInstances = false`).
 */

require_once dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\Bridge;
use Simrs\Pacs\OrthancClient;
use Simrs\Pacs\OrthancException;

/**
 * @param list<string> $argv
 * @return array{date: string, source: string, limit: int, timeout: int, dry_run: bool, quiet: bool}
 */
function parseOptions(array $argv): array
{
    $options = [
        'date'    => 'today',
        'source'  => getenv('PACS_PULL_SOURCE') ?: 'SANTESRV1',
        'limit'   => 0,
        'timeout' => 120,
        'dry_run' => false,
        'quiet'   => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run' || $arg === '-n') {
            $options['dry_run'] = true;
            continue;
        }
        if ($arg === '--quiet' || $arg === '-q') {
            $options['quiet'] = true;
            continue;
        }

        if (!preg_match('/^--(date|source|limit|timeout)=(.*)$/', $arg, $m)) {
            throw new InvalidArgumentException('Argumen tidak dikenal: ' . $arg);
        }

        $value = trim($m[2]);
        switch ($m[1]) {
            case 'date':
                $options['date'] = $value === '' ? 'today' : $value;
                break;
            case 'source':
                $options['source'] = $value;
                break;
            case 'limit':
                $options['limit'] = max(0, (int) $value);
                break;
            case 'timeout':
                $options['timeout'] = max(10, (int) $value);
                break;
        }
    }

    return $options;
}

function resolveDate(string $value): string
{
    $normalized = strtolower($value);

    if ($normalized === 'today') {
        return date('Ymd');
    }
    if ($normalized === 'yesterday') {
        return date('Ymd', strtotime('-1 day') ?: time());
    }
    if (preg_match('/^\d{8}$/', $value) === 1) {
        return $value;
    }

    throw new InvalidArgumentException('Format --date harus today|yesterday|YYYYMMDD, diberi: ' . $value);
}

function logLine(string $message): void
{
    printf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
}

/**
 * Cegah dua proses berjalan bersamaan (cron tiap menit vs susulan per jam).
 *
 * Lock ditunggu sebentar, bukan langsung menyerah: dua jadwal cron bisa
 * berbunyi pada detik yang sama (mis. `* * * * *` dan `5 * * * *`), dan
 * penarikan normal hanya butuh < 2 detik, jadi menunggu membuat jadwal kedua
 * tetap berjalan alih-alih selalu terlewati.
 *
 * @param int $waitSeconds batas tunggu sebelum dianggap gagal
 * @return resource|null null bila proses lain masih berjalan melewati batas
 */
function acquireLock(int $waitSeconds = 30)
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $dir = sys_get_temp_dir();
    }

    $handle = fopen($dir . '/pull-santesrv1.lock', 'c');
    if ($handle === false) {
        return null;
    }

    $deadline = microtime(true) + $waitSeconds;
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    fclose($handle);

    return null;
}

/**
 * @param array<string, array<string, mixed>> $tags
 */
function tagValue(array $tags, string $tag): string
{
    return (string) ($tags[$tag]['Value'] ?? '');
}

/**
 * Jumlah instance yang benar-benar diterima pada satu job C-GET.
 *
 * @param array<string, mixed> $job
 */
function receivedInstances(array $job): int
{
    $total = 0;
    foreach ((array) ($job['Content']['Details'] ?? []) as $detail) {
        $total += count((array) ($detail['ReceivedInstancesIds'] ?? []));
    }

    return $total;
}

/**
 * Status DIMSE (0 = sukses) dari job C-GET; job bisa "Success" tapi DIMSE-nya gagal.
 *
 * @param array<string, mixed> $job
 */
function dimseStatus(array $job): int
{
    $status = 0;
    foreach ((array) ($job['Content']['Details'] ?? []) as $detail) {
        $status = max($status, (int) ($detail['DimseErrorStatus'] ?? 0));
    }

    return $status;
}

$options = parseOptions($argv);
$date = resolveDate($options['date']);
$source = $options['source'];
$quiet = $options['quiet'];
$startedAt = microtime(true);

$bridge = Bridge::fromSettingFile();
/** @var OrthancClient $client */
$client = $bridge->client();

$lock = acquireLock();
if ($lock === null) {
    logLine('lewati: proses tarikan lain masih berjalan (lock aktif > 30 detik)');
    exit(0);
}

$queryId = '';
$pulled = 0;
$skipped = 0;
$failed = 0;
$instances = 0;
$processed = 0;

try {
    if (!$quiet) {
        logLine(sprintf(
            'mulai tarikan: sumber=%s tanggal=%s orthanc=%s%s',
            $source,
            $date,
            $client->baseUrl(),
            $options['dry_run'] ? ' (dry-run)' : ''
        ));
    }

    $queryId = $client->modalityQuery($source, ['StudyDate' => $date]);
    if ($queryId === '') {
        throw new RuntimeException('Orthanc tidak mengembalikan ID query C-FIND');
    }

    $answers = $client->queryAnswers($queryId);
    if (!$quiet) {
        logLine(sprintf('%s melaporkan %d study pada %s', $source, count($answers), $date));
    }

    foreach ($answers as $index) {
        $tags = $client->queryAnswerContent($queryId, $index);
        $uid = tagValue($tags, '0020,000d');
        $accession = tagValue($tags, '0008,0050');
        $patientId = tagValue($tags, '0010,0020');

        if ($uid === '') {
            logLine('lewat: jawaban C-FIND tanpa StudyInstanceUID');
            continue;
        }

        if ($client->find(['StudyInstanceUID' => $uid], 'Study', 1) !== []) {
            $skipped++;
            continue;
        }

        if ($options['limit'] > 0 && $processed >= $options['limit']) {
            logLine(sprintf('batas --limit=%d tercapai, sisa study ditunda ke tarikan berikutnya', $options['limit']));
            break;
        }
        $processed++;

        if ($options['dry_run']) {
            logLine(sprintf('baru (belum di tarik): acc=%s pid=%s uid=%s', $accession, $patientId, $uid));
            continue;
        }

        try {
            $jobId = $client->modalityGet(
                $source,
                [['StudyInstanceUID' => $uid]],
                'Study',
                min($options['timeout'], 60)
            );

            $job = $client->waitJob($jobId, $options['timeout']);
            $state = (string) ($job['State'] ?? '');
            $errorCode = (int) ($job['ErrorCode'] ?? -1);
            $dimse = dimseStatus($job);

            if ($state === 'Success' && $errorCode === 0 && $dimse === 0) {
                $received = receivedInstances($job);
                $instances += $received;
                $pulled++;
                logLine(sprintf('ditarik: acc=%s pid=%s instance=%d', $accession, $patientId, $received));
            } else {
                $failed++;
                logLine(sprintf(
                    'GAGAL: acc=%s pid=%s state=%s error=%d dimse=0x%04X (%s)',
                    $accession,
                    $patientId,
                    $state,
                    $errorCode,
                    $dimse,
                    (string) ($job['ErrorDescription'] ?? '')
                ));
            }
        } catch (Throwable $e) {
            $failed++;
            $detail = $e instanceof OrthancException && $e->responseBody !== ''
                ? ' | ' . substr($e->responseBody, 0, 300)
                : '';
            logLine(sprintf('GAGAL: acc=%s pid=%s %s%s', $accession, $patientId, $e->getMessage(), $detail));
        }
    }
} catch (Throwable $e) {
    logLine('error: ' . $e->getMessage());
    $failed++;
} finally {
    if ($queryId !== '') {
        try {
            $client->deleteQuery($queryId);
        } catch (Throwable $e) {
            logLine('peringatan: gagal hapus query ' . $queryId . ' (' . $e->getMessage() . ')');
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

if (!$quiet || $pulled > 0 || $failed > 0) {
    logLine(sprintf(
        'ringkasan: tanggal=%s sumber=%s baru=%d dilewati=%d gagal=%d instance=%d durasi=%.1fs',
        $date,
        $source,
        $pulled,
        $skipped,
        $failed,
        $instances,
        microtime(true) - $startedAt
    ));
}

exit($failed === 0 ? 0 : 1);
