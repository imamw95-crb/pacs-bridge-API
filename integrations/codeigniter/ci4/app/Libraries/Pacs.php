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

        $this->requireSdk(isset($cfg['pacsRoot']) ? (string) $cfg['pacsRoot'] : null);

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
     * @return list<array<string, mixed>>
     */
    public function findStudies(string $accessionNumber): array
    {
        return $this->bridge->studies()->findByAccession($accessionNumber);
    }

    /**
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    public function jpegByAccession(string $accessionNumber, int $quality = 90): ?array
    {
        return $this->bridge->studies()->imageByAccession($accessionNumber, 'jpg', $quality);
    }

    /**
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    public function pngByAccession(string $accessionNumber, ?int $width = null): ?array
    {
        return $this->bridge->studies()->imageByAccession($accessionNumber, 'png', 90, $width);
    }

    /**
     * Cari SDK (folder src/ + autoload.php) di beberapa lokasi yang wajar.
     */
    private function requireSdk(?string $pacsRoot): void
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
