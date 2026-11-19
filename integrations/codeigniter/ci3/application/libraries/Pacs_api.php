<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Library CodeIgniter 3 untuk **Bridge API** (`public/index.php`) memakai header
 * `X-API-Key`. Mode ini membuat SIMRS **tidak** mengakses Orthanc langsung —
 * validasi, render gambar, watermark, dan ZIP dikerjakan program bridge.
 *
 * Alternatifnya `Pacs_bridge` (memanggil REST Orthanc langsung).
 *
 * Pemakaian:
 *   $this->load->library('pacs_api');
 *   $this->pacs_api->create_worklist([...]);
 *   $img = $this->pacs_api->jpeg_by_accession('A261105038', 90, true);
 */
class Pacs_api
{
    /** @var \Simrs\Pacs\BridgeApiClient */
    private $client;

    /** @var string */
    private $errorMessage = '';

    public function __construct(array $params = [])
    {
        $cfg = $this->buildConfig($params);

        if ($cfg['base_url'] === '') {
            show_error('bridge_url belum diisi (mis. http://10.4.0.10) di application/config/pacs.php');
        }
        if ($cfg['api_key'] === '') {
            show_error('api_key Bridge API belum diisi di application/config/pacs.php (atau env PACS_BRIDGE_KEY)');
        }

        $this->client = new \Simrs\Pacs\BridgeApiClient($cfg['base_url'], $cfg['api_key'], $cfg['timeout']);
    }

    public function client()
    {
        return $this->client;
    }

    public function health(): array
    {
        try {
            return $this->client->health();
        } catch (\Throwable $e) {
            $this->fail('health', $e);

            return ['reachable' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        try {
            return $this->client->me();
        } catch (\Throwable $e) {
            $this->fail('me', $e);

            return [];
        }
    }

    /**
     * @return string|false
     */
    public function next_accession()
    {
        try {
            return $this->client->nextAccession();
        } catch (\Throwable $e) {
            $this->fail('next_accession', $e);

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function worklists(): array
    {
        try {
            return $this->client->worklists();
        } catch (\Throwable $e) {
            $this->fail('worklists', $e);

            return [];
        }
    }

    public function worklist(string $id)
    {
        try {
            return $this->client->worklist($id);
        } catch (\Throwable $e) {
            $this->fail('worklist', $e);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|false
     */
    public function create_worklist(array $order)
    {
        try {
            return $this->client->createWorklist($order);
        } catch (\Throwable $e) {
            $this->fail('create_worklist', $e);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|false
     */
    public function update_worklist(string $id, array $order)
    {
        try {
            return $this->client->updateWorklist($id, $order);
        } catch (\Throwable $e) {
            $this->fail('update_worklist', $e);

            return false;
        }
    }

    public function delete_worklist(string $id): bool
    {
        try {
            $this->client->deleteWorklist($id);

            return true;
        } catch (\Throwable $e) {
            $this->fail('delete_worklist', $e);

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_studies_by_accession(string $accession_number): array
    {
        try {
            return $this->client->findStudiesByAccession($accession_number);
        } catch (\Throwable $e) {
            $this->fail('find_studies_by_accession', $e);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_studies_by_patient_id(string $patient_id): array
    {
        try {
            return $this->client->findStudiesByPatientId($patient_id);
        } catch (\Throwable $e) {
            $this->fail('find_studies_by_patient_id', $e);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_studies_by_name(string $name): array
    {
        try {
            return $this->client->findStudiesByName($name);
        } catch (\Throwable $e) {
            $this->fail('find_studies_by_name', $e);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function recent_studies(string $date = 'today', int $limit = 12, string $modality = ''): array
    {
        try {
            return $this->client->recentStudies($date, $limit, $modality);
        } catch (\Throwable $e) {
            $this->fail('recent_studies', $e);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function image_list(string $study_key): array
    {
        try {
            return $this->client->imagesByAccession($study_key);
        } catch (\Throwable $e) {
            $this->fail('image_list', $e);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jpeg_by_accession(string $accession_number, int $quality = 90, bool $watermark = false)
    {
        try {
            return $this->client->jpegByAccession($accession_number, $quality, $watermark);
        } catch (\Throwable $e) {
            $this->fail('jpeg_by_accession', $e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function png_by_accession(string $accession_number, ?int $width = null)
    {
        try {
            return $this->client->pngByAccession($accession_number, $width);
        } catch (\Throwable $e) {
            $this->fail('png_by_accession', $e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function zip_jpg_by_accession(string $accession_number, int $quality = 90, bool $watermark = true)
    {
        try {
            return $this->client->zipJpgByAccession($accession_number, $quality, $watermark);
        } catch (\Throwable $e) {
            $this->fail('zip_jpg_by_accession', $e);

            return null;
        }
    }

    public function zip_by_accession(string $accession_number): ?string
    {
        try {
            return $this->client->zipByAccession($accession_number);
        } catch (\Throwable $e) {
            $this->fail('zip_by_accession', $e);

            return null;
        }
    }

    public function dicom_instance(string $instance_id): ?string
    {
        try {
            return $this->client->dicomInstance($instance_id);
        } catch (\Throwable $e) {
            $this->fail('dicom_instance', $e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        try {
            return $this->client->settings();
        } catch (\Throwable $e) {
            $this->fail('settings', $e);

            return [];
        }
    }

    public function error_message(): string
    {
        return $this->errorMessage;
    }

    private function fail(string $method, \Throwable $e): void
    {
        $this->errorMessage = $e->getMessage();
        log_message('error', '[pacs-api] ' . $method . ': ' . $e->getMessage());
    }

    /**
     * @param array<string, mixed> $params
     * @return array{base_url: string, api_key: string, timeout: int}
     */
    private function buildConfig(array $params): array
    {
        $ci = function_exists('get_instance') ? get_instance() : null;
        if ($ci !== null && isset($ci->config)) {
            $ci->config->load('pacs', true);
            $fileCfg = (array) $ci->config->item('pacs');
            $params = array_merge($fileCfg, $params);
        }

        $this->requireSdk((string) ($params['pacs_root'] ?? ''));

        $timeout = (int) ($params['bridge_timeout'] ?? 60);

        return [
            'base_url' => rtrim((string) ($params['bridge_url'] ?? ''), '/'),
            'api_key'  => (string) ($params['api_key'] ?? ''),
            'timeout'  => $timeout > 0 ? $timeout : 60,
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
