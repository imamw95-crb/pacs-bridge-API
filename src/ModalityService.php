<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use InvalidArgumentException;

/**
 * Pendaftaran modality DICOM ("DicomModalities") ke Orthanc lewat REST.
 *
 * Orthanc 1.12 mendukung `PUT /modalities/{id}`, jadi alat baru bisa didaftarkan dari
 * dashboard tanpa mengedit /etc/orthanc/orthanc.json. Perubahan itu hanya bertahan
 * setelah container di-restart bila Orthanc dijalankan dengan
 * `"DicomModalitiesInDatabase": true` (default-nya hanya di memori).
 */
final class ModalityService
{
    /** Flag untuk alat radiologi: menarik MWL (C-FIND) sekaligus mengirim gambar (C-STORE). */
    private const DEVICE_FLAGS = [
        'AllowEcho'         => true,
        'AllowFind'         => true,
        'AllowFindWorklist' => true,
        'AllowStore'        => true,
        'AllowGet'          => true,
        'AllowMove'         => true,
    ];

    private const AET_PATTERN = '/^[A-Za-z0-9_\-]{1,16}$/';
    private const HOST_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-]{0,63}$/';

    public function __construct(private readonly OrthancClient $client)
    {
    }

    /**
     * Modality yang saat ini terdaftar di Orthanc (+ ringkasan konfigurasinya).
     *
     * @return list<array{id: string, aet: string, host: string, port: int, allow_find_worklist: bool, allow_store: bool}>
     */
    public function list(): array
    {
        $out = [];

        foreach ($this->client->modalities() as $id) {
            $row = [
                'id'                  => $id,
                'aet'                 => $id,
                'host'                => '',
                'port'                => 0,
                'allow_find_worklist' => false,
                'allow_store'         => false,
            ];

            try {
                $config = $this->client->modalityConfiguration($id);
                $row['aet'] = (string) ($config['AET'] ?? $id);
                $row['host'] = (string) ($config['Host'] ?? '');
                $row['port'] = (int) ($config['Port'] ?? 0);
                $row['allow_find_worklist'] = (bool) ($config['AllowFindWorklist'] ?? false);
                $row['allow_store'] = (bool) ($config['AllowStore'] ?? false);
            } catch (OrthancException) {
                // Orthanc lama tanpa /configuration: cukup tampilkan namanya.
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Daftarkan (atau perbarui) modality Orthanc memakai data alat di Pengaturan.
     *
     * Flag yang sudah ada dipertahankan (mis. `Timeout`, `RetrieveMethod`), hanya
     * AET/Host/Port dan flag MWL/C-STORE yang ditimpa.
     *
     * @param array{aet?: string, host?: string, port?: int} $device
     * @return array<string, mixed> konfigurasi yang tersimpan di Orthanc
     */
    public function register(array $device, ?string $host = null, ?int $port = null): array
    {
        $aet = trim((string) ($device['aet'] ?? ''));
        if (!preg_match(self::AET_PATTERN, $aet)) {
            throw new InvalidArgumentException('AE Title alat tidak valid: ' . $aet);
        }

        $targetHost = trim((string) ($host ?? ($device['host'] ?? '')));
        if ($targetHost === '') {
            throw new InvalidArgumentException('IP/host alat belum diisi — lengkapi kolom IP alat di Pengaturan.');
        }
        if (!preg_match(self::HOST_PATTERN, $targetHost)) {
            throw new InvalidArgumentException('IP/host alat tidak valid: ' . $targetHost);
        }

        $targetPort = (int) ($port ?? ($device['port'] ?? 0));
        if ($targetPort === 0) {
            $targetPort = 104;
        }
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new InvalidArgumentException('Port DICOM alat harus 1-65535.');
        }

        $config = $this->existingConfiguration($aet);
        if (empty($config['Manufacturer'])) {
            $config['Manufacturer'] = 'Generic';
        }
        $config['AET'] = $aet;
        $config['Host'] = $targetHost;
        $config['Port'] = $targetPort;
        foreach (self::DEVICE_FLAGS as $flag => $value) {
            $config[$flag] = $value;
        }

        $this->client->registerModality($aet, $config);

        return $this->existingConfiguration($aet);
    }

    /**
     * C-ECHO ke alat untuk memastikan alamat/port/AET-nya benar.
     *
     * @return array<string, mixed>
     */
    public function echo(string $aet, int $timeout = 5): array
    {
        if (!preg_match(self::AET_PATTERN, $aet)) {
            throw new InvalidArgumentException('AE Title alat tidak valid: ' . $aet);
        }

        return $this->client->modalityEcho($aet, $timeout);
    }

    /**
     * Ringkas alasan kegagalan C-ECHO dari body error Orthanc agar bisa ditampilkan ke petugas.
     *
     * Contoh body:
     * {"Details":"DicomAssociation - connecting to AET \"LEAD_CLIENT\": DUL Association Rejected"}
     */
    public static function echoFailureReason(string $body): string
    {
        $details = '';
        $data = json_decode($body, true);
        if (is_array($data)) {
            $details = trim((string) ($data['Details'] ?? $data['Message'] ?? ''));
        }
        if ($details === '') {
            $details = trim($body);
        }
        if ($details === '') {
            return 'Orthanc tidak memberi detail';
        }

        $lower = strtolower($details);
        $hints = [
            'dul association rejected' => 'ditolak tujuan (DUL Association Rejected) — AET/IP/port tujuan bukan alat yang dituju',
            'called ae title'          => 'AET tujuan tidak cocok (Called AE Title) — periksa AE Title alat',
            'connection refused'       => 'port tujuan tertutup (connection refused) — periksa port DICOM alat',
            'no route to host'         => 'IP tujuan tidak terjangkau (no route to host) — periksa IP/jaringan alat',
            'timeout'                  => 'tidak ada jawaban sampai timeout — alat tidak merespons',
            'unknown modality'         => 'AE Title belum terdaftar di Orthanc',
        ];
        foreach ($hints as $needle => $hint) {
            if (str_contains($lower, $needle)) {
                return $hint;
            }
        }

        return $details;
    }

    /**
     * Gabungkan alat di Pengaturan dengan modality yang benar-benar terdaftar di Orthanc.
     *
     * @param list<array<string, mixed>> $devices
     * @param list<array<string, mixed>> $registered hasil list()
     * @return list<array<string, mixed>>
     */
    public static function mergeDevices(array $devices, array $registered): array
    {
        $byId = [];
        foreach ($registered as $row) {
            $byId[strtolower((string) $row['id'])] = $row;
        }

        $out = [];
        foreach ($devices as $device) {
            $aet = (string) ($device['aet'] ?? '');
            $match = $byId[strtolower($aet)] ?? null;
            $out[] = [
                'name'       => (string) ($device['name'] ?? $aet),
                'aet'        => $aet,
                'modality'   => (string) ($device['modality'] ?? ''),
                'host'       => (string) ($device['host'] ?? ''),
                'port'       => (int) ($device['port'] ?? 104),
                'registered' => $match !== null,
                'orthanc'    => $match,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function existingConfiguration(string $aet): array
    {
        try {
            return $this->client->modalityConfiguration($aet);
        } catch (OrthancException $e) {
            if ($e->statusCode === 404) {
                return [];
            }
            throw $e;
        }
    }
}
