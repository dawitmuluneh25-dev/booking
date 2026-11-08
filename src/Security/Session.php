<?php

declare(strict_types=1);

namespace App\Security;

use App\Bootstrap\Config;

/**
 * Native PHP session with hardened cookie defaults.
 */
final class Session
{
    private const FLASH_KEY = '_flash';

    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config->sessionName());

        $secure = $config->sessionSecure();
        if (!$secure && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
            $secure = true;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $config->sessionLifetime());

        session_start();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_SESSION[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function put(string $key, string $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, string $value): void
    {
        $flash = self::flashBag();
        $flash[$key] = $value;
        $_SESSION[self::FLASH_KEY] = $flash;
    }

    public static function pullFlash(string $key): ?string
    {
        $flash = self::flashBag();
        $value = $flash[$key] ?? null;
        unset($flash[$key]);
        $_SESSION[self::FLASH_KEY] = $flash;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> */
    private static function flashBag(): array
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? [];
        if (!is_array($flash)) {
            return [];
        }

        /** @var array<string, string> $flash */
        return $flash;
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'PHPSESSID', '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }
}
