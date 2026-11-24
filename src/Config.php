<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Memuat konfigurasi dari setting.txt (sumber yang sama dengan aplikasi client),
 * dengan opsi override lewat environment variable.
 */
final class Config
{
    /**
     * @return array<string, mixed>
     */
    public static function load(?string $settingFile = null): array
    {
        $settingFile ??= dirname(__DIR__) . '/setting.txt';
        $parsed = self::parseSettingFile($settingFile);

        $cfg = [
            'server_ip'   => $parsed['SERVER IP'] ?? '10.4.0.10',
            'dicom_port'  => (int) ($parsed['SERVER PORT'] ?? 4242),
            'http_port'   => 8042,
            'called_aet'  => $parsed['CALLED AE TITLE'] ?? 'ORTHANC',
            'calling_aet' => $parsed['CALLING AE TITLE'] ?? 'LEAD_CLIENT',
            'modality'    => $parsed['MODALITY'] ?? 'DX',
            'client_ip'   => $parsed['CLIENT IP'] ?? '',
            'timeout'     => 30,
            'user'        => null,
            'password'    => null,
            'api_key'     => null,
            'ollama_enabled' => false,
            'ollama_url'     => self::defaultOllamaUrl(),
            'ollama_model'   => 'qwen3.5:9b',
            'ollama_timeout' => 180,
        ];

        $envMap = [
            'server_ip'   => 'PACS_SERVER_IP',
            'dicom_port'  => 'PACS_DICOM_PORT',
            'http_port'   => 'PACS_HTTP_PORT',
            'called_aet'  => 'PACS_CALLED_AET',
            'calling_aet' => 'PACS_CALLING_AET',
            'modality'    => 'PACS_MODALITY',
            'timeout'     => 'PACS_TIMEOUT',
            'user'        => 'PACS_HTTP_USER',
            'password'    => 'PACS_HTTP_PASS',
            'api_key'     => 'PACS_BRIDGE_KEY',
            'ollama_enabled' => 'PACS_OLLAMA_ENABLED',
            'ollama_url'     => 'PACS_OLLAMA_URL',
            'ollama_model'   => 'PACS_OLLAMA_MODEL',
            'ollama_timeout' => 'PACS_OLLAMA_TIMEOUT',
        ];

        // Lapisan kedua: pengaturan dari dashboard (data/settings.json)
        $storedFile = dirname(__DIR__) . '/data/settings.json';
        if (is_file($storedFile)) {
            $stored = json_decode((string) file_get_contents($storedFile), true);
            if (is_array($stored)) {
                foreach (array_keys($envMap) as $key) {
                    if (!isset($stored[$key]) || $stored[$key] === '') {
                        continue;
                    }
                    $cfg[$key] = self::cast($key, $stored[$key]);
                }
            }
        }

        foreach ($envMap as $key => $envName) {
            $value = getenv($envName);
            if ($value === false || $value === '') {
                continue;
            }
            $cfg[$key] = self::cast($key, $value);
        }

        $cfg['base_url'] = sprintf('http://%s:%d', $cfg['server_ip'], $cfg['http_port']);

        return $cfg;
    }

    /**
     * @param mixed $value
     * @return mixed nilai sesuai tipe key (int/bool/string)
     */
    private static function cast(string $key, $value)
    {
        if (in_array($key, ['dicom_port', 'http_port', 'timeout', 'ollama_timeout'], true)) {
            return (int) $value;
        }
        if ($key === 'ollama_enabled') {
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $value;
    }

    /**
     * URL Ollama bawaan dari `data/integrasiollama/ip ollama` (diisi manual saat
     * menyiapkan server AI). Contoh isi: `server Ollama 192.168.0.9`.
     */
    private static function defaultOllamaUrl(): string
    {
        $file = dirname(__DIR__) . '/data/integrasiollama/ip ollama';
        if (!is_readable($file)) {
            return '';
        }

        $content = (string) file_get_contents($file);
        if (preg_match('#https?://[^\s]+#i', $content, $m) === 1) {
            return rtrim($m[0], '/');
        }
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $content, $m) === 1) {
            return 'http://' . $m[1] . ':11434';
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private static function parseSettingFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $result = [];
        $content = (string) file_get_contents($path);

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = strtoupper(trim($key));
            if ($key !== '') {
                $result[$key] = trim($value);
            }
        }

        return $result;
    }
}
