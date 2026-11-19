<?php

declare(strict_types=1);

/**
 * REST API bridge SIMRS <-> Orthanc.
 *
 * Jalankan (dev):  php -S 0.0.0.0:8081 -t public public/router.php
 * Apache/Laragon:  arahkan docroot ke folder public/
 *
 * Endpoint:
 *   GET    /health
 *   GET    /accession               (nomor accession baru, format alat Orthanc)
 *   GET    /worklists
 *   POST   /worklists               {patient_name,title,patient_id,accession_number,procedure,...}
 *   GET    /worklists/{id}
 *   PUT    /worklists/{id}          body sama seperti POST
 *   DELETE /worklists/{id}
 *   GET    /studies?accession=...&patient_id=...
 *   GET    /studies/{accession}/jpg?quality=90[&watermark=1]
 *   GET    /studies/{accession}/png?width=1024
 *   GET    /studies/{accession}/zip
 *   GET    /studies/{accession}/zip-jpg?quality=90[&watermark=0]   (ZIP berisi JPG ber-watermark, folder = No. RM)
 *   GET    /studies/{accession}/images               (daftar instance/gambar)
 *   GET    /instances/{id}/file
 *   GET    /instances/{id}/jpg?quality=90
 *   GET    /users                     (manager) daftar user dashboard
 *   POST   /users                     (manager) {username,password,role}
 *   PUT    /users/{username}          (manager) {role?,password?}
 *   DELETE /users/{username}          (manager) hapus user
 */

require_once dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\Auth;
use Simrs\Pacs\Bridge;
use Simrs\Pacs\OrthancException;
use Simrs\Pacs\Settings;
use Simrs\Pacs\WatermarkService;
use Simrs\Pacs\ZipWriter;

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * @param mixed $data
 */
function respondJson($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function respondBinary(string $content, string $mime, string $filename): never
{
    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $content;
    exit;
}

/**
 * Nilai query string dianggap true bila 1/true/yes/on (default false).
 *
 * @param mixed $value
 */
function isTruthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respondJson(['error' => 'Body harus JSON object'], 400);
    }

    return $data;
}

try {
    Auth::start();

    $settings = Settings::default();
    $auth = new Auth($settings);

    $bridge = Bridge::fromSettingFile();
    $config = $bridge->config();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
        $uri = substr($uri, strlen($scriptDir)) ?: '/';
    }
    $path = '/' . trim($uri, '/');
    $query = $_GET;

    $apiKey = $config['api_key'] ?? '';
    $apiKeyValid = is_string($apiKey) && $apiKey !== ''
        && isset($_SERVER['HTTP_X_API_KEY'])
        && hash_equals($apiKey, (string) $_SERVER['HTTP_X_API_KEY']);
    $sessionValid = $auth->check();
    $authenticated = $sessionValid || $apiKeyValid;
    /** Hanya sesi login dengan peran manager yang boleh mengubah konfigurasi & user. */
    $isAdmin = $sessionValid && $auth->isAdmin();

    if ($path === '/login' && $method === 'POST') {
        $body = readJsonBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($auth->login($username, $password)) {
            respondJson([
                'authenticated' => true,
                'user'          => $auth->user(),
                'role'          => $auth->role(),
            ]);
        }
        respondJson(['error' => 'Username atau password salah'], 401);
    }

    if ($path === '/logout') {
        $auth->logout();
        respondJson(['authenticated' => false]);
    }

    if ($path === '/me') {
        $public = $settings->publicValues();
        respondJson([
            'authenticated'       => $authenticated,
            'via'                 => $sessionValid ? 'session' : ($apiKeyValid ? 'api-key' : 'none'),
            'user'                => $auth->user(),
            'role'                => $sessionValid ? $auth->role() : ($apiKeyValid ? 'api-key' : null),
            'is_admin'            => $isAdmin,
            'default_credentials' => empty($public['has_password']),
        ]);
    }

    if ($path === '/' || $path === '/dashboard') {
        $dashboard = __DIR__ . '/dashboard.html';
        if (is_file($dashboard)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            readfile($dashboard);
            exit;
        }
        respondJson(['error' => 'dashboard.html tidak ditemukan'], 500);
    }

    if (!$authenticated) {
        respondJson([
            'error'   => 'Unauthorized',
            'message' => 'Silakan login di dashboard',
        ], 401);
    }

    if ($path === '/settings') {
        if ($method === 'GET') {
            $payload = $settings->publicValues();
            if (!$isAdmin) {
                // Operator cukup butuh daftar alat & filter; detail API key bukan wewenangnya.
                unset($payload['api_key'], $payload['has_api_key']);
            }
            respondJson([
                'settings'  => $payload,
                'effective' => [
                    'base_url'   => $config['base_url'],
                    'server_ip'  => $config['server_ip'],
                    'http_port'  => $config['http_port'],
                    'dicom_port' => $config['dicom_port'],
                    'called_aet' => $config['called_aet'],
                    'calling_aet'=> $config['calling_aet'],
                    'modality'   => $config['modality'],
                ],
            ]);
        }
        if ($method === 'PUT') {
            if (!$sessionValid) {
                respondJson(['error' => 'Perubahan pengaturan harus melalui sesi login'], 403);
            }
            if (!$isAdmin) {
                respondJson(['error' => 'Hanya akun manager yang boleh mengubah pengaturan'], 403);
            }
            $clean = $settings->validateBridgeSettings(readJsonBody());
            $settings->save($clean);
            respondJson(['saved' => true, 'settings' => $settings->publicValues()]);
        }
        respondJson(['error' => 'Method tidak didukung'], 405);
    }

    if ($path === '/settings/api-key' && $method === 'GET') {
        if (!$sessionValid) {
            respondJson(['error' => 'API key hanya bisa dilihat lewat sesi login'], 403);
        }
        if (!$isAdmin) {
            respondJson(['error' => 'API key hanya bisa dilihat akun manager'], 403);
        }
        $full = (string) ($settings->all()['api_key'] ?? '');
        respondJson([
            'has_api_key' => $full !== '',
            'api_key'     => $full,
        ]);
    }

    if ($path === '/settings/password' && $method === 'POST') {
        if (!$sessionValid) {
            respondJson(['error' => 'Harus login'], 403);
        }
        $body = readJsonBody();
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');

        if (!$settings->verifyCredentials((string) $auth->user(), $current)) {
            respondJson(['error' => 'Password lama salah'], 400);
        }
        if (strlen($new) < 4) {
            respondJson(['error' => 'Password baru minimal 4 karakter'], 400);
        }

        $settings->setUserPassword((string) $auth->user(), $new);
        respondJson(['saved' => true]);
    }

    if ($path === '/users') {
        if (!$isAdmin) {
            respondJson(['error' => 'Hanya akun manager yang boleh mengelola user'], 403);
        }
        if ($method === 'GET') {
            $me = (string) $auth->user();
            $list = [];
            foreach ($settings->users() as $user) {
                $list[] = [
                    'username'    => $user['username'],
                    'role'        => $user['role'],
                    'is_self'     => strcasecmp($user['username'], $me) === 0,
                    'has_password'=> $user['password_hash'] !== '',
                ];
            }
            respondJson(['users' => $list, 'roles' => Settings::ROLES]);
        }
        if ($method === 'POST') {
            $body = readJsonBody();
            $settings->addUser(
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['role'] ?? Settings::ROLE_OPERATOR)
            );
            respondJson(['created' => true], 201);
        }
        respondJson(['error' => 'Method tidak didukung'], 405);
    }

    if (preg_match('#^/users/([A-Za-z0-9._\-]{1,32})$#', $path, $m)) {
        if (!$isAdmin) {
            respondJson(['error' => 'Hanya akun manager yang boleh mengelola user'], 403);
        }
        $target = $m[1];
        if ($method === 'PUT') {
            $body = readJsonBody();
            $settings->updateUser(
                $target,
                array_key_exists('role', $body) ? (string) $body['role'] : null,
                array_key_exists('password', $body) ? (string) $body['password'] : null
            );
            respondJson(['saved' => true]);
        }
        if ($method === 'DELETE') {
            if (strcasecmp($target, (string) $auth->user()) === 0) {
                respondJson(['error' => 'Tidak bisa menghapus akun yang sedang dipakai login'], 400);
            }
            $settings->deleteUser($target);
            respondJson(['deleted' => $target]);
        }
        respondJson(['error' => 'Method tidak didukung'], 405);
    }

    if ($path === '/health') {
        respondJson($bridge->health());
    }

    if ($path === '/accession' && $method === 'GET') {
        respondJson(['accession_number' => $bridge->worklist()->nextAccessionNumber()]);
    }

    if ($path === '/worklists') {
        if ($method === 'GET') {
            respondJson($bridge->worklist()->all());
        }
        if ($method === 'POST') {
            $result = $bridge->worklist()->create(readJsonBody());
            respondJson($result, 201);
        }
        respondJson(['error' => 'Method tidak didukung'], 405);
    }

    if (preg_match('#^/worklists/([A-Za-z0-9\-]+)$#', $path, $m)) {
        $id = $m[1];
        if ($method === 'GET') {
            respondJson($bridge->worklist()->get($id));
        }
        if ($method === 'PUT') {
            respondJson($bridge->worklist()->update($id, readJsonBody()));
        }
        if ($method === 'DELETE') {
            $bridge->worklist()->delete($id);
            respondJson(['deleted' => $id]);
        }
        respondJson(['error' => 'Method tidak didukung'], 405);
    }

    if ($path === '/studies/recent') {
        $limit = max(1, min(300, (int) ($query['limit'] ?? 12)));
        $dateParam = isset($query['date']) ? trim((string) $query['date']) : '';
        $defaultFilter = (string) (($settings->all()['study_filter'] ?? 'today'));

        if ($dateParam === '' || $dateParam === 'today') {
            $dateParam = $defaultFilter === 'all' && $dateParam === '' ? 'all' : date('Ymd');
        }

        if ($dateParam !== 'all' && !preg_match('/^\d{8}$/', $dateParam)) {
            respondJson(['error' => 'Parameter date harus YYYYMMDD atau "all"'], 400);
        }

        $modalityParam = strtoupper(trim((string) ($query['modality'] ?? '')));
        if ($modalityParam === 'ALL') {
            $modalityParam = '';
        }
        if ($modalityParam !== '' && !preg_match('/^[A-Z0-9]{1,16}$/', $modalityParam)) {
            respondJson(['error' => 'Parameter modality harus kode DICOM, mis. US atau DX'], 400);
        }

        $date = $dateParam === 'all' ? null : $dateParam;
        $studySvc = $bridge->studies();

        // Penyaringan modalitas terjadi setelah data diambil (Orthanc tidak menyimpan
        // Modality di level Study), jadi ambil lebih dulu lalu potong sesuai limit.
        $fetchLimit = $modalityParam === '' ? $limit : 300;
        $studies = $studySvc->attachModalities($studySvc->recent($fetchLimit, $date), $date);
        $studies = array_slice($studySvc->filterByModality($studies, $modalityParam), 0, $limit);

        $payload = [
            'date'     => $date ?? 'all',
            'limit'    => $limit,
            'modality' => $modalityParam !== '' ? $modalityParam : 'all',
            'count'    => count($studies),
            'studies'  => $studies,
        ];

        // Alat dengan jam maju (mis. workstation USG +1 hari) mengirim study
        // bertanggal setelah hari ini, sehingga tidak muncul di filter "Hari ini".
        if ($date !== null && $date === date('Ymd')) {
            $outside = $studySvc->filterByModality($studySvc->attachModalities($studySvc->newerThan($date, 24)), $modalityParam);
            if ($outside !== []) {
                $payload['outside'] = [
                    'count'   => count($outside),
                    'studies' => $outside,
                ];
            }
        }

        respondJson($payload);
    }

    if ($path === '/studies') {
        $accession = trim((string) ($query['accession'] ?? ''));
        $patientId = trim((string) ($query['patient_id'] ?? ''));
        $name = trim((string) ($query['name'] ?? ($query['patient_name'] ?? '')));

        if ($accession !== '') {
            $studySvc = $bridge->studies();
            respondJson($studySvc->attachModalities($studySvc->findByAccession($accession)));
        }
        if ($patientId !== '') {
            $studySvc = $bridge->studies();
            respondJson($studySvc->attachModalities($studySvc->findByPatientId($patientId)));
        }
        if ($name !== '') {
            $studySvc = $bridge->studies();
            respondJson($studySvc->attachModalities($studySvc->findByPatientName($name)));
        }
        respondJson(['error' => 'Parameter accession, patient_id, atau name wajib'], 400);
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/(jpg|png)$#', $path, $m)) {
        $key = $m[1];
        $format = $m[2];
        $quality = max(1, min(100, (int) ($query['quality'] ?? 90)));
        $width = isset($query['width']) ? max(64, (int) $query['width']) : null;
        $watermark = $format === 'jpg' && isTruthy($query['watermark'] ?? null);

        if ($watermark && !WatermarkService::available()) {
            respondJson(['error' => 'Watermark tidak tersedia (ekstensi GD/FreeType belum aktif)'], 503);
        }

        $image = $bridge->studies()->imageByKey($key, $format, $quality, $width, $watermark);
        if ($image === null) {
            respondJson(['error' => 'Study/gambar tidak ditemukan untuk ' . $key], 404);
        }

        respondBinary($image['content'], $image['mime'], $image['filename']);
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/images$#', $path, $m)) {
        $study = $bridge->studies()->findOneByKey($m[1]);
        if ($study === null) {
            respondJson(['error' => 'Study tidak ditemukan untuk ' . $m[1]], 404);
        }
        $list = $bridge->studies()->imageList((string) ($study['ID'] ?? ''));
        if ($list === []) {
            respondJson(['error' => 'Study belum memiliki gambar'], 404);
        }
        respondJson($list);
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/zip-jpg$#', $path, $m)) {
        $quality = max(1, min(100, (int) ($query['quality'] ?? 90)));
        $watermark = !array_key_exists('watermark', $query) || isTruthy($query['watermark']);

        if ($watermark && !WatermarkService::available()) {
            respondJson(['error' => 'Watermark tidak tersedia (ekstensi GD/FreeType belum aktif)'], 503);
        }

        try {
            $archive = $bridge->studies()->imagesByKey($m[1], $quality, $watermark);
        } catch (InvalidArgumentException $e) {
            respondJson(['error' => $e->getMessage()], 404);
        }
        if ($archive['files'] === []) {
            respondJson(['error' => 'Tidak ada gambar untuk ' . $m[1]], 404);
        }

        $folder = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $archive['patient_id'] !== '' ? $archive['patient_id'] : $m[1]);
        $folder = trim((string) $folder, '_') ?: $m[1];

        $zip = new ZipWriter();
        $zip->addDirectory($folder);
        foreach ($archive['files'] as $file) {
            $zip->addFile($folder . '/' . $file['name'], $file['content']);
        }

        respondBinary($zip->finish(), 'application/zip', $folder . '.zip');
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/zip$#', $path, $m)) {
        $study = $bridge->studies()->findOneByKey($m[1]);
        if ($study === null) {
            respondJson(['error' => 'Study tidak ditemukan untuk ' . $m[1]], 404);
        }
        $studyId = (string) ($study['ID'] ?? '');
        $accession = (string) ($study['MainDicomTags']['AccessionNumber'] ?? '');
        respondBinary(
            $bridge->studies()->archiveStudy($studyId),
            'application/zip',
            ($accession !== '' ? $accession : $m[1]) . '.zip'
        );
    }

    if (preg_match('#^/instances/([A-Za-z0-9\-]+)/jpg$#', $path, $m)) {
        $quality = max(1, min(100, (int) ($query['quality'] ?? 90)));
        $image = $bridge->studies()->imageByInstance($m[1], $quality);
        respondBinary($image['content'], $image['mime'], $image['filename']);
    }

    if (preg_match('#^/instances/([A-Za-z0-9\-]+)/file$#', $path, $m)) {
        respondBinary(
            $bridge->studies()->dicomInstance($m[1]),
            'application/dicom',
            $m[1] . '.dcm'
        );
    }

    respondJson(['error' => 'Endpoint tidak ditemukan', 'path' => $path], 404);
} catch (OrthancException $e) {
    respondJson([
        'error'         => $e->statusCode === 404 ? 'Data tidak ditemukan' : 'Orthanc error',
        'message'       => $e->getMessage(),
        'orthanc_status'=> $e->statusCode,
        'orthanc_body'  => $e->responseBody,
    ], $e->statusCode === 404 ? 404 : 502);
} catch (InvalidArgumentException $e) {
    respondJson(['error' => 'Validasi gagal', 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respondJson([
        'error'   => 'Internal error',
        'message' => $e->getMessage(),
        'type'    => get_class($e),
    ], 500);
}
