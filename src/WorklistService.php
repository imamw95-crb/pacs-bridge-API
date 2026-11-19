<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use InvalidArgumentException;

/**
 * Service worklist (Modality Worklist) di Orthanc.
 *
 * Payload memakai format "Tags" (format "Content" lama TIDAK dipakai oleh plugin
 * orthanc-worklists versi mainline).
 */
final class WorklistService
{
    public function __construct(
        private readonly OrthancClient $client,
        private readonly array $config,
    ) {
    }

    /**
     * Buat order baru di worklist.
     *
     * Field wajib: patient_name, patient_id, accession_number, procedure.
     * Opsional: title, modality, station_aet, start_date, start_time, sps_id, sps_status, study_instance_uid.
     *
     * @param array<string, string|null> $order
     * @return array{id: string, patient_name: string, accession_number: string}
     */
    public function create(array $order): array
    {
        $tags = $this->buildTags($order, true);
        /** @var array<string, mixed> $response */
        $response = $this->client->createWorklist(['Tags' => $tags]);

        return [
            'id'               => (string) ($response['ID'] ?? ''),
            'patient_name'     => (string) $tags['0010,0010'],
            'accession_number' => (string) $tags['0008,0050'],
        ];
    }

    /**
     * @param array<string, string|null> $order
     * @return array{id: string, patient_name: string, accession_number: string}
     */
    public function update(string $id, array $order): array
    {
        $tags = $this->buildTags($order, false);
        $this->client->updateWorklist($id, ['Tags' => $tags]);

        return [
            'id'               => $id,
            'patient_name'     => (string) $tags['0010,0010'],
            'accession_number' => (string) $tags['0008,0050'],
        ];
    }

    public function delete(string $id): void
    {
        $this->client->deleteWorklist($id);
    }

    /**
     * Nomor AccessionNumber baru mengikuti format alat di Orthanc:
     * tanggal (D M YYYY) + jam (HHMMSS + milidetik) tanpa angka nol di depan,
     * contoh 1411202654722880 (maks 16 karakter sesuai VR SH).
     *
     * Nomor yang sudah dipakai worklist dilewati.
     */
    public function nextAccessionNumber(): string
    {
        $now = microtime(true);
        $ts = (int) $now;
        $base = date('j', $ts) . date('n', $ts) . date('Y', $ts);
        $seconds = (int) date('His', $ts);
        $millis = (int) (($now - $ts) * 1000);
        $used = $this->existingAccessions();

        foreach ([(int) ($seconds . sprintf('%03d', $millis)), $seconds] as $start) {
            for ($i = 0; $i < 600; $i++) {
                $candidate = $base . ($start + $i);
                if (strlen($candidate) <= 16 && !isset($used[$candidate])) {
                    return $candidate;
                }
            }
        }

        do {
            $candidate = $base . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (isset($used[$candidate]));

        return $candidate;
    }

    /**
     * @return array<string, true>
     */
    private function existingAccessions(): array
    {
        $used = [];
        foreach ($this->client->worklists() as $item) {
            $accession = (string) ($item['Tags']['AccessionNumber'] ?? '');
            if ($accession !== '') {
                $used[$accession] = true;
            }
        }

        return $used;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->worklist($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->client->worklists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByAccession(string $accessionNumber): ?array
    {
        foreach ($this->client->worklists() as $worklist) {
            if (!is_array($worklist)) {
                continue;
            }
            $tags = $worklist['Tags'] ?? [];
            if (isset($tags['AccessionNumber']) && $tags['AccessionNumber'] === $accessionNumber) {
                return $worklist;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $order
     * @return array<string, mixed>
     */
    private function buildTags(array $order, bool $withDefaults): array
    {
        $patientName = trim((string) ($order['patient_name'] ?? ''));
        $patientId = trim((string) ($order['patient_id'] ?? ''));
        $accession = trim((string) ($order['accession_number'] ?? ''));
        $procedure = trim((string) ($order['procedure'] ?? ''));

        if ($patientName === '' || $patientId === '' || $accession === '' || $procedure === '') {
            throw new InvalidArgumentException(
                'patient_name, patient_id, accession_number, dan procedure wajib diisi'
            );
        }

        $title = trim((string) ($order['title'] ?? ''));
        $formattedName = $title !== ''
            ? PatientName::safe($patientName, $title)
            : PatientName::safe($patientName);

        $modality = (string) ($order['modality'] ?? $this->config['modality'] ?? 'DX');
        $station = (string) ($order['station_aet'] ?? $this->config['calling_aet'] ?? 'LEAD_CLIENT');
        $startDate = (string) ($order['start_date'] ?? ($withDefaults ? date('Ymd') : date('Ymd')));
        $startTime = (string) ($order['start_time'] ?? date('His'));

        if (!preg_match('/^\d{8}$/', $startDate)) {
            throw new InvalidArgumentException('start_date harus format Ymd, contoh 20261113');
        }

        $tags = [
            '0008,0050' => $accession,
            '0010,0010' => $formattedName,
            '0010,0020' => $patientId,
            '0032,1060' => $procedure,
            '0040,0100' => [[
                '0008,0060' => $modality,
                '0040,0001' => $station,
                '0040,0002' => $startDate,
                '0040,0003' => $startTime,
                '0040,0007' => $procedure,
                '0040,0009' => (string) ($order['sps_id'] ?? 'SPS-' . $accession),
                // Alat Siemens (syngo CT View&GO) memfilter MWL dengan
                // ScheduledProcedureStepStatus=SCHEDULED; tanpa tag ini hasil C-FIND kosong.
                '0040,0020' => (string) ($order['sps_status'] ?? 'SCHEDULED'),
            ]],
        ];

        foreach (['0010,0030' => 'birth_date', '0010,0040' => 'sex'] as $tag => $key) {
            $value = trim((string) ($order[$key] ?? ''));
            if ($value !== '') {
                $tags[$tag] = $value;
            }
        }

        $studyUid = trim((string) ($order['study_instance_uid'] ?? ''));
        if ($studyUid !== '') {
            $tags['0020,000D'] = $studyUid;
        }

        // AccessionNumber & Procedure juga dicerminkan di tag prosedur bila ada
        if (isset($order['requested_procedure_id']) && $order['requested_procedure_id'] !== '') {
            $tags['0040,1001'] = (string) $order['requested_procedure_id'];
        }

        return $tags;
    }
}
