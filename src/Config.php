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
                    $cfg[$key] = in_array($key, ['dicom_port', 'http_port', 'timeout'], true)
                        ? (int) $stored[$key]
                        : $stored[$key];
                }
            }
        }

        foreach ($envMap as $key => $envName) {
            $value = getenv($envName);
            if ($value === false || $value === '') {
                continue;
            }
            $cfg[$key] = in_array($key, ['dicom_port', 'http_port', 'timeout'], true)
                ? (int) $value
                : $value;
        }

        $cfg['base_url'] = sprintf('http://%s:%d', $cfg['server_ip'], $cfg['http_port']);

        return $cfg;
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
