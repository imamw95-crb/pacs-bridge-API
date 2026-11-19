<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use RuntimeException;

/**
 * HTTP client untuk **Bridge API** (public/index.php) memakai header X-API-Key.
 *
 * Bedanya dengan OrthancClient:
 *   - OrthancClient : aplikasi bicara langsung ke Orthanc (port 8042), tanpa auth.
 *   - BridgeApiClient: aplikasi bicara ke service bridge PHP kita (yang punya login/API key,
 *     validasi, filter tanggal, dan rendering gambar), lalu bridge yang meneruskan ke Orthanc.
 *
 * Contoh:
 *   $api = new BridgeApiClient('http://pacs-bridge.test', 'kunci-rahasia');
 *   $api->health();
 *   $api->createWorklist([...]);
 *   $img = $api->jpegByAccession('A261105038', 90);
 */
final class BridgeApiClient
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, ?string $apiKey = null, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey !== '' ? $apiKey : null;
        $this->timeout = $timeout;
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->json('GET', '/health');
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->json('GET', '/me');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function worklists(): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $this->json('GET', '/worklists');

        return $data;
    }

    /**
     * @param array<string, mixed> $order patient_name, patient_id, accession_number, procedure (+ title, modality, ...)
     * @return array<string, mixed>
     */
    public function createWorklist(array $order): array
    {
        return $this->json('POST', '/worklists', $order);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function updateWorklist(string $id, array $order): array
    {
        return $this->json('PUT', '/worklists/' . rawurlencode($id), $order);
    }

    public function deleteWorklist(string $id): void
    {
        $this->json('DELETE', '/worklists/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function worklist(string $id): array
    {
        return $this->json('GET', '/worklists/' . rawurlencode($id));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByAccession(string $accessionNumber): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $this->json('GET', '/studies?accession=' . rawurlencode($accessionNumber));

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStudiesByPatientId(string $patientId): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $this->json('GET', '/studies?patient_id=' . rawurlencode($patientId));

        return $data;
    }

    /**
     * @param string $date 'today' | 'all' | 'YYYYMMDD'
     * @param string $modality kode DICOM (mis. 'US' untuk USG, 'DX' untuk rontgen); '' = semua
     * @return array{date: string, limit: int, modality?: string, studies: list<array<string, mixed>>}
     */
    public function recentStudies(string $date = 'today', int $limit = 12, string $modality = ''): array
    {
        $url = sprintf('/studies/recent?date=%s&limit=%d', rawurlencode($date), $limit);
        if ($modality !== '') {
            $url .= '&modality=' . rawurlencode($modality);
        }

        /** @var array{date: string, limit: int, modality?: string, studies: list<array<string, mixed>>} $data */
        $data = $this->json('GET', $url);

        return $data;
    }

    /**
     * @return array{content: string, mime: string, filename: string}|null
     */
    public function jpegByAccession(string $accessionNumber, int $quality = 90): ?array
    {
        return $this->image('/studies/' . rawurlencode($accessionNumber) . '/jpg?quality=' . max(1, min(100, $quality)));
    }

    /**
     * @return array{content: string, mime: string, filename: string}|null
     */
    public function pngByAccession(string $accessionNumber, ?int $width = null): ?array
    {
        $query = $width !== null ? '?width=' . max(64, $width) : '';

        return $this->image('/studies/' . rawurlencode($accessionNumber) . '/png' . $query);
    }

    public function zipByAccession(string $accessionNumber): ?string
    {
        $image = $this->image('/studies/' . rawurlencode($accessionNumber) . '/zip');

        return $image['content'] ?? null;
    }

    public function dicomInstance(string $instanceId): ?string
    {
        $image = $this->image('/instances/' . rawurlencode($instanceId) . '/file');

        return $image['content'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->json('GET', '/settings');
    }

    /**
     * @return array{content: string, mime: string, filename: string}|null
     */
    private function image(string $path): ?array
    {
        [$status, $body, $headers] = $this->request('GET', $path, null, '*/*');

        if ($status === 404) {
            return null;
        }
        $this->assertOk($status, $body, 'GET', $path);

        $mime = $headers['content-type'] ?? 'application/octet-stream';
        $filename = 'download.bin';
        if (isset($headers['content-disposition'])
            && preg_match('/filename="([^"]+)"/', $headers['content-disposition'], $m) === 1) {
            $filename = $m[1];
        }

        return ['content' => $body, 'mime' => $mime, 'filename' => $filename];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, ?array $body = null): array
    {
        [$status, $raw] = $this->request($method, $path, $body, 'application/json');
        $this->assertOk($status, $raw, $method, $path);

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respons bridge bukan JSON valid untuk ' . $path);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function request(string $method, string $path, ?array $body, string $accept): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Gagal membuat handle cURL');
        }

        $headers = ['Accept: ' . $accept];
        if ($this->apiKey !== null) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
        ];

        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new RuntimeException('Gagal encode body');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        $responseHeaders = [];
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        };
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException(sprintf('Gagal menghubungi bridge (%s): %s', $url, $error));
        }

        return [$status, (string) $response, $responseHeaders];
    }

    private function assertOk(int $status, string $body, string $method, string $path): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 401) {
            throw new BridgeApiException('Unauthorized: API key tidak valid / tidak dikirim', 401, $body);
        }
        if ($status === 403) {
            throw new BridgeApiException('Forbidden: operasi ini butuh sesi login', 403, $body);
        }

        throw new BridgeApiException(
            sprintf('Bridge %s %s -> HTTP %d', $method, $path, $status),
            $status,
            $body
        );
    }
}
