<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Facade: satu pintu untuk semua operasi bridge SIMRS <-> Orthanc.
 *
 * Contoh:
 *   $bridge = Bridge::fromSettingFile();
 *   $bridge->worklist()->create([...]);
 *   $img = $bridge->studies()->imageByAccession('A261105038', 'jpg', 90);
 */
final class Bridge
{
    private static ?self $instance = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public static function fromSettingFile(?string $settingFile = null): self
    {
        return new self(Config::load($settingFile));
    }

    /**
     * Buat bridge dari array konfigurasi (dipakai framework seperti CodeIgniter/Laravel),
     * nilai null/kosong akan memakai default dari setting.txt.
     *
     * @param array<string, mixed> $overrides
     */
    public static function fromArray(array $overrides = []): self
    {
        $cfg = Config::load();

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $cfg[$key] = $value;
        }

        $cfg['base_url'] = sprintf('http://%s:%d', $cfg['server_ip'], $cfg['http_port']);

        return new self($cfg);
    }

    public static function instance(): self
    {
        return self::$instance ??= self::fromSettingFile();
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function client(): OrthancClient
    {
        return new OrthancClient($this->config);
    }

    public function worklist(): WorklistService
    {
        return new WorklistService($this->client(), $this->config);
    }

    public function studies(): StudyService
    {
        return new StudyService($this->client());
    }

    /**
     * Auto expertise (draf bacaan AI) memakai model vision di server Ollama LAN.
     */
    public function expertise(): OllamaExpertise
    {
        return new OllamaExpertise(
            $this->studies(),
            (string) ($this->config['ollama_url'] ?? ''),
            (string) ($this->config['ollama_model'] ?? 'qwen3.5:9b'),
            max(5, (int) ($this->config['ollama_timeout'] ?? 180)),
            dirname(__DIR__) . '/data/expertise'
        );
    }

    public function expertiseEnabled(): bool
    {
        return (bool) ($this->config['ollama_enabled'] ?? false);
    }

    /**
     * Ringkasan kesehatan server untuk endpoint /health.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $client = $this->client();
        $system = $client->system();
        $stats = $client->statistics();

        return [
            'reachable'     => true,
            'base_url'      => $client->baseUrl(),
            'version'       => $system['Version'] ?? null,
            'dicom_aet'     => $system['DicomAet'] ?? null,
            'dicom_port'    => $system['DicomPort'] ?? null,
            'count_studies' => $stats['CountStudies'] ?? null,
            'count_patients'=> $stats['CountPatients'] ?? null,
            'count_instances' => $stats['CountInstances'] ?? null,
            'worklist'      => $this->worklistStatus($client),
        ];
    }

    /**
     * Status service worklist (plugin orthanc-worklists) terpisah dari status Orthanc:
     * Orthanc bisa hidup tapi plugin worklist mati/tidak terpasang.
     *
     * @return array{reachable: bool, count: int|null, error: string|null}
     */
    private function worklistStatus(OrthancClient $client): array
    {
        try {
            $items = $client->worklists();

            return [
                'reachable' => true,
                'count'     => is_array($items) ? count($items) : 0,
                'error'     => null,
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'count'     => null,
                'error'     => $e->getMessage(),
            ];
        }
    }
}
