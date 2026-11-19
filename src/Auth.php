<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Sesi login sederhana untuk dashboard (PHP native session).
 */
final class Auth
{
    private const SESSION_KEY = 'pacs_user';

    public function __construct(private readonly Settings $settings)
    {
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        session_start();
    }

    public function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]) && is_string($_SESSION[self::SESSION_KEY]);
    }

    public function user(): ?string
    {
        return $this->check() ? (string) $_SESSION[self::SESSION_KEY] : null;
    }

    /**
     * Peran user yang sedang login (`admin` = manager, `operator` = operasional).
     * Dibaca ulang dari settings supaya perubahan peran langsung berlaku.
     */
    public function role(): string
    {
        $user = $this->user();

        return $user === null ? Settings::ROLE_OPERATOR : $this->settings->roleOf($user);
    }

    public function isAdmin(): bool
    {
        return $this->check() && $this->role() === Settings::ROLE_ADMIN;
    }

    public function login(string $username, string $password): bool
    {
        if (!$this->settings->verifyCredentials($username, $password)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $username;

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
