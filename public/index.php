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
 *   GET    /studies/{key}/expertise                 draf expertise AI yang tersimpan
 *   POST   /studies/{key}/expertise                 buat draf expertise AI (Ollama vision)
 *   GET    /ollama/status                           status server Ollama + model vision
 *   GET    /instances/{id}/file
 *   GET    /instances/{id}/jpg?quality=90
 *   GET    /users                     (manager) daftar user dashboard
 *   POST   /users                     (manager) {username,password,role}
 *   PUT    /users/{username}          (manager) {role?,password?}
 *   DELETE /users/{username}          (manager) hapus user
 *   GET    /modalities                (manager) modality Orthanc + status alat di Pengaturan
 *   PUT    /modalities/{aet}          (manager) daftarkan alat (DicomModalities) ke Orthanc
 *   POST   /modalities/{aet}/echo     (manager) C-ECHO ke alat (uji jangkauan)
 */

require_once dirname(__DIR__) . '/src/autoload.php';

use Simrs\Pacs\AuditLogger;
use Simrs\Pacs\Auth;
use Simrs\Pacs\Bridge;
use Simrs\Pacs\ModalityService;
use Simrs\Pacs\OllamaException;
use Simrs\Pacs\OrthancException;
use Simrs\Pacs\Settings;
use Simrs\Pacs\WatermarkService;
use Simrs\Pacs\WorklistConflictException;

AuditLogger::boot();

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

$GLOBALS['__pacs_audit'] = [
    'method'    => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'path'      => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
    'query'     => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    'accession' => null,
    'started'   => microtime(true),
];

register_shutdown_function(static function (): void {
    $ctx = $GLOBALS['__pacs_audit'] ?? [];
    AuditLogger::request(
        (string) ($ctx['method'] ?? '?'),
        (string) ($ctx['path'] ?? '?'),
        (int) http_response_code(),
        isset($ctx['accession']) ? (string) $ctx['accession'] : null,
        isset($ctx['started']) ? (float) $ctx['started'] : null,
        (string) ($ctx['query'] ?? '')
    );
});

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

/**
 * Error konsisten: `error` (label singkat, kompatibel), `code` (machine-readable),
 * `message` (kalimat untuk petugas).
 *
 * @param array<string, mixed> $extra
 */
function respondError(int $status, string $code, string $message, array $extra = []): never
{
    $labels = [
        400 => 'Validasi gagal',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Data tidak ditemukan',
        405 => 'Method tidak didukung',
        409 => 'Konflik accession',
        500 => 'Internal error',
        502 => 'Orthanc error',
        503 => 'Fitur tidak tersedia',
    ];

    respondJson([
        'error'   => $labels[$status] ?? 'Error',
        'code'    => $code,
        'message' => $message,
    ] + $extra, $status);
}

/**
 * Tandai accession pada access log (dipakai route yang menyentuh order/study).
 */
function auditAccession(string $value): void
{
    if ($value !== '') {
        $GLOBALS['__pacs_audit']['accession'] = $value;
    }
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
        respondError(400, 'VALIDATION_FAILED', 'Body request harus berupa JSON object.');
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

    AuditLogger::setAuth(
        $sessionValid ? 'session' : ($apiKeyValid ? 'api-key' : 'none'),
        $sessionValid ? $auth->user() : null,
        $sessionValid ? $auth->role() : ($apiKeyValid ? 'api-key' : null)
    );

    if ($path === '/login' && $method === 'POST') {
        $body = readJsonBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($auth->login($username, $password)) {
            AuditLogger::setAuth('session', $auth->user(), $auth->role());
            AuditLogger::event('auth.login', ['username' => $auth->user()]);
            respondJson([
                'authenticated' => true,
                'user'          => $auth->user(),
                'role'          => $auth->role(),
            ]);
        }
        AuditLogger::event('auth.login_failed', ['username' => $username]);
        respondError(401, 'INVALID_CREDENTIALS', 'Username atau password salah.');
    }

    if ($path === '/logout') {
        AuditLogger::event('auth.logout', ['username' => $auth->user()]);
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
        respondError(500, 'DASHBOARD_MISSING', 'File dashboard.html tidak ditemukan di server.');
    }

    if (!$authenticated) {
        respondError(401, 'UNAUTHORIZED', 'Silakan login di dashboard atau kirim header X-API-Key.');
    }

    if ($path === '/audit' && $method === 'GET') {
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'Riwayat audit hanya bisa dibaca dengan sesi login manager.');
        }

        $limit = max(1, min(500, (int) ($query['limit'] ?? 50)));
        $filters = [];
        foreach (['event', 'accession', 'method', 'status', 'user', 'auth'] as $key) {
            $value = trim((string) ($query[$key] ?? ''));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $rows = array_reverse(AuditLogger::tail($limit, $filters));
        respondJson([
            'count'   => count($rows),
            'filters' => $filters,
            'entries' => $rows,
        ]);
    }

    if ($path === '/settings') {
        if ($method === 'GET') {
            $payload = $settings->publicValues();
            if (!$isAdmin) {
                // Operator cukup butuh daftar alat & filter; rahasia bukan wewenangnya.
                unset(
                    $payload['api_key'],
                    $payload['has_api_key'],
                    $payload['callback_key'],
                    $payload['has_callback_key']
                );
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
                    'ollama_enabled' => $bridge->expertiseEnabled(),
                    'ollama_url'     => $config['ollama_url'] ?? '',
                    'ollama_model'   => $config['ollama_model'] ?? '',
                    'ollama_timeout' => $config['ollama_timeout'] ?? 180,
                ],
            ]);
        }
        if ($method === 'PUT') {
            if (!$sessionValid) {
                respondError(403, 'FORBIDDEN', 'Perubahan pengaturan harus melalui sesi login manager.');
            }
            if (!$isAdmin) {
                respondError(403, 'FORBIDDEN', 'Hanya akun manager yang boleh mengubah pengaturan.');
            }
            $body = readJsonBody();
            $clean = $settings->validateBridgeSettings($body);
            $settings->save($clean);
            AuditLogger::event('settings.update', ['fields' => array_keys($clean)]);
            respondJson(['saved' => true, 'settings' => $settings->publicValues()]);
        }
        respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan GET atau PUT untuk /settings.');
    }

    if ($path === '/settings/api-key' && $method === 'GET') {
        if (!$sessionValid) {
            respondError(403, 'FORBIDDEN', 'API key hanya bisa dilihat lewat sesi login manager.');
        }
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'API key hanya bisa dilihat akun manager.');
        }
        $full = (string) ($settings->all()['api_key'] ?? '');
        AuditLogger::event('api_key.view');
        respondJson([
            'has_api_key' => $full !== '',
            'api_key'     => $full,
        ]);
    }

    if ($path === '/settings/password' && $method === 'POST') {
        if (!$sessionValid) {
            respondError(403, 'FORBIDDEN', 'Ganti password harus melalui sesi login.');
        }
        $body = readJsonBody();
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');

        if (!$settings->verifyCredentials((string) $auth->user(), $current)) {
            respondError(400, 'INVALID_PASSWORD', 'Password lama salah.');
        }
        if (strlen($new) < 4) {
            respondError(400, 'VALIDATION_FAILED', 'Password baru minimal 4 karakter.');
        }

        $settings->setUserPassword((string) $auth->user(), $new);
        AuditLogger::event('user.password_change', ['username' => $auth->user()]);
        respondJson(['saved' => true]);
    }

    if ($path === '/users') {
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'Hanya akun manager yang boleh mengelola user.');
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
            AuditLogger::event('user.create', [
                'username' => (string) ($body['username'] ?? ''),
                'role'     => (string) ($body['role'] ?? Settings::ROLE_OPERATOR),
            ]);
            respondJson(['created' => true], 201);
        }
        respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan GET atau POST untuk /users.');
    }

    if (preg_match('#^/users/([A-Za-z0-9._\-]{1,32})$#', $path, $m)) {
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'Hanya akun manager yang boleh mengelola user.');
        }
        $target = $m[1];
        if ($method === 'PUT') {
            $body = readJsonBody();
            $settings->updateUser(
                $target,
                array_key_exists('role', $body) ? (string) $body['role'] : null,
                array_key_exists('password', $body) ? (string) $body['password'] : null
            );
            AuditLogger::event('user.update', [
                'username' => $target,
                'role'     => $body['role'] ?? null,
                'password_reset' => array_key_exists('password', $body) && (string) $body['password'] !== '',
            ]);
            respondJson(['saved' => true]);
        }
        if ($method === 'DELETE') {
            if (strcasecmp($target, (string) $auth->user()) === 0) {
                respondError(400, 'VALIDATION_FAILED', 'Tidak bisa menghapus akun yang sedang dipakai login.');
            }
            $settings->deleteUser($target);
            AuditLogger::event('user.delete', ['username' => $target]);
            respondJson(['deleted' => $target]);
        }
        respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan PUT atau DELETE untuk /users/{username}.');
    }

    if ($path === '/modalities') {
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'Status dan pendaftaran modality hanya untuk sesi login manager.');
        }
        if ($method !== 'GET') {
            respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan GET untuk /modalities.');
        }

        $service = new ModalityService($bridge->client());
        $registered = [];
        $error = null;
        try {
            $registered = $service->list();
        } catch (OrthancException $e) {
            $error = $e->getMessage();
        }

        respondJson([
            'reachable'  => $error === null,
            'error'      => $error,
            'modalities' => $registered,
            'devices'    => ModalityService::mergeDevices($settings->devices(), $registered),
        ]);
    }

    if (preg_match('#^/modalities/([A-Za-z0-9_\-]{1,16})$#', $path, $m)) {
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'Pendaftaran modality hanya untuk sesi login manager.');
        }
        if ($method !== 'PUT') {
            respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan PUT untuk /modalities/{aet}.');
        }

        $aet = $m[1];
        $device = $settings->findDevice($aet);
        if ($device === null) {
            respondError(
                404,
                'DEVICE_NOT_FOUND',
                'AE Title ' . $aet . ' belum ada di Pengaturan → Alat Worklist (simpan dulu alatnya).'
            );
        }

        $body = readJsonBody();
        $host = array_key_exists('host', $body) ? trim((string) $body['host']) : null;
        $port = array_key_exists('port', $body) ? (int) $body['port'] : null;

        $service = new ModalityService($bridge->client());
        $config = $service->register($device, $host !== '' ? $host : null, $port);

        AuditLogger::event('modality.register', [
            'aet'  => $aet,
            'host' => $config['Host'] ?? null,
            'port' => $config['Port'] ?? null,
        ]);

        respondJson([
            'registered'    => true,
            'aet'           => $aet,
            'configuration' => $config,
            'message'       => 'Alat ' . $aet . ' terdaftar di Orthanc (MWL + C-STORE).',
        ]);
    }

    if (preg_match('#^/modalities/([A-Za-z0-9_\-]{1,16})/echo$#', $path, $m)) {
        if (!$isAdmin) {
            respondError(403, 'FORBIDDEN', 'Uji C-ECHO hanya untuk sesi login manager.');
        }
        if ($method !== 'POST') {
            respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan POST untuk /modalities/{aet}/echo.');
        }

        $service = new ModalityService($bridge->client());
        try {
            $result = $service->echo($m[1]);
        } catch (OrthancException $e) {
            $reason = ModalityService::echoFailureReason((string) $e->responseBody);
            respondJson([
                'aet'          => $m[1],
                'echo'         => false,
                'code'         => 'ECHO_FAILED',
                'reason'       => $reason,
                'message'      => 'C-ECHO ke ' . $m[1] . ' gagal: ' . $reason . '.',
                'orthanc_body' => $e->responseBody,
            ], 502);
        }

        respondJson(['aet' => $m[1], 'echo' => true, 'result' => $result]);
    }

    if ($path === '/health') {
        $health = $bridge->health();
        $health['bridge_version'] = \Simrs\Pacs\Version::VERSION;
        $health['server_time'] = date('c');
        respondJson($health);
    }

    if ($path === '/accession' && $method === 'GET') {
        respondJson(['accession_number' => $bridge->worklist()->nextAccessionNumber()]);
    }

    if ($path === '/worklists') {
        if ($method === 'GET') {
            respondJson($bridge->worklist()->all());
        }
        if ($method === 'POST') {
            $body = readJsonBody();
            $accession = trim((string) ($body['accession_number'] ?? ''));
            auditAccession($accession);

            $result = $bridge->worklist()->create($body);
            AuditLogger::event('worklist.create', [
                'accession'   => $result['accession_number'],
                'worklist_id' => $result['id'],
                'patient_id'  => (string) ($body['patient_id'] ?? ''),
                'modality'    => (string) ($body['modality'] ?? ''),
                'duplicate'   => !$result['created'],
            ]);

            respondJson($result, $result['created'] ? 201 : 200);
        }
        respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan GET atau POST untuk /worklists.');
    }

    if (preg_match('#^/worklists/([A-Za-z0-9\-.]+)/status$#', $path, $m)) {
        $key = $m[1];
        auditAccession($key);

        $worklistSvc = $bridge->worklist();
        $studySvc = $bridge->studies();
        $entry = $worklistSvc->findByAccession($key);
        $studies = $studySvc->attachModalities($studySvc->findByAccession($key));

        $createdAt = null;
        $deletedAt = null;
        $lastEvent = null;
        foreach (AuditLogger::history($key, 100) as $row) {
            $event = (string) ($row['event'] ?? '');
            if ($event === 'worklist.create' && $createdAt === null) {
                $createdAt = $row['ts'] ?? null;
            }
            if ($event === 'worklist.delete') {
                $deletedAt = $row['ts'] ?? null;
            }
            if ($event !== '') {
                $lastEvent = ['event' => $event, 'ts' => $row['ts'] ?? null];
            }
        }

        $images = 0;
        $modalities = [];
        foreach ($studies as $study) {
            $modality = strtoupper(trim((string) ($study['MainDicomTags']['Modality'] ?? '')));
            if ($modality !== '') {
                $modalities[] = $modality;
            }
            try {
                $images += count($studySvc->instances((string) ($study['ID'] ?? '')));
            } catch (\Throwable $e) {
                // hitung gambar bersifat informatif saja
            }
        }

        if ($studies !== []) {
            $status = 'completed';
            $label = 'Gambar sudah masuk';
        } elseif ($entry !== null) {
            $status = 'sent';
            $label = 'Order terkirim ke alat';
        } elseif ($deletedAt !== null) {
            $status = 'cancelled';
            $label = 'Order dibatalkan';
        } elseif ($createdAt !== null) {
            $status = 'taken';
            $label = 'Ditarik / diproses alat';
        } else {
            respondError(404, 'ORDER_NOT_FOUND', 'Order dengan AccessionNumber ' . $key . ' tidak ditemukan.');
        }

        respondJson([
            'accession_number' => $key,
            'status'           => $status,
            'label'            => $label,
            'worklist'         => [
                'present' => $entry !== null,
                'id'      => $entry !== null ? (string) ($entry['ID'] ?? '') : null,
            ],
            'studies' => [
                'count'     => count($studies),
                'modalities'=> array_values(array_unique($modalities)),
                'images'    => $images,
                'ids'       => array_values(array_map(
                    static fn (array $study): string => (string) ($study['ID'] ?? ''),
                    $studies
                )),
            ],
            'timeline'   => [
                'created_at' => $createdAt,
                'deleted_at' => $deletedAt,
                'last_event' => $lastEvent,
            ],
            'checked_at' => date('c'),
        ]);
    }

    if (preg_match('#^/worklists/([A-Za-z0-9\-]+)$#', $path, $m)) {
        $id = $m[1];
        if ($method === 'GET') {
            respondJson($bridge->worklist()->get($id));
        }
        if ($method === 'PUT') {
            $body = readJsonBody();
            $accession = trim((string) ($body['accession_number'] ?? ''));
            auditAccession($accession);
            $result = $bridge->worklist()->update($id, $body);
            AuditLogger::event('worklist.update', [
                'accession'   => $accession !== '' ? $accession : $result['accession_number'],
                'worklist_id' => $id,
            ]);
            respondJson($result);
        }
        if ($method === 'DELETE') {
            $accession = '';
            try {
                $entry = $bridge->worklist()->get($id);
                $accession = (string) ($entry['Tags']['AccessionNumber'] ?? '');
            } catch (\Throwable $e) {
                // entry mungkin sudah hilang; tetap lanjut hapus
            }
            auditAccession($accession);

            $bridge->worklist()->delete($id);
            AuditLogger::event('worklist.delete', ['accession' => $accession, 'worklist_id' => $id]);
            respondJson(['deleted' => $id]);
        }
        respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan GET, PUT, atau DELETE untuk /worklists/{id}.');
    }

    if ($path === '/studies/recent') {
        $limit = max(1, min(300, (int) ($query['limit'] ?? 12)));
        $dateParam = isset($query['date']) ? trim((string) $query['date']) : '';
        $defaultFilter = (string) (($settings->all()['study_filter'] ?? 'today'));

        if ($dateParam === '' || $dateParam === 'today') {
            $dateParam = $defaultFilter === 'all' && $dateParam === '' ? 'all' : date('Ymd');
        }

        if ($dateParam !== 'all' && !preg_match('/^\d{8}$/', $dateParam)) {
            respondError(400, 'VALIDATION_FAILED', 'Parameter date harus YYYYMMDD atau "all".');
        }

        $modalityParam = strtoupper(trim((string) ($query['modality'] ?? '')));
        if ($modalityParam === 'ALL') {
            $modalityParam = '';
        }
        if ($modalityParam !== '' && !preg_match('/^[A-Z0-9]{1,16}$/', $modalityParam)) {
            respondError(400, 'VALIDATION_FAILED', 'Parameter modality harus kode DICOM, mis. US atau DX.');
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
        auditAccession($accession);

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
        respondError(400, 'VALIDATION_FAILED', 'Parameter accession, patient_id, atau name wajib diisi.');
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/(jpg|png)$#', $path, $m)) {
        $key = $m[1];
        $format = $m[2];
        auditAccession($key);
        $quality = max(1, min(100, (int) ($query['quality'] ?? 90)));
        $width = isset($query['width']) ? max(64, (int) $query['width']) : null;
        $watermark = $format === 'jpg' && isTruthy($query['watermark'] ?? null);

        if ($watermark && !WatermarkService::available()) {
            respondError(503, 'WATERMARK_UNAVAILABLE', 'Watermark belum aktif di server (ekstensi GD/FreeType belum tersedia).');
        }

        $image = $bridge->studies()->imageByKey($key, $format, $quality, $width, $watermark);
        if ($image === null) {
            respondError(
                404,
                'IMAGE_NOT_FOUND',
                'Gambar untuk study ' . $key . ' belum tersedia. Pastikan order sudah dikerjakan alat, lalu coba lagi.'
            );
        }

        respondBinary($image['content'], $image['mime'], $image['filename']);
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/images$#', $path, $m)) {
        auditAccession($m[1]);
        $study = $bridge->studies()->findOneByKey($m[1]);
        if ($study === null) {
            respondError(404, 'STUDY_NOT_FOUND', 'Study ' . $m[1] . ' belum masuk ke PACS. Coba lagi beberapa saat.');
        }
        $list = $bridge->studies()->imageList((string) ($study['ID'] ?? ''));
        if ($list === []) {
            respondError(404, 'IMAGE_NOT_FOUND', 'Study ' . $m[1] . ' ditemukan, tetapi belum memiliki gambar.');
        }
        respondJson($list);
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/zip-jpg$#', $path, $m)) {
        auditAccession($m[1]);
        $quality = max(1, min(100, (int) ($query['quality'] ?? 90)));
        $watermark = !array_key_exists('watermark', $query) || isTruthy($query['watermark']);

        if ($watermark && !WatermarkService::available()) {
            respondError(503, 'WATERMARK_UNAVAILABLE', 'Watermark belum aktif di server (ekstensi GD/FreeType belum tersedia).');
        }

        try {
            $archive = $bridge->studies()->zipJpegByKey($m[1], $quality, $watermark);
        } catch (InvalidArgumentException $e) {
            respondError(404, 'STUDY_NOT_FOUND', $e->getMessage());
        }
        if ($archive === null) {
            respondError(404, 'IMAGE_NOT_FOUND', 'Study ' . $m[1] . ' belum memiliki gambar untuk diunduh.');
        }

        respondBinary($archive['content'], 'application/zip', $archive['filename']);
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/zip$#', $path, $m)) {
        auditAccession($m[1]);
        $study = $bridge->studies()->findOneByKey($m[1]);
        if ($study === null) {
            respondError(404, 'STUDY_NOT_FOUND', 'Study ' . $m[1] . ' belum masuk ke PACS. Coba lagi beberapa saat.');
        }
        $studyId = (string) ($study['ID'] ?? '');
        $accession = (string) ($study['MainDicomTags']['AccessionNumber'] ?? '');
        respondBinary(
            $bridge->studies()->archiveStudy($studyId),
            'application/zip',
            ($accession !== '' ? $accession : $m[1]) . '.zip'
        );
    }

    if (preg_match('#^/studies/([A-Za-z0-9\-.]+)/expertise$#', $path, $m)) {
        $key = $m[1];
        auditAccession($key);
        $expertise = $bridge->expertise();

        if ($method === 'GET') {
            $draft = $expertise->stored($key);
            if ($draft === null) {
                respondError(404, 'EXPERTISE_NOT_FOUND', 'Belum ada draf expertise untuk study ' . $key . '.');
            }
            respondJson($draft);
        }

        if ($method === 'POST') {
            if (!$bridge->expertiseEnabled()) {
                respondError(
                    503,
                    'EXPERTISE_DISABLED',
                    'Auto expertise belum diaktifkan. Aktifkan di Pengaturan → Auto Expertise (Ollama).'
                );
            }
            $body = readJsonBody();
            $model = trim((string) ($body['model'] ?? ''));
            $result = $expertise->generate($key, $model !== '' ? $model : null);
            AuditLogger::event('expertise.generate', [
                'accession'   => $key,
                'study_id'    => $result['study_id'],
                'model'       => $result['model'],
                'duration_ms' => $result['duration_ms'],
            ]);
            respondJson($result, 201);
        }

        respondError(405, 'METHOD_NOT_ALLOWED', 'Gunakan GET atau POST untuk /studies/{key}/expertise.');
    }

    if ($path === '/ollama/status' && $method === 'GET') {
        $enabled = $bridge->expertiseEnabled();
        try {
            $status = $bridge->expertise()->status();
        } catch (OllamaException $e) {
            // Dashboard tetap butuh jawaban agar bisa menampilkan pesan koneksi.
            respondJson([
                'enabled'   => $enabled,
                'reachable' => false,
                'base_url'  => (string) ($config['ollama_url'] ?? ''),
                'model'     => (string) ($config['ollama_model'] ?? ''),
                'error'     => $e->getMessage(),
            ]);
        }
        respondJson(['enabled' => $enabled] + $status);
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

    respondError(404, 'ENDPOINT_NOT_FOUND', 'Endpoint ' . $path . ' tidak dikenal. Lihat API.md untuk daftar endpoint.');
} catch (WorklistConflictException $e) {
    respondError(409, $e->errorCode, $e->getMessage());
} catch (OrthancException $e) {
    respondJson([
        'error'          => $e->statusCode === 404 ? 'Data tidak ditemukan' : 'Orthanc error',
        'code'           => $e->statusCode === 404 ? 'NOT_FOUND' : 'ORTHANC_ERROR',
        'message'        => $e->statusCode === 404
            ? 'Data yang diminta belum ada di PACS (order/study/gambar).'
            : $e->getMessage(),
        'orthanc_status' => $e->statusCode,
        'orthanc_body'   => $e->responseBody,
    ], $e->statusCode === 404 ? 404 : 502);
} catch (OllamaException $e) {
    respondJson([
        'error'         => 'Ollama error',
        'code'          => $e->statusCode === 404 ? 'OLLAMA_MODEL_NOT_FOUND' : 'OLLAMA_ERROR',
        'message'       => $e->statusCode === 404
            ? 'Model tidak tersedia di server Ollama. Pilih model lain di Pengaturan → Auto Expertise.'
            : $e->getMessage(),
        'ollama_status' => $e->statusCode,
        'ollama_body'   => $e->responseBody,
    ], $e->statusCode === 0 ? 503 : ($e->statusCode >= 500 ? 502 : 400));
} catch (InvalidArgumentException $e) {
    respondError(400, 'VALIDATION_FAILED', $e->getMessage());
} catch (Throwable $e) {
    respondJson([
        'error'   => 'Internal error',
        'code'    => 'INTERNAL_ERROR',
        'message' => $e->getMessage(),
        'type'    => get_class($e),
    ], 500);
}
