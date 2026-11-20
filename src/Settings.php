<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pengaturan bridge yang bisa diubah dari dashboard (disimpan di data/settings.json).
 * Nilai di sini menimpa setting.txt, tetapi environment variable tetap prioritas tertinggi.
 */
final class Settings
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    /** @var list<string> */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATOR];

    private const USERNAME_PATTERN = '/^[A-Za-z0-9._\-]{3,32}$/';

    public const DEFAULTS = [
        'server_ip'   => null,
        'http_port'   => null,
        'dicom_port'  => null,
        'called_aet'  => null,
        'calling_aet' => null,
        'modality'    => null,
        'client_ip'   => null,
        'timeout'     => null,
        'user'        => null,
        'password'    => null,
        'api_key'     => null,
        'callback_url'=> null,
        'callback_key'=> null,
        'admin_user'  => 'admin',
        'study_filter'=> 'today',
        'worklist_devices' => null,
    ];

    public function __construct(private readonly string $path)
    {
    }

    public static function default(): self
    {
        return new self(dirname(__DIR__) . '/data/settings.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = (string) file_get_contents($this->path);
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Nilai yang aman ditampilkan ke UI (tanpa password/hash user).
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        $data = $this->all();
        unset($data['admin_password_hash'], $data['users']);

        $data['admin_user'] = $this->adminUser();
        $data['has_password'] = $this->hasAnyPassword();
        $data['has_api_key'] = !empty($data['api_key']);
        if ($data['has_api_key']) {
            $data['api_key'] = self::maskKey((string) $data['api_key']);
        }

        $data['has_callback_url'] = !empty($data['callback_url']);
        $data['has_callback_key'] = !empty($data['callback_key']);
        if ($data['has_callback_key']) {
            $data['callback_key'] = self::maskKey((string) $data['callback_key']);
        }

        return $data;
    }

    /**
     * API key tidak pernah dikirim utuh ke klien; hanya cuplikan untuk verifikasi visual.
     */
    private static function maskKey(string $key): string
    {
        return strlen($key) > 12 ? substr($key, 0, 8) . '...' . substr($key, -4) : '********';
    }

    private function hasAnyPassword(): bool
    {
        foreach ($this->users() as $user) {
            if ($user['password_hash'] !== '') {
                return true;
            }
        }

        return false;
    }

    public function adminUser(): string
    {
        foreach ($this->users() as $user) {
            if ($user['role'] === self::ROLE_ADMIN) {
                return $user['username'];
            }
        }

        $user = $this->all()['admin_user'] ?? null;

        return is_string($user) && $user !== '' ? $user : (string) self::DEFAULTS['admin_user'];
    }

    /**
     * Daftar user (manager & operator).
     *
     * Bila belum ada `users` (instalasi lama), daftar diturunkan dari
     * `admin_user` + `admin_password_hash` supaya password admin yang sudah
     * dipakai tetap berlaku.
     *
     * @return list<array{username: string, role: string, password_hash: string}>
     */
    public function users(): array
    {
        $data = $this->all();
        $users = [];

        if (is_array($data['users'] ?? null)) {
            foreach ($data['users'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $username = trim((string) ($row['username'] ?? ''));
                if ($username === '') {
                    continue;
                }
                $users[] = [
                    'username'      => $username,
                    'role'          => self::normalizeRole((string) ($row['role'] ?? '')),
                    'password_hash' => (string) ($row['password_hash'] ?? ''),
                ];
            }
        }

        if ($users === []) {
            $legacyUser = $data['admin_user'] ?? null;
            $users[] = [
                'username'      => is_string($legacyUser) && $legacyUser !== ''
                    ? $legacyUser
                    : (string) self::DEFAULTS['admin_user'],
                'role'          => self::ROLE_ADMIN,
                'password_hash' => (string) ($data['admin_password_hash'] ?? ''),
            ];
        }

        return $users;
    }

    /**
     * @return array{username: string, role: string, password_hash: string}|null
     */
    public function findUser(string $username): ?array
    {
        foreach ($this->users() as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function verifyCredentials(string $username, string $password): bool
    {
        $user = $this->findUser($username);
        if ($user === null) {
            return false;
        }

        if ($user['password_hash'] !== '') {
            return password_verify($password, $user['password_hash']);
        }

        // Default pertama kali: admin / admin
        return hash_equals('admin', $password);
    }

    public function roleOf(string $username): string
    {
        $user = $this->findUser($username);

        return $user === null ? self::ROLE_OPERATOR : $user['role'];
    }

    public function setUserPassword(string $username, string $password): void
    {
        $user = $this->findUser($username);
        if ($user === null) {
            throw new InvalidArgumentException('User tidak ditemukan: ' . $username);
        }

        $this->putUsers($this->replaceUser($user['username'], ['password_hash' => self::hashPassword($password)]));
    }

    /**
     * Tambah user baru (dipakai endpoint /users).
     */
    public function addUser(string $username, string $password, string $role): void
    {
        $username = trim($username);
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new InvalidArgumentException('Username tidak valid (3-32 karakter: huruf, angka, . _ -)');
        }
        if (strlen($password) < 4) {
            throw new InvalidArgumentException('Password minimal 4 karakter');
        }
        if ($this->findUser($username) !== null) {
            throw new InvalidArgumentException('Username sudah dipakai: ' . $username);
        }

        $role = strtolower(trim($role));
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Peran harus "admin" (manager) atau "operator"');
        }

        $users = $this->users();
        $users[] = [
            'username'      => $username,
            'role'          => $role,
            'password_hash' => self::hashPassword($password),
        ];
        $this->putUsers($users);
    }

    /**
     * Ubah peran dan/atau password user.
     */
    public function updateUser(string $username, ?string $role = null, ?string $password = null): void
    {
        $user = $this->findUser($username);
        if ($user === null) {
            throw new InvalidArgumentException('User tidak ditemukan: ' . $username);
        }

        $changes = [];
        if ($role !== null && $role !== '') {
            $newRole = strtolower(trim($role));
            if (!in_array($newRole, self::ROLES, true)) {
                throw new InvalidArgumentException('Peran harus "admin" (manager) atau "operator"');
            }
            if ($user['role'] === self::ROLE_ADMIN && $newRole !== self::ROLE_ADMIN && $this->countAdmins() <= 1) {
                throw new InvalidArgumentException('Tidak bisa menurunkan peran manager terakhir');
            }
            $changes['role'] = $newRole;
        }
        if ($password !== null && $password !== '') {
            if (strlen($password) < 4) {
                throw new InvalidArgumentException('Password minimal 4 karakter');
            }
            $changes['password_hash'] = self::hashPassword($password);
        }
        if ($changes === []) {
            return;
        }

        $this->putUsers($this->replaceUser($user['username'], $changes));
    }

    public function deleteUser(string $username): void
    {
        $user = $this->findUser($username);
        if ($user === null) {
            throw new InvalidArgumentException('User tidak ditemukan: ' . $username);
        }
        if ($user['role'] === self::ROLE_ADMIN && $this->countAdmins() <= 1) {
            throw new InvalidArgumentException('Tidak bisa menghapus manager terakhir');
        }

        $users = [];
        foreach ($this->users() as $row) {
            if (strcasecmp($row['username'], $user['username']) !== 0) {
                $users[] = $row;
            }
        }
        $this->putUsers($users);
    }

    public function countAdmins(): int
    {
        $count = 0;
        foreach ($this->users() as $user) {
            if ($user['role'] === self::ROLE_ADMIN) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array{username: string, role: string, password_hash: string} $user
     * @param array<string, string> $changes
     * @return list<array{username: string, role: string, password_hash: string}>
     */
    private function replaceUser(string $username, array $changes): array
    {
        $users = [];
        foreach ($this->users() as $row) {
            $users[] = strcasecmp($row['username'], $username) === 0
                ? array_merge($row, $changes)
                : $row;
        }

        return $users;
    }

    /**
     * Simpan daftar user sekaligus membuang field lama (admin_user/admin_password_hash)
     * supaya tidak ada dua sumber kebenaran.
     *
     * @param list<array{username: string, role: string, password_hash: string}> $users
     */
    private function putUsers(array $users): void
    {
        $data = $this->all();
        $data['users'] = array_values($users);
        unset($data['admin_user'], $data['admin_password_hash']);
        $this->write($data);
    }

    private static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Gagal membuat hash password');
        }

        return $hash;
    }

    private static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));

        return in_array($role, self::ROLES, true) ? $role : self::ROLE_OPERATOR;
    }

    /**
     * @param mixed $value
     */
    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $data = $this->all();

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                unset($data[$key]);
                continue;
            }
            $data[$key] = $value;
        }

        $this->write($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Tidak bisa membuat folder ' . $dir);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal encode settings');
        }

        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new RuntimeException('Tidak bisa menulis settings (cek izin folder data/)');
        }
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Tidak bisa menyimpan settings');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> nilai yang sudah dinormalisasi
     */
    public function validateBridgeSettings(array $input): array
    {
        $clean = [];
        $map = [
            'server_ip'   => '/^(\d{1,3}\.){3}\d{1,3}$/',
            'called_aet'  => '/^[A-Z0-9_\-]{1,16}$/i',
            'calling_aet' => '/^[A-Z0-9_\-]{1,16}$/i',
            'modality'    => '/^[A-Z0-9]{1,16}$/i',
            'client_ip'   => '/^$|^(\d{1,3}\.){3}\d{1,3}$/',
        ];

        foreach ($map as $key => $pattern) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string) $input[$key]);
            if ($value !== '' && !preg_match($pattern, $value)) {
                throw new \InvalidArgumentException("Nilai tidak valid untuk {$key}: {$value}");
            }
            $clean[$key] = in_array($key, ['called_aet', 'calling_aet', 'modality'], true)
                ? strtoupper($value)
                : $value;
        }

        foreach (['http_port' => [1, 65535], 'dicom_port' => [1, 65535], 'timeout' => [1, 300]] as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = (int) $input[$key];
            if ($value < $min || $value > $max) {
                throw new \InvalidArgumentException("Nilai {$key} harus di antara {$min}-{$max}");
            }
            $clean[$key] = $value;
        }

        if (array_key_exists('api_key', $input)) {
            $clean['api_key'] = trim((string) $input['api_key']);
        }

        if (array_key_exists('callback_url', $input)) {
            $value = trim((string) $input['callback_url']);
            if ($value !== '' && preg_match('#^https?://\S+$#i', $value) !== 1) {
                throw new \InvalidArgumentException('callback_url harus URL http/https yang valid');
            }
            $clean['callback_url'] = $value;
        }

        if (array_key_exists('callback_key', $input)) {
            $value = trim((string) $input['callback_key']);
            if ($value !== '' && strlen($value) < 8) {
                throw new \InvalidArgumentException('callback_key minimal 8 karakter');
            }
            $clean['callback_key'] = $value;
        }

        if (array_key_exists('study_filter', $input)) {
            $value = trim((string) $input['study_filter']);
            if (!in_array($value, ['today', 'all'], true)) {
                throw new \InvalidArgumentException('study_filter harus today atau all');
            }
            $clean['study_filter'] = $value;
        }

        if (array_key_exists('auto_modality_tabs', $input)) {
            $clean['auto_modality_tabs'] = self::toBool($input['auto_modality_tabs']);
        }

        if (array_key_exists('worklist_devices', $input)) {
            $clean['worklist_devices'] = self::validateDevices($input['worklist_devices']);
        }

        return $clean;
    }

    /**
     * Daftar alat/station worklist: [{name, aet, modality, host, port}].
     *
     * `host`/`port` dipakai untuk mendaftarkan alat ke Orthanc (`DicomModalities`)
     * lewat dashboard, bukan untuk koneksi dari bridge.
     *
     * @param mixed $input
     * @return list<array{name: string, aet: string, modality: string, host: string, port: int}>
     */
    private static function validateDevices($input): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException('worklist_devices harus berupa array');
        }

        $devices = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            // Huruf AE Title dipertahankan apa adanya: pencocokan ScheduledStationAETitle
            // di MWL bersifat case-sensitive (alat GE memakai "VersanaB-000000").
            $aet = trim((string) ($row['aet'] ?? ''));
            $modality = strtoupper(trim((string) ($row['modality'] ?? '')));
            $host = trim((string) ($row['host'] ?? ''));
            $port = (int) ($row['port'] ?? 0);

            if ($name === '' && $aet === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_\-]{1,16}$/', $aet)) {
                throw new \InvalidArgumentException('AE Title alat tidak valid: ' . $aet);
            }
            if ($modality !== '' && !preg_match('/^[A-Z0-9]{1,16}$/', $modality)) {
                throw new \InvalidArgumentException('Modality alat tidak valid: ' . $modality);
            }
            if ($host !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,63}$/', $host)) {
                throw new \InvalidArgumentException('IP/host alat tidak valid: ' . $host);
            }
            if ($port !== 0 && ($port < 1 || $port > 65535)) {
                throw new \InvalidArgumentException('Port DICOM alat harus 1-65535: ' . $port);
            }

            $devices[] = [
                'name'     => $name !== '' ? $name : $aet,
                'aet'      => $aet,
                'modality' => $modality !== '' ? $modality : 'DX',
                'host'     => $host,
                'port'     => $port > 0 ? $port : 104,
            ];
        }

        return $devices;
    }

    /**
     * Alat worklist yang tersimpan (sudah dinormalisasi, aman dipakai service lain).
     *
     * @return list<array{name: string, aet: string, modality: string, host: string, port: int}>
     */
    public function devices(): array
    {
        $raw = $this->all()['worklist_devices'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        try {
            return self::validateDevices($raw);
        } catch (InvalidArgumentException) {
            // Data lama/tidak valid tidak boleh mematikan halaman Pengaturan.
            return [];
        }
    }

    /**
     * Cari alat berdasarkan AE Title (peka huruf besar/kecil, sama seperti MWL).
     *
     * @return array{name: string, aet: string, modality: string, host: string, port: int}|null
     */
    public function findDevice(string $aet): ?array
    {
        foreach ($this->devices() as $device) {
            if ($device['aet'] === $aet) {
                return $device;
            }
        }

        return null;
    }
}
