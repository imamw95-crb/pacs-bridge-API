<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Library CodeIgniter 3 untuk bridge SIMRS <-> Orthanc PACS.
 *
 * Instalasi:
 *   1. Salin folder SDK "src" ke application/third_party/pacs/src
 *   2. Salin file ini ke application/libraries/Pacs_bridge.php
 *   3. Salin application/config/pacs.php ke proyek SIMRS
 *
 * Pemakaian di controller:
 *   $this->load->library('pacs_bridge');
 *   $this->pacs_bridge->create_order([...]);
 *   $img = $this->pacs_bridge->jpeg_by_accession('A261105038');
 */
class Pacs_bridge
{
    /** @var \Simrs\Pacs\Bridge */
    private $bridge;

    /** @var string */
    private $errorMessage = '';

    public function __construct(array $params = [])
    {
        $this->requireSdk($params['pacs_root'] ?? '');
        $this->bridge = \Simrs\Pacs\Bridge::fromArray($this->buildConfig($params));
    }

    public function bridge()
    {
        return $this->bridge;
    }

    public function client()
    {
        return $this->bridge->client();
    }

    public function worklist()
    {
        return $this->bridge->worklist();
    }

    public function studies()
    {
        return $this->bridge->studies();
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        try {
            return $this->bridge->health();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return ['reachable' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|false
     */
    public function create_order(array $order)
    {
        try {
            return $this->bridge->worklist()->create($order);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            log_message('error', '[pacs] create_order: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|false
     */
    public function update_order(string $id, array $order)
    {
        try {
            return $this->bridge->worklist()->update($id, $order);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            log_message('error', '[pacs] update_order: ' . $e->getMessage());

            return false;
        }
    }

    public function delete_order(string $id): bool
    {
        try {
            $this->bridge->worklist()->delete($id);

            return true;
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            log_message('error', '[pacs] delete_order: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function orders(): array
    {
        try {
            return $this->bridge->worklist()->all();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return [];
        }
    }

    /**
     * @return string|false
     */
    public function next_accession()
    {
        try {
            return $this->bridge->worklist()->nextAccessionNumber();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return false;
        }
    }

    /**
     * @param string $date 'today' (default), 'all', atau 'YYYYMMDD'
     * @param string $modality kode DICOM (mis. 'DX', 'US', 'ECG'); kosong = semua
     * @return array<int, array<string, mixed>>
     */
    public function recent_studies(string $date = 'today', int $limit = 12, string $modality = ''): array
    {
        try {
            $limit = max(1, min(300, $limit));
            $filter = $this->normalize_date($date);
            $modality = strtoupper(trim($modality));

            $studies = $this->bridge->studies();
            $fetchLimit = $modality === '' ? $limit : 300;
            $rows = $studies->attachModalities($studies->recent($fetchLimit, $filter), $filter);

            if ($modality !== '') {
                $rows = $studies->filterByModality($rows, $modality);
            }

            return array_slice($rows, 0, $limit);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_studies_by_patient_id(string $patient_id, int $limit = 20): array
    {
        try {
            return $this->bridge->studies()->findByPatientId($patient_id, $limit);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_studies_by_name(string $name, int $limit = 50): array
    {
        try {
            return $this->bridge->studies()->findByPatientName($name, $limit);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_studies(string $accession_number): array
    {
        try {
            return $this->bridge->studies()->findByAccession($accession_number);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return [];
        }
    }

    /**
     * Daftar gambar (instance) satu study; kunci boleh accession, ID Orthanc, atau DICOM UID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function image_list(string $study_key): array
    {
        try {
            $study = $this->bridge->studies()->findOneByKey($study_key);

            return $study === null ? [] : $this->bridge->studies()->imageList((string) ($study['ID'] ?? ''));
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jpeg_by_accession(string $accession_number, int $quality = 90, bool $watermark = false)
    {
        try {
            return $this->bridge->studies()->imageByAccession($accession_number, 'jpg', $quality, null, $watermark);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function png_by_accession(string $accession_number, ?int $width = null)
    {
        try {
            return $this->bridge->studies()->imageByAccession($accession_number, 'png', 90, $width);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }
    }

    /**
     * ZIP berisi semua JPG satu study, satu folder = No. RM.
     *
     * @return array<string, mixed>|null
     */
    public function zip_jpg_by_accession(string $accession_number, int $quality = 90, bool $watermark = true)
    {
        try {
            return $this->bridge->studies()->zipJpegByKey($accession_number, $quality, $watermark);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }
    }

    /**
     * ZIP seluruh DICOM satu study.
     */
    public function zip_by_accession(string $accession_number): ?string
    {
        try {
            $study = $this->bridge->studies()->findOneByKey($accession_number);

            return $study === null ? null : $this->bridge->studies()->archiveStudy((string) ($study['ID'] ?? ''));
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }
    }

    /**
     * @param string $date 'today', 'all', atau 'YYYYMMDD'
     */
    private function normalize_date(string $date): ?string
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

    public function error_message(): string
    {
        return $this->errorMessage;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildConfig(array $params): array
    {
        $ci = function_exists('get_instance') ? get_instance() : null;
        if ($ci !== null && isset($ci->config)) {
            $ci->config->load('pacs', true);
            $fileCfg = (array) $ci->config->item('pacs');
            $params = array_merge($fileCfg, $params);
        }

        return [
            'server_ip'   => $params['server_ip'] ?? null,
            'http_port'   => $params['http_port'] ?? null,
            'dicom_port'  => $params['dicom_port'] ?? null,
            'called_aet'  => $params['called_aet'] ?? null,
            'calling_aet' => $params['calling_aet'] ?? null,
            'modality'    => $params['modality'] ?? null,
            'timeout'     => $params['timeout'] ?? null,
            'user'        => $params['user'] ?? null,
            'password'    => $params['password'] ?? null,
        ];
    }

    private function requireSdk(string $pacsRoot): void
    {
        $candidates = [];
        if ($pacsRoot !== '') {
            $candidates[] = $pacsRoot;
        }
        if (defined('APPPATH')) {
            $candidates[] = APPPATH . 'third_party/pacs';
            $candidates[] = APPPATH . 'ThirdParty/pacs';
        }
        $candidates[] = dirname(__DIR__, 2) . '/third_party/pacs';

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

        show_error('SDK PACS tidak ditemukan. Salin folder "src" ke application/third_party/pacs/src');
    }
}
