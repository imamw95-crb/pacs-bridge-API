<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use RuntimeException;
use InvalidArgumentException;

/**
 * Service baca study & gambar dari Orthanc.
 */
final class StudyService
{
    public const MIME_PNG = 'image/png';
    public const MIME_JPEG = 'image/jpeg';
    public const MIME_DICOM = 'application/dicom';
    public const MIME_ZIP = 'application/zip';

    /** Pola ID resource Orthanc, mis. 142ca2db-e3ae40d0-e9b328a0-6a77ec35-80702d8c. */
    private const ORTHANC_ID_PATTERN = '/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{8}){4}$/';

    /** Pola DICOM UID, mis. 1.2.840.113619.2.511... */
    private const DICOM_UID_PATTERN = '/^[0-9]+(?:\.[0-9]+)+$/';

    /** Maksimum tanggal berbeda yang modalitasnya dicari per request (1 request per tanggal). */
    private const MODALITY_DATE_MAX = 8;

    /**
     * Modalitas yang gambarnya TIDAK diberi watermark: citra USG/ECG sudah
     * memuat identitas pasien dari alat, sehingga watermark hanya menutupi
     * gambar (tracing EKG) atau tercetak dobel di lembar hasil.
     *
     * @var list<string>
     */
    private const NO_WATERMARK_MODALITIES = ['US', 'ECG'];

    /** @var array<string, array<string, string>> memo peta modalitas per tanggal (Ymd) */
    private array $modalityDateMemo = [];

    public function __construct(private readonly OrthancClient $client)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByAccession(string $accessionNumber): array
    {
        /** @var list<array<string, mixed>> $studies */
        $studies = $this->client->find(['AccessionNumber' => $accessionNumber], 'Study', 0, true);

        return $studies;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByPatientId(string $patientId, int $limit = 20): array
    {
        return $this->findStudiesOrdered(['PatientID' => $patientId], $limit);
    }

    /**
     * Cari study berdasarkan nama pasien (parsial).
     *
     * Nilai PatientName di PACS memakai pemisah berbeda-beda (`ADI WATIK, NY`,
     * `ADI^WATIK^NY`, `NEYSHA_RULIA_WATI_AN`), jadi input dipecah per kata lalu
     * digabung dengan wildcard `*` yang didukung `POST /tools/find` Orthanc —
     * mis. "adi watik" menjadi `*ADI*WATIK*`. Pencocokan wildcard itu tidak peka
     * huruf besar/kecil, tetapi input tetap di-`strtoupper` agar konsisten.
     *
     * @return list<array<string, mixed>>
     */
    public function findByPatientName(string $name, int $limit = 50): array
    {
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper(trim($name)), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return [];
        }

        $pattern = '*' . implode('*', array_slice($words, 0, 8)) . '*';

        return $this->findStudiesOrdered(['PatientName' => $pattern], $limit);
    }

    /**
     * Cari satu study dari kunci apa pun: AccessionNumber, ID Orthanc, atau
     * DICOM UID (StudyInstanceUID / SeriesInstanceUID / SOPInstanceUID).
     *
     * Diperlukan karena sebagian alat (mis. workstation USG) mengirim study
     * tanpa AccessionNumber, sehingga study seperti itu tidak bisa diakses
     * lewat nomor accession.
     *
     * @return array<string, mixed>|null
     */
    public function findOneByKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (preg_match(self::DICOM_UID_PATTERN, $key) === 1) {
            return $this->findOneByUid($key);
        }

        if (preg_match(self::ORTHANC_ID_PATTERN, $key) === 1) {
            return $this->studyOrNull($key);
        }

        $studies = $this->findByAccession($key);

        return $studies[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOneByUid(string $uid): ?array
    {
        foreach ($this->client->lookup($uid) as $hit) {
            $id = (string) ($hit['ID'] ?? '');
            if ($id === '') {
                continue;
            }

            $studyId = match (strtolower((string) ($hit['Type'] ?? ''))) {
                'study'    => $id,
                'series'   => (string) ($this->client->series($id)['ParentStudy'] ?? ''),
                'instance' => $this->studyIdOfInstance($id),
                default    => '',
            };

            $study = $studyId !== '' ? $this->studyOrNull($studyId) : null;
            if ($study !== null) {
                return $study;
            }
        }

        return null;
    }

    private function studyIdOfInstance(string $instanceId): string
    {
        $seriesId = (string) ($this->client->instance($instanceId)['ParentSeries'] ?? '');

        return $seriesId !== '' ? (string) ($this->client->series($seriesId)['ParentStudy'] ?? '') : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function studyOrNull(string $studyId): ?array
    {
        try {
            $study = $this->client->study($studyId);
        } catch (OrthancException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }

        return $study === [] ? null : $study;
    }

    /**
     * Lengkapi `MainDicomTags.Modality` tiap study dengan modalitas seri-seri nya.
     *
     * Orthanc tidak menyimpan Modality (0008,0060) di level Study — tag itu ada
     * di level Series — sehingga tanpa ini semua study tampil sebagai "DX".
     *
     * Modalitas diambil per tanggal (satu request per tanggal), bukan per study,
     * supaya daftar study tidak memicu puluhan request.
     *
     * @param list<array<string, mixed>> $studies
     * @param string|null $date filter StudyDate (Ymd) yang dipakai saat mencari
     * @return list<array<string, mixed>>
     */
    public function attachModalities(array $studies, ?string $date = null): array
    {
        if ($studies === []) {
            return $studies;
        }

        $map = [];
        foreach ($this->modalityDates($studies, $date) as $day) {
            $map += $this->modalitiesByDate($day);
        }

        foreach ($studies as $i => $study) {
            $id = (string) ($study['ID'] ?? '');
            if ($id === '' || !isset($map[$id])) {
                continue;
            }

            $tags = is_array($studies[$i]['MainDicomTags'] ?? null) ? $studies[$i]['MainDicomTags'] : [];
            $tags['Modality'] = $map[$id];
            $studies[$i]['MainDicomTags'] = $tags;
        }

        return $studies;
    }

    /**
     * Saring study berdasarkan kode modalitas (mis. `US` = USG, `DX` = rontgen).
     *
     * Study dengan modalitas gabungan (`DX/US`) ikut cocok bila salah satu sama.
     * Modalitas kosong = tidak menyaring.
     *
     * @param list<array<string, mixed>> $studies
     * @return list<array<string, mixed>>
     */
    public function filterByModality(array $studies, string $modality): array
    {
        $modality = strtoupper(trim($modality));
        if ($modality === '') {
            return $studies;
        }

        return array_values(array_filter($studies, static function (array $study) use ($modality): bool {
            $value = strtoupper((string) ($study['MainDicomTags']['Modality'] ?? ''));

            return in_array($modality, explode('/', $value), true);
        }));
    }

    /**
     * Tanggal yang perlu dicari modalitasnya (dibatasi MODALITY_DATE_MAX).
     *
     * @param list<array<string, mixed>> $studies
     * @return list<string>
     */
    private function modalityDates(array $studies, ?string $date): array
    {
        if ($date !== null && $date !== '') {
            return [$date];
        }

        $days = [];
        foreach ($studies as $study) {
            $day = (string) ($study['MainDicomTags']['StudyDate'] ?? '');
            if (preg_match('/^\d{8}$/', $day) === 1 && !in_array($day, $days, true)) {
                $days[] = $day;
                if (count($days) >= self::MODALITY_DATE_MAX) {
                    break;
                }
            }
        }

        return $days;
    }

    /**
     * Peta ID study => modalitas untuk satu tanggal, satu request ke Orthanc.
     *
     * @return array<string, string>
     */
    private function modalitiesByDate(string $date): array
    {
        if (isset($this->modalityDateMemo[$date])) {
            return $this->modalityDateMemo[$date];
        }

        try {
            /** @var list<array<string, mixed>> $series */
            $series = $this->client->find(['StudyDate' => $date], 'Series', 0, true);
        } catch (OrthancException) {
            return $this->modalityDateMemo[$date] = [];
        }

        $map = [];
        foreach ($series as $item) {
            $studyId = (string) ($item['ParentStudy'] ?? '');
            $modality = strtoupper(trim((string) ($item['MainDicomTags']['Modality'] ?? '')));
            if ($studyId === '' || $modality === '') {
                continue;
            }
            if (!isset($map[$studyId])) {
                $map[$studyId] = $modality;
            } elseif (!in_array($modality, explode('/', $map[$studyId]), true)) {
                $map[$studyId] .= '/' . $modality;
            }
        }

        return $this->modalityDateMemo[$date] = $map;
    }

    /**
     * Study dengan StudyDate setelah tanggal tertentu (mis. jam mesin alat maju
     * sehari sehingga study "hari ini" bertanggal besok), urut terbaru dulu.
     *
     * @return list<array<string, mixed>>
     */
    public function newerThan(string $date, int $limit = 24): array
    {
        $next = date('Ymd', (int) strtotime($date . ' +1 day'));

        return $this->findStudiesOrdered(['StudyDate' => $next . '-'], $limit);
    }

    /**
     * Study terbaru (urut tanggal + jam study menurun).
     *
     * @param string|null $date filter tanggal StudyDate (Ymd); null = semua
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 12, ?string $date = null): array
    {
        $query = $date !== null && $date !== '' ? ['StudyDate' => $date] : [];

        return $this->findStudiesOrdered($query, $limit);
    }

    /**
     * Cari study dengan urutan terbaru lebih dulu.
     *
     * Pengurutan dilakukan di Orthanc (`OrderBy`, butuh ExtendedFind pada Orthanc
     * >= 1.12.5) supaya `Limit` memotong data yang sudah terurut. Tanpa itu
     * Orthanc mengembalikan urutan internal database, sehingga study terbaru
     * bisa hilang dari hasil bila hanya di-sort setelah di-limit.
     *
     * @param array<string, string> $query
     * @return list<array<string, mixed>>
     */
    private function findStudiesOrdered(array $query, int $limit): array
    {
        $orderBy = [
            ['Type' => 'DicomTag', 'Key' => 'StudyDate', 'Direction' => 'DESC'],
            ['Type' => 'DicomTag', 'Key' => 'StudyTime', 'Direction' => 'DESC'],
        ];

        try {
            /** @var list<array<string, mixed>> $studies */
            $studies = $this->client->find($query, 'Study', $limit, true, $orderBy);

            return $studies;
        } catch (OrthancException $e) {
            // Orthanc lama / backend tanpa ExtendedFind: ambil semua lalu urutkan lokal.
            /** @var list<array<string, mixed>> $studies */
            $studies = $this->client->find($query, 'Study', 0, true);

            return $limit > 0 ? array_slice($this->sortByStudyTimeDesc($studies), 0, $limit) : $this->sortByStudyTimeDesc($studies);
        }
    }

    /**
     * @param list<array<string, mixed>> $studies
     * @return list<array<string, mixed>>
     */
    private function sortByStudyTimeDesc(array $studies): array
    {
        usort($studies, static function (array $a, array $b): int {
            $ka = ($a['MainDicomTags']['StudyDate'] ?? '') . ($a['MainDicomTags']['StudyTime'] ?? '');
            $kb = ($b['MainDicomTags']['StudyDate'] ?? '') . ($b['MainDicomTags']['StudyTime'] ?? '');

            return $kb <=> $ka;
        });

        return $studies;
    }

    /**
     * @return array<string, mixed>
     */
    public function study(string $studyId): array
    {
        return $this->client->study($studyId);
    }

    /**
     * @return list<string>
     */
    public function instances(string $studyId): array
    {
        return $this->client->studyInstances($studyId);
    }

    /**
     * Render PNG (native Orthanc). Mendukung resize lewat width/height.
     */
    public function renderPng(string $instanceId, ?int $width = null, ?int $height = null): string
    {
        return $this->client->binary(
            '/instances/' . rawurlencode($instanceId) . '/rendered',
            ['width' => $width, 'height' => $height],
            self::MIME_PNG
        );
    }

    /**
     * Render JPEG (DICOMweb). Mendukung quality; parameter width diabaikan server.
     */
    public function renderJpeg(string $studyUid, string $seriesUid, string $instanceUid, int $quality = 90): string
    {
        $path = sprintf(
            '/dicom-web/studies/%s/series/%s/instances/%s/rendered',
            rawurlencode($studyUid),
            rawurlencode($seriesUid),
            rawurlencode($instanceUid)
        );

        return $this->client->binary($path, ['quality' => $quality], self::MIME_JPEG);
    }

    /**
     * Ambil UID study/series/instance dari sebuah study Orthanc.
     *
     * @return array{study_uid: string, series_uid: string, instance_uid: string, instance_id: string, modality: string}|null
     */
    public function firstInstance(string $studyId): ?array
    {
        $study = $this->client->study($studyId);
        $seriesIds = $study['Series'] ?? [];
        $studyUid = $study['MainDicomTags']['StudyInstanceUID'] ?? '';
        if (!is_array($seriesIds) || $seriesIds === [] || $studyUid === '') {
            return null;
        }

        $series = $this->client->series((string) $seriesIds[0]);
        $instanceIds = $series['Instances'] ?? [];
        if (!is_array($instanceIds) || $instanceIds === []) {
            return null;
        }

        $instanceId = (string) $instanceIds[0];
        $instance = $this->client->instance($instanceId);

        return [
            'study_uid'   => (string) $studyUid,
            'series_uid'  => (string) ($series['MainDicomTags']['SeriesInstanceUID'] ?? ''),
            'instance_uid'=> (string) ($instance['MainDicomTags']['SOPInstanceUID'] ?? ''),
            'instance_id' => $instanceId,
            'modality'    => strtoupper(trim((string) ($series['MainDicomTags']['Modality'] ?? ''))),
        ];
    }

    /**
     * Apakah watermark boleh digambar untuk modalitas ini.
     */
    private static function watermarkAllowed(string $modality): bool
    {
        return !in_array(strtoupper(trim($modality)), self::NO_WATERMARK_MODALITIES, true);
    }

    /**
     * Gambar untuk satu AccessionNumber.
     *
     * Catatan: bila study memuat SOPInstanceUID duplikat, endpoint DICOMweb
     * menolak ("Multiple Instance found for WADO-RS") sehingga otomatis
     * fallback ke render PNG native Orthanc.
     *
     * @param string $format 'jpg' atau 'png'
     * @param bool $watermark tambahkan watermark identitas pasien (hasil selalu JPEG)
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    public function imageByAccession(string $accessionNumber, string $format = 'jpg', int $quality = 90, ?int $width = null, bool $watermark = false): ?array
    {
        $studies = $this->findByAccession($accessionNumber);
        if ($studies === []) {
            return null;
        }

        return $this->imageForStudy($studies[0], $accessionNumber, $format, $quality, $width, $watermark);
    }

    /**
     * Seperti imageByAccession(), tetapi kuncinya boleh AccessionNumber, ID
     * Orthanc, atau DICOM UID (study tanpa accession tetap bisa diambil).
     *
     * @param string $format 'jpg' atau 'png'
     * @param bool $watermark tambahkan watermark identitas pasien (hasil selalu JPEG)
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    public function imageByKey(string $key, string $format = 'jpg', int $quality = 90, ?int $width = null, bool $watermark = false): ?array
    {
        $study = $this->findOneByKey($key);
        if ($study === null) {
            return null;
        }

        return $this->imageForStudy($study, $this->fileBase($study, $key), $format, $quality, $width, $watermark);
    }

    /**
     * @param array<string, mixed> $study
     * @param string $fileBase nama file keluaran (accession / No. RM)
     * @return array{content: string, mime: string, filename: string, study_id: string}|null
     */
    private function imageForStudy(
        array $study,
        string $fileBase,
        string $format,
        int $quality,
        ?int $width,
        bool $watermark
    ): ?array {
        $studyId = (string) ($study['ID'] ?? '');
        if ($studyId === '') {
            return null;
        }

        $ref = $this->firstInstance($studyId);
        if ($ref === null) {
            return null;
        }

        // USG: citra sudah memuat identitas pasien dari alat -> tanpa watermark.
        $watermark = $watermark && self::watermarkAllowed($ref['modality']);
        if (strtolower($format) === 'jpg') {
            try {
                $image = [
                    'content'  => $this->renderJpeg($ref['study_uid'], $ref['series_uid'], $ref['instance_uid'], $quality),
                    'mime'     => self::MIME_JPEG,
                    'filename' => sprintf('%s.jpg', $fileBase),
                    'study_id' => $studyId,
                ];

                return $watermark ? $this->withWatermark($image, $study, $quality) : $image;
            } catch (OrthancException $e) {
                // 404 = SOPInstanceUID ambigu di DICOMweb -> pakai render native (PNG)
                if ($e->statusCode !== 404) {
                    throw $e;
                }
            }
        }

        $image = [
            'content'  => $this->renderPng($ref['instance_id'], $width),
            'mime'     => self::MIME_PNG,
            'filename' => sprintf('%s.png', $fileBase),
            'study_id' => $studyId,
        ];

        return $watermark ? $this->withWatermark($image, $study, $quality) : $image;
    }

    /**
     * Nama dasar file untuk study tanpa AccessionNumber.
     *
     * @param array<string, mixed> $study
     */
    private function fileBase(array $study, string $fallback): string
    {
        $accession = (string) ($study['MainDicomTags']['AccessionNumber'] ?? '');
        if ($accession !== '') {
            return $accession;
        }

        $patientId = (string) ($study['PatientMainDicomTags']['PatientID'] ?? '');

        return $patientId !== '' ? $patientId : $fallback;
    }

    /**
     * @param array{content: string, mime: string, filename: string, study_id: string} $image
     * @param array<string, mixed> $study
     * @return array{content: string, mime: string, filename: string, study_id: string}
     */
    private function withWatermark(array $image, array $study, int $quality): array
    {
        $watermark = new WatermarkService();

        return [
            'content'  => $watermark->applyJpeg($image['content'], $this->watermarkInfo($study), $quality),
            'mime'     => self::MIME_JPEG,
            'filename' => preg_replace('/\.\w+$/', '', $image['filename']) . '.jpg',
            'study_id' => $image['study_id'],
        ];
    }

    /**
     * Data identitas untuk watermark.
     *
     * @param array<string, mixed> $study
     * @return array<string, string>
     */
    private function watermarkInfo(array $study): array
    {
        $tags = is_array($study['MainDicomTags'] ?? null) ? $study['MainDicomTags'] : [];
        $patient = is_array($study['PatientMainDicomTags'] ?? null) ? $study['PatientMainDicomTags'] : [];
        $date = (string) ($tags['StudyDate'] ?? '');

        return [
            'name'        => (string) ($patient['PatientName'] ?? ''),
            'pid'         => (string) ($patient['PatientID'] ?? ''),
            'sex'         => (string) ($patient['PatientSex'] ?? ''),
            'date'        => strlen($date) === 8
                ? substr($date, 6, 2) . '/' . substr($date, 4, 2) . '/' . substr($date, 0, 4)
                : $date,
            'desc'        => (string) ($tags['StudyDescription'] ?? ''),
            'institution' => (string) ($tags['InstitutionName'] ?? ''),
            'physician'   => (string) ($tags['ReferringPhysicianName'] ?? ''),
        ];
    }

    public function dicomInstance(string $instanceId): string
    {
        return $this->client->binary('/instances/' . rawurlencode($instanceId) . '/file', [], self::MIME_DICOM);
    }

    /**
     * Daftar instance (gambar) satu study, urut sesuai seri Orthanc.
     *
     * @return list<array{instance_id: string, series_number: string, instance_number: string}>
     */
    public function imageList(string $studyId): array
    {
        $study = $this->client->study($studyId);
        $out = [];

        foreach ((array) ($study['Series'] ?? []) as $seriesId) {
            $series = $this->client->series((string) $seriesId);
            $seriesNo = (string) ($series['MainDicomTags']['SeriesNumber'] ?? '1');

            foreach ((array) ($series['Instances'] ?? []) as $i => $instanceId) {
                $instance = $this->client->instance((string) $instanceId);
                $out[] = [
                    'instance_id'     => (string) $instanceId,
                    'series_number'   => $seriesNo,
                    'instance_number' => (string) ($instance['MainDicomTags']['InstanceNumber'] ?? ($i + 1)),
                ];
            }
        }

        return $out;
    }

    /**
     * Render satu instance menjadi JPEG (fallback PNG native bila DICOMweb menolak).
     *
     * @return array{content: string, mime: string, filename: string}
     */
    public function imageByInstance(string $instanceId, int $quality = 90): array
    {
        $ref = $this->instanceRef($instanceId);

        if ($ref !== null) {
            try {
                return [
                    'content'  => $this->renderJpeg($ref['study_uid'], $ref['series_uid'], $ref['instance_uid'], $quality),
                    'mime'     => self::MIME_JPEG,
                    'filename' => $instanceId . '.jpg',
                ];
            } catch (OrthancException $e) {
                if ($e->statusCode !== 404) {
                    throw $e;
                }
            }
        }

        return [
            'content'  => $this->renderPng($instanceId),
            'mime'     => self::MIME_PNG,
            'filename' => $instanceId . '.png',
        ];
    }

    /**
     * @return array{study_uid: string, series_uid: string, instance_uid: string}|null
     */
    private function instanceRef(string $instanceId): ?array
    {
        $instance = $this->client->instance($instanceId);
        $seriesId = (string) ($instance['ParentSeries'] ?? '');
        if ($seriesId === '') {
            return null;
        }

        $series = $this->client->series($seriesId);
        $studyId = (string) ($series['ParentStudy'] ?? '');
        $study = $studyId !== '' ? $this->client->study($studyId) : [];

        $studyUid = (string) ($study['MainDicomTags']['StudyInstanceUID'] ?? '');
        $seriesUid = (string) ($series['MainDicomTags']['SeriesInstanceUID'] ?? '');
        $instanceUid = (string) ($instance['MainDicomTags']['SOPInstanceUID'] ?? '');
        if ($studyUid === '' || $seriesUid === '' || $instanceUid === '') {
            return null;
        }

        return ['study_uid' => $studyUid, 'series_uid' => $seriesUid, 'instance_uid' => $instanceUid];
    }

    /**
     * Semua instance satu study dirender menjadi JPEG (fallback PNG bila
     * DICOMweb menolak karena SOPInstanceUID ambigu). Secara default tiap
     * gambar diberi watermark identitas pasien, kecuali modalitas US (citra
     * USG sudah memuat identitas pasien dari alat).
     *
     * @return array{patient_id: string, patient_name: string, accession: string, files: list<array{name: string, content: string}>}
     */
    public function imagesByAccession(string $accessionNumber, int $quality = 90, bool $watermark = true): array
    {
        $studies = $this->findByAccession($accessionNumber);
        if ($studies === []) {
            throw new InvalidArgumentException('Study tidak ditemukan untuk accession ' . $accessionNumber);
        }

        return $this->imagesForStudy($studies[0], $accessionNumber, $quality, $watermark);
    }

    /**
     * Seperti imagesByAccession(), tetapi kuncinya boleh AccessionNumber, ID
     * Orthanc, atau DICOM UID.
     *
     * @return array{patient_id: string, patient_name: string, accession: string, files: list<array{name: string, content: string}>}
     */
    public function imagesByKey(string $key, int $quality = 90, bool $watermark = true): array
    {
        $study = $this->findOneByKey($key);
        if ($study === null) {
            throw new InvalidArgumentException('Study tidak ditemukan untuk ' . $key);
        }

        return $this->imagesForStudy($study, $this->fileBase($study, $key), $quality, $watermark);
    }

    /**
     * @param array<string, mixed> $study
     * @return array{patient_id: string, patient_name: string, accession: string, files: list<array{name: string, content: string}>}
     */
    private function imagesForStudy(array $study, string $accession, int $quality, bool $watermark): array
    {
        $studyUid = (string) ($study['MainDicomTags']['StudyInstanceUID'] ?? '');
        $info = $this->watermarkInfo($study);
        $watermarker = $watermark ? new WatermarkService() : null;
        $files = [];
        $index = 0;
        $usedNames = [];

        foreach ((array) ($study['Series'] ?? []) as $seriesIndex => $seriesId) {
            $series = $this->client->series((string) $seriesId);
            $seriesUid = (string) ($series['MainDicomTags']['SeriesInstanceUID'] ?? '');
            $seriesNo = (string) ($series['MainDicomTags']['SeriesNumber'] ?? '1');
            $seriesModality = (string) ($series['MainDicomTags']['Modality'] ?? '');

            // USG: citra sudah memuat identitas pasien dari alat -> tanpa watermark.
            $applyWatermark = $watermarker !== null && self::watermarkAllowed($seriesModality);

            foreach ((array) ($series['Instances'] ?? []) as $instanceId) {
                $index++;
                $instance = $this->client->instance((string) $instanceId);
                $instanceUid = (string) ($instance['MainDicomTags']['SOPInstanceUID'] ?? '');
                $instanceNo = (string) ($instance['MainDicomTags']['InstanceNumber'] ?? (string) $index);

                $ext = 'jpg';
                try {
                    $content = $this->renderJpeg($studyUid, $seriesUid, $instanceUid, $quality);
                } catch (OrthancException $e) {
                    if ($e->statusCode !== 404) {
                        throw $e;
                    }
                    $content = $this->renderPng((string) $instanceId);
                    $ext = 'png';
                }

                if ($applyWatermark) {
                    $content = $watermarker->applyJpeg($content, $info, $quality);
                    $ext = 'jpg';
                }

                $files[] = [
                    'name'    => self::uniqueFileName(
                        sprintf('%s-%03d.%s', $seriesNo, (int) $instanceNo, $ext),
                        $usedNames,
                        $index,
                        $seriesIndex + 1,
                        $ext
                    ),
                    'content' => $content,
                ];
            }
        }

        return [
            'patient_id'   => (string) ($study['PatientMainDicomTags']['PatientID'] ?? ''),
            'patient_name' => (string) ($study['PatientMainDicomTags']['PatientName'] ?? ''),
            'accession'    => $accession,
            'files'        => $files,
        ];
    }

    public function archiveStudy(string $studyId): string
    {
        return $this->client->binary('/studies/' . rawurlencode($studyId) . '/archive', [], self::MIME_ZIP);
    }

    /**
     * Nama file unik di dalam satu ZIP.
     *
     * SeriesNumber sering sama untuk semua seri (mis. semuanya "1") dan
     * InstanceNumber bisa berulang antar seri, sehingga nama `1-001.jpg` dapat
     * bentrok. Tanpa penanganan, extractor akan menimpa gambar sebelumnya.
     *
     * @param array<string, true> $used sudah dipakai (ditambah di sini)
     */
    private static function uniqueFileName(
        string $name,
        array &$used,
        int $index,
        int $seriesIndex,
        string $ext
    ): string {
        if (!isset($used[$name])) {
            $used[$name] = true;

            return $name;
        }

        $base = preg_replace('/\.\w+$/', '', $name);
        $candidate = sprintf('%s-s%d-%03d.%s', $base, $seriesIndex, $index, $ext);
        $counter = 2;
        while (isset($used[$candidate])) {
            $candidate = sprintf('%s-s%d-%03d-%d.%s', $base, $seriesIndex, $index, $counter, $ext);
            $counter++;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * @return array<string, string>
     */
    public function metadata(string $instanceId): array
    {
        return $this->client->simplifiedTags($instanceId);
    }

    public function requireStudy(string $studyId): array
    {
        $study = $this->client->study($studyId);
        if ($study === []) {
            throw new RuntimeException('Study tidak ditemukan: ' . $studyId);
        }

        return $study;
    }
}
