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

check('CI4 library: createOrder + deleteOrder', static function () use ($ci4Config): string {
    $pacs = new App\Libraries\Pacs($ci4Config);
    $created = $pacs->createOrder([
        'patient_name'     => 'CI TEST',
        'title'            => 'Ny.',
        'patient_id'       => 'CI-TEST-01',
        'accession_number' => 'ACC-CI-' . date('His'),
        'procedure'        => 'CI TEST THORAX',
    ]);
    $pacs->deleteOrder($created['id']);

    return 'id=' . $created['id'] . ' nama="' . $created['patient_name'] . '" (dihapus)';
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

echo "\n=== Ringkasan: {$pass} pass, {$fail} fail ===\n";
exit($fail === 0 ? 0 : 1);
