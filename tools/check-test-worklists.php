<?php
require_once __DIR__ . '/../src/autoload.php';
use Simrs\Pacs\Bridge;

$bridge = Bridge::fromSettingFile();
$w = $bridge->worklist();
$list = $bridge->client()->worklists();

echo "Daftar Worklist Uji:\n";
foreach ($list as $item) {
    $acc = $item['Tags']['AccessionNumber'] ?? '';
    if (strpos($acc, 'TEST-CT-') === 0 || strpos($acc, 'QA-') === 0) {
        echo "Menghapus: " . $item['ID'] . ' | ' . $acc . "\n";
        $bridge->worklist()->delete($item['ID']);
    }
}
