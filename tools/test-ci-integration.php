<?php

declare(strict_types=1);

/**
 * Harness uji integrasi CodeIgniter tanpa framework.
 *
 * Mensimulasikan cara CI memanggil library, memakai APPPATH + config array.
 *
 *   php tools/test-ci-integration.php
 */

require_once dirname(__DIR__) . '/src/autoload.php';

// Simulasikan konstanta CI4 & CI3
define('APPPATH', dirname(__DIR__) . '/integrations/codeigniter/ci4/app/');
define('FCPATH', dirname(__DIR__) . '/public/');
define('BASEPATH', dirname(__DIR__) . '/integrations/codeigniter/ci3/system/');

$pass = 0;
$fail = 0;

function check(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $info = $fn();
        $pass++;
        printf("[PASS] %-44s %s\n", $label, (string) $info);
    } catch (Throwable $e) {
        $fail++;
        printf("[FAIL] %-44s %s\n", $label, $e->getMessage());
    }
}

// ---------- CI4 style: App\Libraries\Pacs ----------
require_once __DIR__ . '/../integrations/codeigniter/ci4/app/Libraries/Pacs.php';

$ci4Config = [
    'pacsRoot'   => dirname(__DIR__),
    'serverIp'   => '10.4.0.10',
    'httpPort'   => 8042,
    'dicomPort'  => 4242,
    'calledAet'  => 'ORTHANC',
    'callingAet' => 'LEAD_CLIENT',
    'modality'   => 'DX',
];

check('CI4 library: konstruksi + health', static function () use ($ci4Config): string {
    $pacs = new App\Libraries\Pacs($ci4Config);
    $health = $pacs->health();

    return sprintf(
        'Orthanc %s | %s study',
        $health['version'] ?? '?',
        $health['count_studies'] ?? '?'
    );
});

check('CI4 library: createOrder + updateOrder + deleteOrder', static function () use ($ci4Config): string {
    $pacs = new App\Libraries\Pacs($ci4Config);
    $created = $pacs->createOrder([
        'patient_name'     => 'CI TEST',
        'title'            => 'Ny.',
        'patient_id'       => 'CI-TEST-01',
        'accession_number' => 'ACC-CI-' . date('His'),
        'procedure'        => 'CI TEST THORAX',
    ]);

    $updated = $pacs->updateOrder($created['id'], [
        'patient_name'     => 'CI TEST',
        'title'            => 'Ny.',
        'patient_id'       => 'CI-TEST-01',
        'accession_number' => $created['accession_number'],
        'procedure'        => 'CI TEST THORAX ULANG',
    ]);
    if ($updated['id'] !== $created['id']) {
        throw new RuntimeException('updateOrder mengembalikan id berbeda');
    }

    $pacs->deleteOrder($created['id']);

    return 'id=' . $created['id'] . ' nama="' . $created['patient_name'] . '" (create+update+delete)';
});

check('CI4 library: pngByAccession', static function () use ($ci4Config): string {
    $pacs = new App\Libraries\Pacs($ci4Config);
    $studies = $pacs->findStudies('A261105038');
    if ($studies === []) {
        return 'study tidak ada di server ini (lewati)';
    }
    $png = $pacs->pngByAccession('A261105038', 160);
    if ($png === null || !str_starts_with($png['content'], "\x89PNG")) {
        throw new RuntimeException('PNG tidak valid');
    }

    return strlen($png['content']) . ' byte PNG';
});

check('CI4 library: nextAccession + recentStudies', static function () use ($ci4Config): string {
    $pacs = new App\Libraries\Pacs($ci4Config);
    $accession = $pacs->nextAccession();
    if (preg_match('/^\d{8,16}$/', $accession) !== 1) {
        throw new RuntimeException('format accession tidak valid: ' . $accession);
    }
    $recent = $pacs->recentStudies('today', 3);
    $byName = $pacs->findStudiesByName('TES', 5);

    return 'acc=' . $accession . ', ' . count($recent) . ' study hari ini, ' . count($byName) . ' by nama';
});

check('CI4 library: zipJpgByAccession + zipByAccession', static function () use ($ci4Config): string {
    $pacs = new App\Libraries\Pacs($ci4Config);
    $jpgZip = $pacs->zipJpgByAccession('A261105038', 80, false);
    if ($jpgZip === null || !str_starts_with($jpgZip['content'], "PK")) {
        throw new RuntimeException('ZIP JPG tidak valid');
    }
    $dicomZip = $pacs->zipByAccession('A261105038');
    if ($dicomZip === null || !str_starts_with($dicomZip, "PK")) {
        throw new RuntimeException('ZIP DICOM tidak valid');
    }

    return $jpgZip['filename'] . ' (' . strlen($jpgZip['content']) . 'B), DICOM ' . strlen($dicomZip) . 'B';
});

// ---------- CI3 style: Pacs_bridge ----------
if (!function_exists('log_message')) {
    function log_message(string $level, string $message): void
    {
    }
}
if (!function_exists('show_error')) {
    function show_error(string $message): void
    {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../integrations/codeigniter/ci3/application/libraries/Pacs_bridge.php';

check('CI3 library: konstruksi + health', static function (): string {
    $lib = new Pacs_bridge([
        'pacs_root'   => dirname(__DIR__),
        'server_ip'   => '10.4.0.10',
        'http_port'   => 8042,
        'called_aet'  => 'ORTHANC',
        'calling_aet' => 'LEAD_CLIENT',
        'modality'    => 'DX',
    ]);
    $health = $lib->health();

    return sprintf('reachable=%s, %s study', $health['reachable'] ? 'yes' : 'no', $health['count_studies'] ?? '?');
});

check('CI3 library: create_order + delete_order', static function (): string {
    $lib = new Pacs_bridge([
        'pacs_root'   => dirname(__DIR__),
        'server_ip'   => '10.4.0.10',
        'http_port'   => 8042,
    ]);
    $created = $lib->create_order([
        'patient_name'     => 'CI3 TEST',
        'title'            => 'Tn.',
        'patient_id'       => 'CI3-TEST-01',
        'accession_number' => 'ACC-CI3-' . date('His'),
        'procedure'        => 'CI3 TEST CHEST',
    ]);
    if ($created === false) {
        throw new RuntimeException('create_order gagal: ' . $lib->error_message());
    }
    $ok = $lib->delete_order($created['id']);
    if (!$ok) {
        throw new RuntimeException('delete_order gagal: ' . $lib->error_message());
    }

    return 'id=' . $created['id'] . ' (dibuat & dihapus)';
});

check('CI3 library: orders + find_studies', static function (): string {
    $lib = new Pacs_bridge([
        'pacs_root'   => dirname(__DIR__),
        'server_ip'   => '10.4.0.10',
        'http_port'   => 8042,
    ]);
    $orders = $lib->orders();
    $studies = $lib->find_studies('A261105038');
    if ($lib->error_message() !== '') {
        throw new RuntimeException($lib->error_message());
    }

    return count($orders) . ' order, ' . count($studies) . ' study';
});

check('CI3 library: next_accession + recent_studies + zip_jpg', static function (): string {
    $lib = new Pacs_bridge([
        'pacs_root'   => dirname(__DIR__),
        'server_ip'   => '10.4.0.10',
        'http_port'   => 8042,
    ]);
    $accession = $lib->next_accession();
    if ($accession === false || preg_match('/^\d{8,16}$/', $accession) !== 1) {
        throw new RuntimeException('next_accession gagal: ' . $lib->error_message());
    }

    $recent = $lib->recent_studies('today', 3);
    $zip = $lib->zip_jpg_by_accession('A261105038', 80, false);
    if ($lib->error_message() !== '') {
        throw new RuntimeException($lib->error_message());
    }
    if ($zip === null || !str_starts_with($zip['content'], 'PK')) {
        throw new RuntimeException('ZIP JPG tidak valid');
    }

    return 'acc=' . $accession . ', ' . count($recent) . ' study, zip ' . strlen($zip['content']) . 'B';
});

// ---------- Mode API: Bridge API + X-API-Key (opsional) ----------
$bridgeUrl = (string) getenv('PACS_BRIDGE_TEST_URL');
$bridgeKey = (string) getenv('PACS_BRIDGE_TEST_KEY');

if ($bridgeUrl !== '' && $bridgeKey !== '') {
    require_once __DIR__ . '/../integrations/codeigniter/ci4/app/Libraries/PacsApi.php';
    require_once __DIR__ . '/../integrations/codeigniter/ci3/application/libraries/Pacs_api.php';

    $apiCfg = [
        'pacsRoot'      => dirname(__DIR__),
        'bridgeUrl'     => $bridgeUrl,
        'apiKey'        => $bridgeKey,
        'bridgeTimeout' => 60,
    ];

    check('CI4 PacsApi: health + me', static function () use ($apiCfg): string {
        $api = new App\Libraries\PacsApi($apiCfg);
        $health = $api->health();
        $me = $api->me();
        if (($me['via'] ?? '') !== 'api-key') {
            throw new RuntimeException('kredensial bukan api-key');
        }

        return 'Orthanc ' . ($health['version'] ?? '?') . ', via=' . $me['via'];
    });

    check('CI4 PacsApi: nextAccession + recent + zipJpg', static function () use ($apiCfg): string {
        $api = new App\Libraries\PacsApi($apiCfg);
        $accession = $api->nextAccession();
        if (preg_match('/^\d{8,16}$/', $accession) !== 1) {
            throw new RuntimeException('format accession tidak valid: ' . $accession);
        }
        $recent = $api->recentStudies('today', 3);
        $zip = $api->zipJpgByAccession('A261105038', 80, false);
        if ($zip === null || !str_starts_with($zip['content'], 'PK')) {
            throw new RuntimeException('ZIP JPG tidak valid');
        }

        return 'acc=' . $accession . ', ' . count($recent['studies'] ?? []) . ' study, zip ' . strlen($zip['content']) . 'B';
    });

    check('CI3 Pacs_api: health + next_accession + zip_jpg', static function () use ($bridgeUrl, $bridgeKey): string {
        $lib = new Pacs_api([
            'pacs_root'      => dirname(__DIR__),
            'bridge_url'     => $bridgeUrl,
            'api_key'        => $bridgeKey,
            'bridge_timeout' => 60,
        ]);
        $health = $lib->health();
        $accession = $lib->next_accession();
        $zip = $lib->zip_jpg_by_accession('A261105038', 80, false);

        if ($accession === false || $lib->error_message() !== '') {
            throw new RuntimeException('next_accession gagal: ' . $lib->error_message());
        }
        if ($zip === null || !str_starts_with($zip['content'], 'PK')) {
            throw new RuntimeException('ZIP JPG tidak valid: ' . $lib->error_message());
        }

        return 'reachable=' . (!empty($health['reachable']) ? 'ya' : 'tidak') . ', acc=' . $accession;
    });
} else {
    echo "[SKIP] Mode API: set PACS_BRIDGE_TEST_URL & PACS_BRIDGE_TEST_KEY untuk menguji PacsApi/Pacs_api\n";
}

echo "\n=== Ringkasan: {$pass} pass, {$fail} fail ===\n";
exit($fail === 0 ? 0 : 1);
