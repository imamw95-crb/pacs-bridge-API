<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * HTTP client tipis untuk REST API Orthanc (port 8042).
 */
final class OrthancClient
{
    private string $baseUrl;
    private int $timeout;
    private ?string $user;
    private ?string $password;

    /**
     * @param array<string, mixed> $config hasil Config::load()
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) $config['base_url'], '/');
        $this->timeout = (int) ($config['timeout'] ?? 30);
        $this->user = $config['user'] ?? null;
        $this->password = $config['password'] ?? null;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function system(): array
    {
        return $this->json('GET', '/system');
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(): array
    {
        return $this->json('GET', '/statistics');
    }

    /**
     * Sequence perubahan terakhir Orthanc (untuk inisialisasi callback tanpa
     * memutar ulang seluruh riwayat).
     */
    public function lastSequence(): int
    {
        /** @var array{Last?: int} $data */
        $data = $this->json('GET', '/changes?last=true');

        return (int) ($data['Last'] ?? 0);
    }

    /**
     * Daftar perubahan Orthanc sejak sequence tertentu (`/changes`).
     * Dipakai `tools/notify-studies.php` untuk callback study baru.
     *
     * @return array{Changes: list<array<string, mixed>>, Last: int, Done: bool}
     */
    public function changes(int $since, int $limit = 100, bool $expand = true): array
    {
        $query = ['since' => (string) max(0, $since), 'limit' => (string) max(1, $limit)];
        if ($expand) {
            $query['expand'] = 'true';
        }

        /** @var array{Changes?: list<array<string, mixed>>, Last?: int, Done?: bool} $data */
        $data = $this->json('GET', $this->withQuery('/changes', $query));

        return [
            'Changes' => $data['Changes'] ?? [],
            'Last'    => (int) ($data['Last'] ?? $since),
            'Done'    => (bool) ($data['Done'] ?? true),
        ];
    }

    /**
     * Cari resource (Study/Series/Instance/Patient) memakai /tools/find.
     *
     * @param array<string, string> $query
     * @param list<array{Type: string, Key: string, Direction: string}> $orderBy
     *        pengurutan sisi server (butuh ExtendedFind, Orthanc >= 1.12.5)
     * @return list<string> daftar ID Orthanc
     */
    public function find(array $query, string $level = 'Study', int $limit = 0, bool $expand = false, array $orderBy = []): array
    {
        $payload = [
            'Level'  => $level,
            // json_encode([]) menghasilkan "[]" — Orthanc butuh object "{}"
            'Query'  => $query === [] ? new \stdClass() : $query,
            'Expand' => $expand,
        ];
        if ($limit > 0) {
            $payload['Limit'] = $limit;
        }
        if ($orderBy !== []) {
            $payload['OrderBy'] = $orderBy;
        }

        /** @var list<string|array<string, mixed>> $result */
        $result = $this->json('POST', '/tools/find', $payload);

        if ($expand) {
            return $result;
        }

        return array_values(array_map('strval', $result));
    }

    /**
     * Petakan DICOM UID (StudyInstanceUID / SeriesInstanceUID / SOPInstanceUID)
     * ke resource Orthanc. Orthanc 1.12 tidak menerima UID pada path
     * `/studies/{uid}`, jadi harus lewat `/tools/lookup`.
     *
     * @return list<array{ID?: string, Path?: string, Type?: string}>
     */
    public function lookup(string $uid): array
    {
        $raw = $this->request('POST', '/tools/lookup', null, 'application/json', $uid);
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OrthancException('Respons Orthanc bukan JSON yang valid untuk /tools/lookup');
        }

        /** @var list<array{ID?: string, Path?: string, Type?: string}> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function study(string $id): array
    {
        return $this->json('GET', '/studies/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function series(string $id): array
    {
        return $this->json('GET', '/series/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function instance(string $id): array
    {
        return $this->json('GET', '/instances/' . rawurlencode($id));
    }

    /**
     * @return array<string, string>
     */
    public function simplifiedTags(string $instanceId): array
    {
        /** @var array<string, string> $tags */
        $tags = $this->json('GET', '/instances/' . rawurlencode($instanceId) . '/simplified-tags');

        return $tags;
    }

    /**
     * @return list<string>
     */
    public function studyInstances(string $studyId): array
    {
        /** @var list<string> $ids */
        $ids = $this->json('GET', '/studies/' . rawurlencode($studyId) . '/instances');

        return $ids;
    }

    /**
     * C-FIND ke modality lain (Orthanc bertindak sebagai SCU).
     * Mengembalikan ID query Orthanc; jawabannya dibaca lewat queryAnswers().
     *
     * @param array<string, string> $query
     */
    public function modalityQuery(string $modality, array $query, string $level = 'Study'): string
    {
        $result = $this->json('POST', '/modalities/' . rawurlencode($modality) . '/query', [
            'Level'     => $level,
            'Query'     => $query === [] ? new \stdClass() : $query,
            'Normalize' => true,
        ]);

        return (string) ($result['ID'] ?? '');
    }

    /**
     * Daftar index jawaban C-FIND.
     *
     * @return list<int|string>
     */
    public function queryAnswers(string $queryId): array
    {
        /** @var list<int|string> $answers */
        $answers = $this->json('GET', '/queries/' . rawurlencode($queryId) . '/answers');

        return $answers;
    }

    /**
     * Isi 1 jawaban C-FIND: tag => ['Name' => ..., 'Value' => ...].
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryAnswerContent(string $queryId, int|string $index): array
    {
        /** @var array<string, array<string, mixed>> $content */
        $content = $this->json(
            'GET',
            '/queries/' . rawurlencode($queryId) . '/answers/' . rawurlencode((string) $index) . '/content'
        );

        return $content;
    }

    public function deleteQuery(string $queryId): void
    {
        $this->request('DELETE', '/queries/' . rawurlencode($queryId));
    }

    /**
     * C-GET dari modality lain (Orthanc jadi SCU, sumber = PACS lain).
     * Mengembalikan ID job Orthanc; pantau lewat job()/waitJob().
     *
     * Body mengikuti API Orthanc 1.12.6+: `Resources` berisi daftar identifier,
     * bukan `Level`/`Query` seperti C-FIND.
     *
     * @param list<array<string, string>> $resources mis. [['StudyInstanceUID' => '1.2.3']]
     */
    public function modalityGet(
        string $modality,
        array $resources,
        string $level = 'Study',
        int $timeout = 60,
        bool $permissive = true
    ): string {
        $result = $this->json('POST', '/modalities/' . rawurlencode($modality) . '/get', [
            'Level'       => $level,
            'Resources'   => $resources,
            'Permissive'  => $permissive,
            'Synchronous' => false,
            'Timeout'     => $timeout,
        ]);

        return (string) ($result['ID'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function job(string $id): array
    {
        return $this->json('GET', '/jobs/' . rawurlencode($id));
    }

    /**
     * Tunggu job Orthanc sampai tidak lagi berjalan.
     *
     * @return array<string, mixed>
     */
    public function waitJob(string $id, int $timeout = 120, int $intervalMs = 500): array
    {
        $deadline = microtime(true) + $timeout;

        do {
            $job = $this->job($id);
            $state = (string) ($job['State'] ?? '');
            if (!in_array($state, ['Pending', 'Running', 'Paused', 'Retry'], true)) {
                return $job;
            }
            usleep($intervalMs * 1000);
        } while (microtime(true) < $deadline);

        throw new OrthancException(sprintf('Job Orthanc %s belum selesai setelah %d detik', $id, $timeout));
    }

    /**
     * @return array<string, mixed>
     */
    public function worklists(): array
    {
        return $this->json('GET', '/worklists');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createWorklist(array $payload): array
    {
        return $this->json('POST', '/worklists/create', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateWorklist(string $id, array $payload): array
    {
        return $this->json('PUT', '/worklists/' . rawurlencode($id), $payload);
    }

    public function deleteWorklist(string $id): void
    {
        $this->request('DELETE', '/worklists/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function worklist(string $id): array
    {
        return $this->json('GET', '/worklists/' . rawurlencode($id));
    }

    /**
     * Ambil body biner (gambar, file DICOM, ZIP).
     *
     * @param array<string, scalar> $query
     */
    public function binary(string $path, array $query = [], string $accept = '*/*'): string
    {
        return $this->request('GET', $this->withQuery($path, $query), null, $accept);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, ?array $body = null): array
    {
        $raw = $this->request($method, $path, $body, 'application/json');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OrthancException('Respons Orthanc bukan JSON yang valid untuk ' . $path);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param string|null $rawBody isi body apa adanya (bukan JSON), mis. untuk /tools/lookup
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        string $accept = 'application/json',
        ?string $rawBody = null
    ): string {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OrthancException('Gagal membuat handle cURL');
        }

        $headers = ['Accept: ' . $accept];
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new OrthancException('Gagal encode JSON request');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $json;
        } elseif ($rawBody !== null) {
            $headers[] = 'Content-Type: text/plain';
            $options[CURLOPT_POSTFIELDS] = $rawBody;
        }

        if ($this->user !== null) {
            $options[CURLOPT_USERPWD] = $this->user . ':' . ($this->password ?? '');
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new OrthancException(sprintf('Koneksi ke Orthanc gagal (%s): %s', $url, $error));
        }

        if ($status >= 400) {
            $text = is_string($response) ? $response : '';
            throw new OrthancException(
                sprintf('Orthanc %s %s -> HTTP %d', $method, $path, $status),
                $status,
                $text
            );
        }

        return is_string($response) ? $response : '';
    }

    /**
     * @param array<string, scalar> $query
     */
    private function withQuery(string $path, array $query): string
    {
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }
}
