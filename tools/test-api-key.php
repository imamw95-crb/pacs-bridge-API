<?php

declare(strict_types=1);

/**
 * Uji pemakaian API key pada Bridge API, memakai Simrs\Pacs\BridgeApiClient.
 *
 *   php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>
 *
 * Bila API_KEY kosong, hanya pengujian "tanpa key harus 401".
 */

require_once dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\BridgeApiClient;
use Simrs\Pacs\BridgeApiException;

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8081';
$apiKey = $argv[2] ?? '';

$pass = 0;
$fail = 0;

function check(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $info = $fn();
        $pass++;
        printf("[PASS] %-46s %s\n", $label, (string) $info);
    } catch (Throwable $e) {
        $fail++;
        printf("[FAIL] %-46s %s\n", $label, $e->getMessage());
    }
}

function expectStatus(int $expected, callable $fn): string
{
    try {
        $fn();
    } catch (BridgeApiException $e) {
        if ($e->statusCode === $expected) {
            return 'HTTP ' . $e->statusCode . ' (sesuai harapan)';
        }
        throw new RuntimeException('Status ' . $e->statusCode . ', diharapkan ' . $expected);
    }

    throw new RuntimeException('Tidak melempar exception, diharapkan HTTP ' . $expected);
}

echo "=== Bridge API: $baseUrl ===\n";
echo 'API key: ' . ($apiKey === '' ? '(kosong)' : substr($apiKey, 0, 8) . '…') . "\n\n";

$noKey = new BridgeApiClient($baseUrl, null);

check('tanpa API key -> 401', static function () use ($noKey): string {
    return expectStatus(401, static fn () => $noKey->health());
});

if ($apiKey !== '') {
    $api = new BridgeApiClient($baseUrl, $apiKey);

    check('health() dengan API key', static function () use ($api): string {
        $h = $api->health();

        return sprintf('Orthanc %s, %s study', $h['version'] ?? '?', $h['count_studies'] ?? '?');
    });

    check('worklists() dengan API key', static function () use ($api): string {
        return count($api->worklists()) . ' order';
    });

    check('recentStudies(today) dengan API key', static function () use ($api): string {
        $res = $api->recentStudies('today', 3);
        $hint = count($res['studies']) === 3 ? '3 study' : count($res['studies']) . ' study';

        return 'tanggal ' . $res['date'] . ', ' . $hint;
    });

    check('settings() dengan API key', static function () use ($api): string {
        $s = $api->settings();

        return 'base_url=' . ($s['effective']['base_url'] ?? '?');
    });

    check('PUT settings dengan API key -> 403', static function () use ($baseUrl, $apiKey): string {
        // tidak ada helper PUT settings di client (memang sengaja butuh sesi);
        // uji lewat request mentah
        $ch = curl_init(rtrim($baseUrl, '/') . '/settings');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
            CURLOPT_POSTFIELDS     => '{"study_filter":"all"}',
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 403) {
            throw new RuntimeException('Status ' . $status . ', diharapkan 403 (butuh sesi login)');
        }

        return 'HTTP 403 (benar, butuh sesi)';
    });

    check('gambar PNG (width=200) dengan API key', static function () use ($api): string {
        $studies = $api->recentStudies('all', 1);
        if (($studies['studies'] ?? []) === []) {
            return 'tidak ada study (lewati)';
        }
        $acc = $studies['studies'][0]['MainDicomTags']['AccessionNumber'] ?? '';
        if ($acc === '') {
            return 'study tanpa accession (lewati)';
        }

        $img = $api->pngByAccession($acc, 200);
        if ($img === null || !str_starts_with($img['content'], "\x89PNG")) {
            throw new RuntimeException('PNG tidak valid');
        }

        return strlen($img['content']) . ' byte PNG (' . $img['filename'] . ')';
    });

    check('API key salah -> 401', static function () use ($baseUrl): string {
        $bad = new BridgeApiClient($baseUrl, 'kunci-yang-salah');

        return expectStatus(401, static fn () => $bad->health());
    });
}

echo "\n=== Ringkasan: {$pass} pass, {$fail} fail ===\n";
exit($fail === 0 ? 0 : 1);
