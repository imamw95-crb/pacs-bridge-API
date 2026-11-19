<?php

declare(strict_types=1);

/**
 * Self-test alur order lewat Bridge API:
 * idempotensi AccessionNumber, konflik 409, status order, error 404, dan audit.
 *
 *   php tools/test-order-flow.php http://127.0.0.1:8081 <API_KEY>
 *
 * Membuat satu order uji lalu **menghapusnya** di akhir (aman diulang).
 */

require dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\BridgeApiClient;
use Simrs\Pacs\BridgeApiException;

$baseUrl = $argv[1] ?? '';
$apiKey = $argv[2] ?? '';

if ($baseUrl === '' || $apiKey === '') {
    fwrite(STDERR, "Penggunaan: php tools/test-order-flow.php <baseUrl> <apiKey>\n");
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

/**
 * @param callable(): void $fn
 */
function expectHttpStatus(int $expected, callable $fn): string
{
    try {
        $fn();
    } catch (BridgeApiException $e) {
        if ($e->statusCode === $expected) {
            return 'HTTP ' . $expected . ' (sesuai harapan)';
        }
        throw new RuntimeException('dapat HTTP ' . $e->statusCode . ', seharusnya ' . $expected);
    }

    throw new RuntimeException('tidak gagal, seharusnya HTTP ' . $expected);
}

$api = new BridgeApiClient($baseUrl, $apiKey, 60);
$accession = 'QA-IDEM-' . date('His');
$createdId = null;

echo "=== Bridge API: {$baseUrl} ===\n";
echo 'Accession uji: ' . $accession . "\n\n";

try {
    $order = [
        'patient_name'     => 'QA IDEMPOTENT',
        'title'            => 'Tn.',
        'patient_id'       => 'QA-0001',
        'accession_number' => $accession,
        'procedure'        => 'QA THORAX',
        'modality'         => 'DX',
    ];

    check('create order (pertama) -> 201/created', static function () use ($api, &$order, &$createdId): string {
        $result = $api->createWorklist($order);
        $createdId = (string) ($result['id'] ?? '');
        if (empty($result['created']) || $createdId === '') {
            throw new RuntimeException('created=false / id kosong: ' . json_encode($result));
        }

        return 'id=' . $createdId;
    });

    check('kirim ulang accession sama -> order sama', static function () use ($api, &$order, &$createdId): string {
        $again = $api->createWorklist($order);
        if (!empty($again['created'])) {
            throw new RuntimeException('order dibuat ganda (created=true)');
        }
        if ((string) ($again['id'] ?? '') !== $createdId) {
            throw new RuntimeException('id berbeda: ' . $again['id']);
        }

        return 'id sama, created=false';
    });

    check('accession sama, pasien lain -> 409', static function () use ($api, &$order): string {
        $conflict = $order;
        $conflict['patient_id'] = 'QA-9999';
        $conflict['patient_name'] = 'PASIEN LAIN';

        return expectHttpStatus(409, static fn () => $api->createWorklist($conflict));
    });

    check('status order -> sent', static function () use ($api, $accession): string {
        $status = $api->orderStatus($accession);
        if (($status['status'] ?? '') !== 'sent') {
            throw new RuntimeException('status=' . ($status['status'] ?? '?'));
        }

        return 'status=sent, label="' . ($status['label'] ?? '') . '"';
    });

    check('hapus order -> status cancelled', static function () use ($api, &$createdId, $accession): string {
        $api->deleteWorklist($createdId);
        $createdId = null;
        $status = $api->orderStatus($accession);
        if (($status['status'] ?? '') !== 'cancelled') {
            throw new RuntimeException('status=' . ($status['status'] ?? '?'));
        }

        return 'status=cancelled, timeline terisi';
    });

    check('status accession tak dikenal -> 404', static function () use ($api): string {
        return expectHttpStatus(404, static fn () => $api->orderStatus('QA-TIDAK-ADA-9'));
    });

    check('gambar study tak dikenal -> null', static function () use ($api): string {
        $img = $api->jpegByAccession('QA-TIDAK-ADA-9', 60);
        if ($img !== null) {
            throw new RuntimeException('seharusnya null');
        }

        return 'null (404 dipetakan ke null)';
    });

    check('GET /audit dengan API key -> 403', static function () use ($baseUrl, $apiKey): string {
        $ch = curl_init(rtrim($baseUrl, '/') . '/audit?limit=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $apiKey],
            CURLOPT_TIMEOUT        => 20,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status !== 403) {
            throw new RuntimeException('HTTP ' . $status . ', seharusnya 403');
        }

        return 'HTTP 403 (hanya sesi manager)';
    });
} finally {
    if ($createdId !== null) {
        try {
            $api->deleteWorklist($createdId);
            echo '(bersih-bersih: order uji dihapus)' . PHP_EOL;
        } catch (Throwable $e) {
            fwrite(STDERR, 'PERINGATAN: gagal menghapus order uji ' . $createdId . ': ' . $e->getMessage() . PHP_EOL);
        }
    }
}

echo "\n=== Ringkasan: {$pass} pass, {$fail} fail ===\n";
exit($fail === 0 ? 0 : 1);
