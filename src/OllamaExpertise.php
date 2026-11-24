<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use InvalidArgumentException;
use RuntimeException;

/**
 * Draf "expertise" otomatis dari satu gambar study memakai model vision di server
 * Ollama (LAN, tanpa layanan pihak ketiga).
 *
 * Hasil selalu berupa DRAF untuk diverifikasi dokter/radiolog — bukan diagnosis final.
 * Citra dirender ulang TANPA watermark supaya nama/No. RM tidak ikut terkirim ke model.
 */
final class OllamaExpertise
{
    public const PROMPT_VERSION = 'v1';

    /** Lebar render PNG yang dikirim ke model (cukup detail, tetap ringan). */
    private const IMAGE_WIDTH = 1024;

    private const MODEL_PATTERN = '/^[A-Za-z0-9._:\/-]{1,80}$/';

    private const PROMPT = <<<'PROMPT'
Anda asisten radiologi yang konservatif. Anda hanya menerima SATU gambar representatif
dari pemeriksaan %s%s.

Aturan wajib:
- Jangan menyebut temuan yang tidak jelas terlihat; bila ragu tulis "tidak dapat dinilai".
- Jangan menyimpulkan efusi, konsolidasi, atau massa tanpa bukti jelas (meniskus, hilangnya siluet, air bronchogram, batas lesi).
- Bila struktur dalam batas normal, tulis "dalam batas normal".
- Sebutkan bahwa penilaian terbatas pada satu gambar.

Balas HANYA JSON valid (tanpa markdown) dengan kunci:
{"kualitas":"","temuan_utama":"","temuan_lain":"","kesan":"","rekomendasi":"","keyakinan":"tinggi|sedang|rendah"}
PROMPT;

    /** Kunci wajib pada draf; kunci lain dari model tetap disimpan. */
    private const DRAFT_KEYS = ['kualitas', 'temuan_utama', 'temuan_lain', 'kesan', 'rekomendasi', 'keyakinan'];

    public function __construct(
        private readonly StudyService $studies,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
        private readonly string $storeDir
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Status server Ollama + daftar model vision yang tersedia.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        // /api/version tidak ada di build sangat lama → status tetap dianggap terjangkau.
        try {
            $version = (string) ($this->request('GET', '/api/version')['version'] ?? '');
        } catch (OllamaException $e) {
            $version = '';
        }
        $models = [];
        $vision = [];
        foreach ($this->request('GET', '/api/tags')['models'] ?? [] as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }
            $name = (string) $item['name'];
            $capabilities = is_array($item['capabilities'] ?? null) ? $item['capabilities'] : [];
            $models[] = ['name' => $name, 'vision' => in_array('vision', $capabilities, true)];
            if (in_array('vision', $capabilities, true)) {
                $vision[] = $name;
            }
        }

        return [
            'reachable'     => true,
            'base_url'      => $this->baseUrl,
            'version'       => $version !== '' ? $version : null,
            'model'         => $this->model,
            'model_ready'   => in_array($this->model, $vision, true),
            'models'        => $models,
            'vision_models' => $vision,
        ];
    }

    /**
     * Buat draf expertise baru untuk satu study (kunci: accession / ID Orthanc / UID).
     *
     * @return array<string, mixed> draf + metadata yang juga disimpan ke `data/expertise/`
     */
    public function generate(string $key, ?string $model = null): array
    {
        $key = trim($key);
        if (preg_match('/^[A-Za-z0-9.\-]{1,128}$/', $key) !== 1) {
            throw new InvalidArgumentException('Kunci study tidak valid.');
        }

        $model = $model !== null && trim($model) !== '' ? trim($model) : $this->model;
        if (preg_match(self::MODEL_PATTERN, $model) !== 1) {
            throw new InvalidArgumentException('Nama model Ollama tidak valid: ' . $model);
        }

        $study = $this->studies->findOneByKey($key);
        if ($study === null) {
            throw new InvalidArgumentException('Study ' . $key . ' belum masuk ke PACS.');
        }

        $studyId = (string) ($study['ID'] ?? '');
        $ref = $this->studies->firstInstance($studyId);
        if ($ref === null) {
            throw new InvalidArgumentException('Study ' . $key . ' belum memiliki gambar untuk dianalisis.');
        }

        $png = $this->studies->renderPng($ref['instance_id'], self::IMAGE_WIDTH);
        if ($png === '') {
            throw new InvalidArgumentException('Gambar study ' . $key . ' gagal dirender.');
        }

        $description = trim((string) ($study['MainDicomTags']['StudyDescription'] ?? ''));
        $prompt = sprintf(
            self::PROMPT,
            $ref['modality'] !== '' ? 'modalitas ' . $ref['modality'] : 'radiologi',
            $description !== '' ? ' ("' . $description . '")' : ''
        );

        $started = microtime(true);
        $response = $this->request('POST', '/api/generate', [
            'model'   => $model,
            'prompt'  => $prompt,
            'images'  => [base64_encode($png)],
            'stream'  => false,
            'think'   => false,
            'format'  => 'json',
            'options' => ['temperature' => 0, 'num_predict' => 800],
        ]);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $answer = trim((string) ($response['response'] ?? ''));
        $draft = json_decode($answer, true);
        $parsed = is_array($draft);
        if (!$parsed) {
            // Model kadang menambah teks di luar JSON; simpan apa adanya agar tidak hilang.
            $draft = ['kesan' => $answer];
        }

        $result = [
            'study_key'         => $key,
            'study_id'          => $studyId,
            'accession'         => (string) ($study['MainDicomTags']['AccessionNumber'] ?? ''),
            'modality'          => $ref['modality'],
            'study_description' => $description,
            'study_date'        => (string) ($study['MainDicomTags']['StudyDate'] ?? ''),
            'model'             => $model,
            'prompt_version'    => self::PROMPT_VERSION,
            'generated_at'      => date('c'),
            'duration_ms'       => $durationMs,
            'eval_count'        => (int) ($response['eval_count'] ?? 0),
            'parsed'            => $parsed,
            'draft'             => $this->normalizeDraft($draft),
            'disclaimer'        => 'DRAF AI — wajib diverifikasi dokter/radiolog sebelum dipakai.',
        ];

        if (!$parsed) {
            $result['raw'] = $answer;
        }

        $this->store($key, $result);

        return $result;
    }

    /**
     * Draf yang sudah tersimpan (null bila belum pernah dibuat).
     *
     * @return array<string, mixed>|null
     */
    public function stored(string $key): ?array
    {
        $path = $this->storePath($key);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, string>
     */
    private function normalizeDraft(array $draft): array
    {
        $clean = [];
        foreach (self::DRAFT_KEYS as $name) {
            $value = $draft[$name] ?? '';
            $clean[$name] = is_scalar($value) ? trim((string) $value) : '';
        }

        foreach ($draft as $name => $value) {
            if (!is_string($name) || in_array($name, self::DRAFT_KEYS, true) || !is_scalar($value)) {
                continue;
            }
            $clean[$name] = trim((string) $value);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(string $key, array $data): void
    {
        $path = $this->storePath($key);
        if ($path === null) {
            return;
        }

        if (!is_dir($this->storeDir) && !mkdir($this->storeDir, 0775, true) && !is_dir($this->storeDir)) {
            throw new RuntimeException('Tidak bisa membuat folder ' . $this->storeDir);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal encode draf expertise');
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Tidak bisa menyimpan draf expertise ke ' . $path);
        }
    }

    private function storePath(string $key): ?string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $key);

        return $safe === null || $safe === '' ? null : $this->storeDir . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim(trim($this->baseUrl), '/');
        if ($base === '') {
            throw new OllamaException('URL server Ollama belum diatur (Pengaturan → Auto Expertise).');
        }

        $url = $base . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OllamaException('Gagal membuat handle cURL untuk Ollama.');
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new OllamaException('Gagal encode permintaan Ollama.');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $json;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new OllamaException(sprintf('Tidak bisa menghubungi Ollama (%s): %s', $url, $error));
        }

        $text = is_string($response) ? $response : '';
        if ($status >= 400) {
            throw new OllamaException(
                sprintf('Ollama %s %s -> HTTP %d', $method, $path, $status),
                $status,
                $text
            );
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new OllamaException('Respons Ollama bukan JSON yang valid untuk ' . $path, $status, $text);
        }

        return $decoded;
    }
}
