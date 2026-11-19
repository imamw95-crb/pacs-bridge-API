<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;
use Simrs\Pacs\BridgeApiClient;

/**
 * Library CodeIgniter 4 untuk memanggil **Bridge API** (`public/index.php`)
 * memakai header `X-API-Key`. Dengan mode ini SIMRS **tidak** menghubungi Orthanc
 * langsung — semua validasi, render gambar, watermark, dan ZIP dikerjakan bridge.
 *
 * Pemakaian:
 *   $api = new \App\Libraries\PacsApi(config('Pacs'));
 *   // atau via Services: service('pacsApi')
 *
 *   $api->createWorklist([...]);
 *   $jpg = $api->jpegByAccession('A261105038', 90, true);
 */
class PacsApi
{
    private BridgeApiClient $client;

    /**
     * @param array<string, mixed>|object|null $config Config\Pacs atau array
     */
    public function __construct(array|object|null $config = null)
    {
        $cfg = is_object($config) ? get_object_vars($config) : ($config ?? []);

        Pacs::requireSdk(isset($cfg['pacsRoot']) ? (string) $cfg['pacsRoot'] : null);

        $baseUrl = (string) ($cfg['bridgeUrl'] ?? $cfg['baseUrl'] ?? '');
        if ($baseUrl === '') {
            throw new RuntimeException('bridgeUrl belum diisi (mis. http://10.4.0.10) di app/Config/Pacs.php');
        }

        $apiKey = (string) ($cfg['apiKey'] ?? '');
        if ($apiKey === '') {
            throw new RuntimeException(
                'apiKey Bridge API belum diisi (dashboard → Pengaturan → API Key, atau env PACS_BRIDGE_KEY)'
            );
        }

        $timeout = (int) ($cfg['bridgeTimeout'] ?? 60);
        $this->client = new BridgeApiClient($baseUrl, $apiKey, $timeout > 0 ? $timeout : 60);
    }

    public function client(): BridgeApiClient
    {
        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->client->health();
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->client->me();
    }

    public function nextAccession(): string
    {
        return $this->client->nextAccession();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function worklists(): array
    {
        return $this->client->worklists();
    }

    /**
     * @return array<string, mixed>
     */
    public function worklist(string $id): array
    {
        return $this->client->worklist($id);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function createWorklist(array $order): array
    {
        return $this->client->createWorklist($order);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function updateWorklist(string $id, array $order): array
    {
        return $this->client->updateWorklist($id, $order);
    }

    public function deleteWorklist(string $id): void
    {
        $this->client->deleteWorklist($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByAccession(string $accessionNumber): array
    {
        return $this->client->findStudiesByAccession($accessionNumber);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByPatientId(string $patientId): array
    {
        return $this->client->findStudiesByPatientId($patientId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByName(string $name): array
    {
        return $this->client->findStudiesByName($name);
    }

    /**
     * @param string $date 'today' | 'all' | 'YYYYMMDD'
     * @return array{date: string, limit: int, modality?: string, studies: list<array<string, mixed>>}
     */
    public function recentStudies(string $date = 'today', int $limit = 12, string $modality = ''): array
    {
        return $this->client->recentStudies($date, $limit, $modality);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function imageList(string $studyKey): array
    {
        return $this->client->imagesByAccession($studyKey);
    }

    /**
     * @return array{content: string, mime: string, filename: string}|null
     */
    public function jpegByAccession(string $accessionNumber, int $quality = 90, bool $watermark = false): ?array
    {
        return $this->client->jpegByAccession($accessionNumber, $quality, $watermark);
    }

    /**
     * @return array{content: string, mime: string, filename: string}|null
     */
    public function pngByAccession(string $accessionNumber, ?int $width = null): ?array
    {
        return $this->client->pngByAccession($accessionNumber, $width);
    }

    /**
     * @return array{content: string, mime: string, filename: string}|null
     */
    public function zipJpgByAccession(string $accessionNumber, int $quality = 90, bool $watermark = true): ?array
    {
        return $this->client->zipJpgByAccession($accessionNumber, $quality, $watermark);
    }

    public function zipByAccession(string $accessionNumber): ?string
    {
        return $this->client->zipByAccession($accessionNumber);
    }

    public function dicomInstance(string $instanceId): ?string
    {
        return $this->client->dicomInstance($instanceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->client->settings();
    }
}
