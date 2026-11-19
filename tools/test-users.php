<?php

declare(strict_types=1);

/**
 * Uji peran user dashboard (manager vs operator) pada Bridge API.
 *
 *   php tools/test-users.php [baseUrl] [adminUser] [adminPassword]
 *
 * Aman diulang: user uji dibuat dengan nama acak lalu dihapus di akhir.
 * Butuh akun manager (default admin / admin pada instalasi baru).
 */

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8081', '/');
$adminUser = $argv[2] ?? 'admin';
$adminPass = $argv[3] ?? 'admin';

$pass = 0;
$fail = 0;

function check(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $info = $fn();
        $pass++;
        printf("[PASS] %-48s %s\n", $label, (string) $info);
    } catch (Throwable $e) {
        $fail++;
        printf("[FAIL] %-48s %s\n", $label, $e->getMessage());
    }
}

/**
 * @return array{0: int, 1: array<string, mixed>|null, 2: string}
 */
function request(string $method, string $url, ?string $jar, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ];
    if ($jar !== null) {
        // Simpan & pakai cookie jar terpisah per "browser" (sesi PHP).
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string) $raw, true);

    return [$status, is_array($data) ? $data : null, (string) $raw];
}

function expectStatus(int $expected, string $label, callable $fn): string
{
    $status = $fn();
    if ($status !== $expected) {
        throw new RuntimeException($label . ': HTTP ' . $status . ', diharapkan ' . $expected);
    }

    return 'HTTP ' . $status;
}

function setUpSessionJar(string $url, string $user, string $password): string
{
    $jar = tempnam(sys_get_temp_dir(), 'pacsjar');
    if ($jar === false) {
        throw new RuntimeException('Tidak bisa membuat cookie jar');
    }
    [$status, $data] = request('POST', $url . '/login', $jar, ['username' => $user, 'password' => $password]);
    if ($status !== 200) {
        @unlink($jar);
        throw new RuntimeException('Login ' . $user . ' gagal (HTTP ' . $status . ')');
    }

    return $jar;
}

$testUser = 'u_test_' . substr(bin2hex(random_bytes(4)), 0, 6);
$testPass = 'uji' . bin2hex(random_bytes(3));
$testPass2 = 'baru' . bin2hex(random_bytes(3));
$adminJar = null;

echo "=== Peran user dashboard: $baseUrl ===\n";
echo "akun manager: {$adminUser} · user uji: {$testUser}\n\n";

try {
    $adminJar = setUpSessionJar($baseUrl, $adminUser, $adminPass);

    check('login manager: role=admin & is_admin', static function () use ($baseUrl, $adminJar): string {
        [$status, $me] = request('GET', $baseUrl . '/me', $adminJar);
        if ($status !== 200 || ($me['role'] ?? '') !== 'admin' || ($me['is_admin'] ?? false) !== true) {
            throw new RuntimeException('/me tidak melaporkan manager');
        }

        return 'role=admin';
    });

    check('manager: GET /users 200', static function () use ($baseUrl, $adminJar): string {
        [$status, $data] = request('GET', $baseUrl . '/users', $adminJar);
        if ($status !== 200 || !isset($data['users'])) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return count($data['users']) . ' user';
    });

    check('manager: tambah user operator', static function () use ($baseUrl, $adminJar, $testUser, $testPass): string {
        [$status] = request('POST', $baseUrl . '/users', $adminJar, [
            'username' => $testUser,
            'password' => $testPass,
            'role'     => 'operator',
        ]);
        if ($status !== 201) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return 'dibuat: ' . $testUser;
    });

    $operatorJar = setUpSessionJar($baseUrl, $testUser, $testPass);

    check('operator: login role=operator, is_admin=false', static function () use ($baseUrl, $operatorJar): string {
        [$status, $me] = request('GET', $baseUrl . '/me', $operatorJar);
        if ($status !== 200 || ($me['role'] ?? '') !== 'operator' || ($me['is_admin'] ?? true) !== false) {
            throw new RuntimeException('/me tidak melaporkan operator');
        }

        return 'role=operator';
    });

    check('operator: GET /worklists 200', static function () use ($baseUrl, $operatorJar): string {
        [$status, $data] = request('GET', $baseUrl . '/worklists', $operatorJar);
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return count($data) . ' order';
    });

    check('operator: GET /health 200', static function () use ($baseUrl, $operatorJar): string {
        [$status, $h] = request('GET', $baseUrl . '/health', $operatorJar);
        if ($status !== 200) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return 'Orthanc ' . ($h['version'] ?? '?');
    });

    check('operator: PUT /settings -> 403', static function () use ($baseUrl, $operatorJar): string {
        return expectStatus(403, 'PUT /settings', static function () use ($baseUrl, $operatorJar): int {
            [$status] = request('PUT', $baseUrl . '/settings', $operatorJar, ['study_filter' => 'all']);

            return $status;
        });
    });

    check('operator: GET /settings/api-key -> 403', static function () use ($baseUrl, $operatorJar): string {
        return expectStatus(403, 'GET /settings/api-key', static function () use ($baseUrl, $operatorJar): int {
            [$status] = request('GET', $baseUrl . '/settings/api-key', $operatorJar);

            return $status;
        });
    });

    check('operator: GET /users -> 403', static function () use ($baseUrl, $operatorJar): string {
        return expectStatus(403, 'GET /users', static function () use ($baseUrl, $operatorJar): int {
            [$status] = request('GET', $baseUrl . '/users', $operatorJar);

            return $status;
        });
    });

    check('operator: POST /users -> 403', static function () use ($baseUrl, $operatorJar): string {
        return expectStatus(403, 'POST /users', static function () use ($baseUrl, $operatorJar): int {
            [$status] = request('POST', $baseUrl . '/users', $operatorJar, [
                'username' => 'u_palsu_' . substr(bin2hex(random_bytes(3)), 0, 4),
                'password' => 'rahasia1',
                'role'     => 'admin',
            ]);

            return $status;
        });
    });

    check('operator: ganti password sendiri', static function () use ($baseUrl, $operatorJar, $testPass, $testPass2): string {
        [$status] = request('POST', $baseUrl . '/settings/password', $operatorJar, [
            'current_password' => $testPass,
            'new_password'     => $testPass2,
        ]);
        if ($status !== 200) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return 'password diganti';
    });

    check('login dengan password baru', static function () use ($baseUrl, $testUser, $testPass2): string {
        $jar = setUpSessionJar($baseUrl, $testUser, $testPass2);
        @unlink($jar);

        return 'berhasil';
    });

    check('login dengan password lama -> 401', static function () use ($baseUrl, $testUser, $testPass): string {
        $jar = tempnam(sys_get_temp_dir(), 'pacsjar');
        [$status] = request('POST', $baseUrl . '/login', $jar, ['username' => $testUser, 'password' => $testPass]);
        @unlink((string) $jar);
        if ($status !== 401) {
            throw new RuntimeException('HTTP ' . $status . ', diharapkan 401');
        }

        return 'HTTP 401';
    });

    check('manager: ubah peran operator -> manager', static function () use ($baseUrl, $adminJar, $testUser): string {
        [$status] = request('PUT', $baseUrl . '/users/' . rawurlencode($testUser), $adminJar, ['role' => 'admin']);
        if ($status !== 200) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return 'role=admin';
    });

    check('peran baru langsung berlaku (GET /users 200)', static function () use ($baseUrl, $operatorJar): string {
        [$status, $data] = request('GET', $baseUrl . '/users', $operatorJar);
        if ($status !== 200) {
            throw new RuntimeException('HTTP ' . $status . ' (peran lama masih dipakai?)');
        }

        return count($data['users'] ?? []) . ' user';
    });

    check('manager: hapus akun sendiri -> 400', static function () use ($baseUrl, $adminJar, $adminUser): string {
        return expectStatus(400, 'DELETE /users/' . $adminUser, static function () use ($baseUrl, $adminJar, $adminUser): int {
            [$status] = request('DELETE', $baseUrl . '/users/' . rawurlencode($adminUser), $adminJar);

            return $status;
        });
    });

    check('manager: hapus user uji', static function () use ($baseUrl, $adminJar, $testUser): string {
        [$status] = request('DELETE', $baseUrl . '/users/' . rawurlencode($testUser), $adminJar);
        if ($status !== 200) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return 'dihapus: ' . $testUser;
    });

    check('user terhapus: login -> 401', static function () use ($baseUrl, $testUser, $testPass2): string {
        $jar = tempnam(sys_get_temp_dir(), 'pacsjar');
        [$status] = request('POST', $baseUrl . '/login', $jar, ['username' => $testUser, 'password' => $testPass2]);
        @unlink((string) $jar);
        if ($status !== 401) {
            throw new RuntimeException('HTTP ' . $status . ', diharapkan 401');
        }

        return 'HTTP 401';
    });

    check('manager terakhir tidak bisa diturunkan -> 400', static function () use ($baseUrl, $adminJar, $adminUser): string {
        return expectStatus(400, 'PUT /users/' . $adminUser, static function () use ($baseUrl, $adminJar, $adminUser): int {
            [$status] = request('PUT', $baseUrl . '/users/' . rawurlencode($adminUser), $adminJar, ['role' => 'operator']);

            return $status;
        });
    });
} catch (Throwable $e) {
    $fail++;
    echo '[FAIL] persiapan: ' . $e->getMessage() . "\n";
} finally {
    if ($adminJar !== null) {
        @unlink($adminJar);
    }
}

echo "\n=== Ringkasan: {$pass} pass, {$fail} fail ===\n";
exit($fail === 0 ? 0 : 1);
