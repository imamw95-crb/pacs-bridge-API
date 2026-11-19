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

    /**
     * Modality **default** bila sebuah order tidak mengisi `modality`.
     * Bukan pembatasan: order boleh memakai `modality` lain (DX, CT, US, ECG, PX, …)
     * asalkan alatnya terdaftar di Orthanc (`DicomModalities`).
     */
    public string $modality  = 'DX';
    public int $timeout      = 30;

    /** Basic auth Orthanc (kosongkan bila AuthenticationEnabled = false). */
    public ?string $user     = null;
    public ?string $password = null;

    /**
     * Base URL **Bridge API** (program ini), mis. `http://10.4.0.10`.
     * Dipakai `App\Libraries\PacsApi` (mode API + `X-API-Key`).
     */
    public string $bridgeUrl = 'http://10.4.0.10';

    /** Timeout HTTP ke Bridge API (detik); ZIP/gambar besar butuh lebih lama. */
    public int $bridgeTimeout = 60;

    /**
     * API key **Bridge API** (dipakai `App\Libraries\PacsApi` / `Simrs\Pacs\BridgeApiClient`),
     * bukan library `Pacs` yang memanggil REST Orthanc langsung.
     */
    public string $apiKey = '';
}
