<?php

declare(strict_types=1);

/**
 * Self-test bridge SIMRS <-> Orthanc.
 *
 *   php tools/selftest.php
 *
 * Yang diuji:
 *   1. Koneksi REST (/system, /statistics)
 *   2. CRUD worklist (entry uji dibuat lalu dihapus)
 *   3. Pencarian study + render gambar (PNG kecil)
 *
 * Tidak menyimpan data pasien baru dan tidak mengubah study yang ada.
 */

require_once dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\Bridge;
use Simrs\Pacs\OrthancException;

$pass = 0;
$fail = 0;

function check(string $label, callable $fn): void
{
    global $pass, $fail;

    try {
        $info = $fn();
        $pass++;
        printf("[PASS] %-46s %s\n", $label, $info === null ? '' : $info);
    } catch (Throwable $e) {
        $fail++;
        printf("[FAIL] %-46s %s\n", $label, $e->getMessage());
        if ($e instanceof OrthancException && $e->responseBody !== '') {
            printf("       -> %s\n", substr($e->responseBody, 0, 300));
        }
    }
}

$bridge = Bridge::fromSettingFile();
$config = $bridge->config();

echo "=== Konfigurasi ===\n";
printf("base_url   : %s\n", $config['base_url']);
printf("dicom AET  : %s @ %s:%d\n", $config['called_aet'], $config['server_ip'], $config['dicom_port']);
printf("calling AET: %s (modality %s)\n", $config['calling_aet'], $config['modality']);
echo "\n=== Uji ===\n";

check('1. REST Orthanc /system', static function () use ($bridge): string {
    $health = $bridge->health();

    return sprintf('Orthanc %s, DICOM AET %s:%s', $health['version'], $health['dicom_aet'], $health['dicom_port']);
});

check('2. Statistik study', static function () use ($bridge): string {
    $health = $bridge->health();

    return sprintf('%s study / %s pasien', $health['count_studies'], $health['count_patients']);
});

$createdId = null;

check('3. Worklist: create', static function () use ($bridge, &$createdId): string {
    $result = $bridge->worklist()->create([
        'patient_name'     => 'SELFTEST BRIDGE',
        'title'            => 'Tn.',
        'patient_id'       => 'SELFTEST-001',
        'accession_number' => 'ACC-SELFTEST-' . date('His'),
        'procedure'        => 'SELFTEST CHEST PA',
    ]);
    $createdId = $result['id'];
    if ($createdId === '') {
        throw new RuntimeException('ID worklist kosong');
    }

    return 'id=' . $createdId . ' nama="' . $result['patient_name'] . '"';
});

check('4. Worklist: terlihat di daftar', static function () use ($bridge, &$createdId): string {
    foreach ($bridge->worklist()->all() as $item) {
        if (($item['ID'] ?? '') === $createdId) {
            $tags = $item['Tags'] ?? [];

            return 'PatientName="' . ($tags['PatientName'] ?? '') . '"';
        }
    }

    throw new RuntimeException('Entry yang baru dibuat tidak ditemukan di /worklists');
});

check('5. Worklist: delete (bersih-bersih)', static function () use ($bridge, &$createdId): string {
    $bridge->worklist()->delete((string) $createdId);
    foreach ($bridge->worklist()->all() as $item) {
        if (($item['ID'] ?? '') === $createdId) {
            throw new RuntimeException('Entry masih ada setelah DELETE');
        }
    }

    return 'entry uji dihapus';
});

check('6. Cari study terbaru + render PNG', static function () use ($bridge): string {
    $studies = $bridge->client()->find(['PatientID' => '*'], 'Study', 1, true);
    if ($studies === []) {
        return 'belum ada study di PACS (lewati)';
    }

    $studyId = (string) ($studies[0]['ID'] ?? '');
    $ref = $bridge->studies()->firstInstance($studyId);
    if ($ref === null) {
        throw new RuntimeException('Study tanpa instance');
    }

    $png = $bridge->studies()->renderPng($ref['instance_id'], 200);
    if ($png === '' || !str_starts_with($png, "\x89PNG")) {
        throw new RuntimeException('Render PNG tidak valid');
    }

    return sprintf('%d byte PNG (instance %s)', strlen($png), substr($ref['instance_id'], 0, 12) . '…');
});

/**
 * Regresi: `/studies/recent` harus benar-benar study terbaru.
 * Orthanc tanpa OrderBy mengembalikan urutan internal DB, sehingga study
 * terbaru bisa hilang bila sort dilakukan setelah Limit.
 */
check('7. Study terbaru: urut tanggal+jam menurun', static function () use ($bridge): string {
    $all = $bridge->studies()->recent(5, null);
    $today = $bridge->studies()->recent(3, date('Ymd'));

    foreach ([$all, $today] as $list) {
        $keys = [];
        foreach ($list as $study) {
            $keys[] = (string) ($study['MainDicomTags']['StudyDate'] ?? '')
                . (string) ($study['MainDicomTags']['StudyTime'] ?? '');
        }
        $sorted = $keys;
        rsort($sorted);
        if ($keys !== $sorted) {
            throw new RuntimeException('Urutan study tidak menurun: ' . implode(', ', $keys));
        }
    }

    return sprintf(
        'terbaru=%s %s',
        (string) ($all[0]['MainDicomTags']['StudyDate'] ?? '-'),
        substr((string) ($all[0]['MainDicomTags']['StudyTime'] ?? ''), 0, 6)
    );
});

/**
 * Regresi: nama file di dalam ZIP JPG harus unik.
 * Study dengan beberapa seri sering punya SeriesNumber sama ("1") dan
 * InstanceNumber berulang, sehingga nama `1-001.jpg` bisa bentrok dan
 * gambar tertimpa saat diekstrak.
 */
check('8. ZIP JPG: nama file unik (multi-series)', static function () use ($bridge): string {
    foreach ($bridge->studies()->recent(60, null) as $study) {
        if (count((array) ($study['Series'] ?? [])) < 2) {
            continue;
        }

        $accession = (string) ($study['MainDicomTags']['AccessionNumber'] ?? '');
        $archive = $bridge->studies()->imagesByAccession($accession, 60, false);
        $names = array_column($archive['files'], 'name');
        if ($names === []) {
            continue;
        }

        if (count($names) !== count(array_unique($names))) {
            throw new RuntimeException('Nama file ZIP duplikat pada ' . $accession . ': ' . implode(', ', $names));
        }

        return sprintf('%s: %d file unik (%s)', $accession, count($names), implode(', ', $names));
    }

    return 'tidak ada study multi-series (lewati)';
});

echo "\n=== Ringkasan: {$pass} pass, {$fail} fail ===\n";
exit($fail === 0 ? 0 : 1);
