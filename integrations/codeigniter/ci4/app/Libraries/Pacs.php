<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;
use Simrs\Pacs\Bridge;
use Simrs\Pacs\OrthancClient;
use Simrs\Pacs\StudyService;
use Simrs\Pacs\WorklistService;

/**
 * Library CodeIgniter 4 untuk bridge SIMRS <-> Orthanc PACS.
 *
 * Pemakaian:
 *   $pacs = new \App\Libraries\Pacs(config('Pacs'));
 *   // atau via Services: service('pacs')
 *
 *   $pacs->createOrder([...]);
 *   $img = $pacs->jpegByAccession('A261105038');
 */
class Pacs
{
    private Bridge $bridge;

    /**
     * @param array<string, mixed>|object|null $config Config\Pacs atau array
     */
    public function __construct(array|object|null $config = null)
    {
        $cfg = is_object($config) ? get_object_vars($config) : ($config ?? []);

        self::requireSdk(isset($cfg['pacsRoot']) ? (string) $cfg['pacsRoot'] : null);

        $this->bridge = Bridge::fromArray([
            'server_ip'   => $cfg['serverIp'] ?? null,
            'http_port'   => $cfg['httpPort'] ?? null,
            'dicom_port'  => $cfg['dicomPort'] ?? null,
            'called_aet'  => $cfg['calledAet'] ?? null,
            'calling_aet' => $cfg['callingAet'] ?? null,
            'modality'    => $cfg['modality'] ?? null,
            'timeout'     => $cfg['timeout'] ?? null,
            'user'        => $cfg['user'] ?? null,
            'password'    => $cfg['password'] ?? null,
        ]);
    }

    public function bridge(): Bridge
    {
        return $this->bridge;
    }

    public function client(): OrthancClient
    {
        return $this->bridge->client();
    }

    public function worklist(): WorklistService
    {
        return $this->bridge->worklist();
    }

    public function studies(): StudyService
    {
        return $this->bridge->studies();
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->bridge->health();
    }

    /**
     * Kirim order ke worklist.
     *
     * @param array<string, mixed> $order
     * @return array{id: string, patient_name: string, accession_number: string}
     */
    public function createOrder(array $order): array
    {
        return $this->bridge->worklist()->create($order);
    }

    /**
     * @param array<string, mixed> $order
     * @return array{id: string, patient_name: string, accession_number: string}
     */
    public function updateOrder(string $id, array $order): array
    {
        return $this->bridge->worklist()->update($id, $order);
    }

    public function deleteOrder(string $id): void
    {
        $this->bridge->worklist()->delete($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function orders(): array
    {
        return $this->bridge->worklist()->all();
    }

    /**
     * Nomor AccessionNumber baru mengikuti format alat Orthanc.
     */
    public function nextAccession(): string
    {
        return $this->bridge->worklist()->nextAccessionNumber();
    }

    /**
     * Study terbaru (urut tanggal + jam study menurun).
     *
     * @param string $date 'today' (default), 'all', atau 'YYYYMMDD'
     * @param string $modality kode DICOM (mis. 'DX', 'US', 'ECG'); kosong = semua
     * @return list<array<string, mixed>>
     */
    public function recentStudies(string $date = 'today', int $limit = 12, string $modality = ''): array
    {
        $limit = max(1, min(300, $limit));
        $filter = $this->normalizeDate($date);
        $modality = strtoupper(trim($modality));

        $studies = $this->bridge->studies();
        $fetchLimit = $modality === '' ? $limit : 300;
        $rows = $studies->attachModalities($studies->recent($fetchLimit, $filter), $filter);

        if ($modality !== '') {
            $rows = $studies->filterByModality($rows, $modality);
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByPatientId(string $patientId, int $limit = 20): array
    {
        return $this->bridge->studies()->findByPatientId($patientId, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByName(string $name, int $limit = 50): array
    {
        return $this->bridge->studies()->findByPatientName($name, $limit);
    }

    /**
     * Daftar gambar (instance) satu study; kunci boleh accession, ID Orthanc, atau DICOM UID.
     *
     * @return list<array{instance_id: string, series_number: string, instance_number: string}>
     */
    public function imageList(string $studyKey): array
    {
        $study = $this->bridge->studies()->findOneByKey($studyKey);

        return $study === null ? [] : $this->bridge->studies()->imageList((string) ($study['ID'] ?? ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudies(string $accessionNumber): array
    {
        return $this->bridge->studies()->findByAccession($accessionNumber);
    }

    /**
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    public function jpegByAccession(string $accessionNumber, int $quality = 90, bool $watermark = false): ?array
    {
        return $this->bridge->studies()->imageByAccession($accessionNumber, 'jpg', $quality, null, $watermark);
    }

    /**
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    public function pngByAccession(string $accessionNumber, ?int $width = null): ?array
    {
        return $this->bridge->studies()->imageByAccession($accessionNumber, 'png', 90, $width);
    }

    /**
     * ZIP berisi semua JPG satu study, satu folder = No. RM.
     *
     * @return array{content: string, filename: string, folder: string}|null null bila study tidak punya gambar
     */
    public function zipJpgByAccession(string $accessionNumber, int $quality = 90, bool $watermark = true): ?array
    {
        return $this->bridge->studies()->zipJpegByKey($accessionNumber, $quality, $watermark);
    }

    /**
     * ZIP seluruh DICOM satu study.
     */
    public function zipByAccession(string $accessionNumber): ?string
    {
        $study = $this->bridge->studies()->findOneByKey($accessionNumber);

        return $study === null ? null : $this->bridge->studies()->archiveStudy((string) ($study['ID'] ?? ''));
    }

    /**
     * @param string $date 'today', 'all', atau 'YYYYMMDD'
     */
    private function normalizeDate(string $date): ?string
    {
        $date = strtolower(trim($date));

        if ($date === '' || $date === 'today') {
            return date('Ymd');
        }
        if ($date === 'all') {
            return null;
        }
        if (preg_match('/^\d{8}$/', $date) !== 1) {
            throw new \InvalidArgumentException('Parameter tanggal harus today, all, atau YYYYMMDD');
        }

        return $date;
    }

    /**
     * Cari SDK (folder src/ + autoload.php) di beberapa lokasi yang wajar.
     * Dipakai juga oleh `App\Libraries\PacsApi`.
     */
    public static function requireSdk(?string $pacsRoot): void
    {
        $candidates = [];

        if ($pacsRoot !== null && $pacsRoot !== '') {
            $candidates[] = $pacsRoot;
        }

        if (defined('APPPATH')) {
            $candidates[] = APPPATH . 'ThirdParty/pacs';
        }
        if (defined('FCPATH')) {
            $candidates[] = FCPATH . 'pacs';
        }
        $candidates[] = dirname(__DIR__, 3) . '/ThirdParty/pacs';

        foreach ($candidates as $root) {
            $autoload = rtrim($root, '/\\') . '/src/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;

                return;
            }
            $autoload = rtrim($root, '/\\') . '/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;

                return;
            }
        }

        throw new RuntimeException(
            'SDK PACS tidak ditemukan. Salin folder "src" ke APPPATH/ThirdParty/pacs/src '
            . 'atau set $pacsRoot di app/Config/Pacs.php'
        );
    }
}
