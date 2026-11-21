<?php
require_once __DIR__ . '/../src/autoload.php';
use Simrs\Pacs\Bridge;

try {
    $bridge = Simrs\Pacs\Bridge::fromSettingFile();
    $res = $bridge->worklist()->create([
        'accession_number' => 'TEST-CT-' . date('His'),
        'patient_id' => '999888',
        'patient_name' => 'PASIEN CT UJI',
        'birth_date' => '19850505',
        'sex' => 'M',
        'modality' => 'CT',
        'station_aet' => 'CT140643',
        'study_description' => 'CT Scan Kepala Uji',
        'procedure' => 'CT_HEAD'
    ]);
    echo "BERHASIL buat worklist CT:\n";
    print_r($res);
} catch (Exception $e) {
    echo "GAGAL: " . $e->getMessage() . "\n";
}
