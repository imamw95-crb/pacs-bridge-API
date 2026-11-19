<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi bridge PACS (CodeIgniter 4).
 *
 * Nilai bisa dioverride dari file .env, contoh:
 *   pacs.serverIp = 10.4.0.10
 *   pacs.httpPort = 8042
 *   pacs.apiKey   = rahasia
 */
class Pacs extends BaseConfig
{
    /**
     * Lokasi folder SDK (berisi src/autoload.php).
     * Kosongkan untuk mencari otomatis di APPPATH/ThirdParty/pacs.
     */
    public string $pacsRoot = '';

    public string $serverIp  = '10.4.0.10';
    public int $httpPort     = 8042;
    public int $dicomPort    = 4242;
    public string $calledAet = 'ORTHANC';
    public string $callingAet = 'LEAD_CLIENT';
    public string $modality  = 'DX';
    public int $timeout      = 30;

    /** Basic auth Orthanc (kosongkan bila AuthenticationEnabled = false). */
    public ?string $user     = null;
    public ?string $password = null;

    /**
     * API key **Bridge API** (dipakai `Simrs\Pacs\BridgeApiClient`), bukan library ini.
     * Library `App\Libraries\Pacs` memanggil REST Orthanc langsung sehingga tidak memakai key.
     */
    public string $apiKey = '';
}
