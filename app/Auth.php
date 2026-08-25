<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Database;

final class Auth
{
    /**
     * How long a sign-in lasts.
     *
     * Long on purpose: this is a tool three people open a few times a week, and
     * being thrown back to the sign-in screen every visit is friction with no
     * security benefit for accounts that sign in by email alone anyway. Anyone
     * who wants the old behaviour back for a person can tick "Requires a
     * password" on the Users screen.
     */
    private const SESSION_DAYS = 30;

    private const SESSION_NAME = 'prospector_session';

    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    public static function start(): void
    {
        // Sessions are meaningless for the CLI runner, and starting one there
        // only produces warnings.
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = self::SESSION_DAYS * 86400;

        // Keep session files in our own directory.
        //
        // On shared hosting the default save path is shared, and garbage
        // collection there runs on whatever gc_maxlifetime the *other* site
        // configured — typically 24 minutes. A month-long cookie pointing at a
        // file some neighbour's request already deleted is the failure this
        // avoids, and it is invisible when it happens: the browser still has a
        // session cookie, the server just no longer knows what it means.
        $path = dirname(__DIR__) . '/storage/sessions';
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }

        // The server has to keep the data at least as long as the browser keeps
        // the cookie, or the cookie outlives what it refers to.
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        $secure = self::isHttps();

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);
        session_name(self::SESSION_NAME);
        session_start();

        self::extendCookie($lifetime, $secure);
    }

    /**
     * Push the cookie's expiry back out to a full term on every visit.
     *
     * PHP sets the cookie when the session is created and never again, so
     * without this the month would count from the first sign-in and expire
     * mid-use a month later regardless of how often the tool was opened. Re-sent
     * only once a day, because a Set-Cookie on every single response is noise.
     */
    private static function extendCookie(int $lifetime, bool $secure): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        $now = time();
        $refreshed = (int) ($_SESSION['cookie_refreshed_at'] ?? 0);

        if ($refreshed > $now - 86400) {
            return;
        }

        $wasJustCreated = $refreshed === 0;
        $_SESSION['cookie_refreshed_at'] = $now;

        // A session created on this request already had its cookie sent by
        // session_start, with these same parameters. Sending it again would put
        // an identical duplicate Set-Cookie on every first response.
        if ($wasJustCreated) {
            return;
        }

        setcookie(session_name(), session_id(), [
            'expires' => $now + $lifetime,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Seed the three accounts on first boot. Billy and Darren sign in with just
     * their email address; Scott's admin account requires the password.
     */
    public static function seed(): void
    {
        if ((int) Database::scalar('SELECT COUNT(*) FROM users') > 0) {
            return;
        }

        Users::create([
            'name' => 'Scott',
            'email' => 'scott@44interactive.com',
            'role' => 'admin',
            'password' => '44i123',
            'requires_password' => true,
            'loop' => 'none',
            'daily_email' => 1,
            'autorun' => 0,
        ]);

        Users::create([
            'name' => 'Billy',
            'email' => 'billy@44idigital.com',
            'role' => 'user',
            'password' => '44i123',
            'requires_password' => false,
            'loop' => 'partner',
            'daily_email' => 1,
            'autorun' => 1,
        ]);

        Users::create([
            'name' => 'Darren',
            'email' => 'darren@44i.com',
            'role' => 'user',
            'password' => '44i123',
            'requires_password' => false,
            'loop' => 'client',
            'daily_email' => 1,
            'autorun' => 1,
        ]);
    }

    /**
     * @return array{ok: bool, error?: string, needs_password?: bool}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }

        // Throttle by IP + email so email-only sign-in cannot be brute-forced
        // for a password-protected account.
        if (self::throttled($email)) {
            return ['ok' => false, 'error' => 'Too many attempts. Wait a minute and try again.'];
        }

        $user = Users::findByEmail($email);

        if ($user === null || (int) $user['active'] !== 1) {
            self::recordFailure($email);

            return ['ok' => false, 'error' => 'No active account for that email address.'];
        }

        if ((int) $user['requires_password'] === 1) {
            if ($password === '') {
                return ['ok' => false, 'error' => 'This account needs a password.', 'needs_password' => true];
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) {
                self::recordFailure($email);

                return ['ok' => false, 'error' => 'That password is not right.', 'needs_password' => true];
            }
        }

        self::clearFailures($email);
        self::login($user);

        return ['ok' => true];
    }

    /** @param array<string, mixed> $user */
    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['authenticated_at'] = time();
        self::$user = $user;

        Database::update('users', ['last_login_at' => Clock::now()], ['id' => (int) $user['id']]);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        self::start();
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id === 0) {
            return null;
        }

        $user = Users::find($id);
        if ($user === null || (int) $user['active'] !== 1) {
            self::logout();

            return null;
        }

        return self::$user = $user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** Admins may act on any user's leads; everyone else only on their own. */
    public static function canAccessUser(int $userId): bool
    {
        return self::isAdmin() || self::id() === $userId;
    }

    private static function failureKey(string $email): string
    {
        return 'login_failures_' . hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    }

    private static function throttled(string $email): bool
    {
        self::start();
        $entry = $_SESSION[self::failureKey($email)] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        if ((int) $entry['at'] < time() - 60) {
            return false;
        }

        return (int) $entry['count'] >= 8;
    }

    private static function recordFailure(string $email): void
    {
        self::start();
        $key = self::failureKey($email);
        $entry = $_SESSION[$key] ?? ['count' => 0, 'at' => time()];
        if ((int) $entry['at'] < time() - 60) {
            $entry = ['count' => 0, 'at' => time()];
        }
        $entry['count'] = (int) $entry['count'] + 1;
        $entry['at'] = time();
        $_SESSION[$key] = $entry;
    }

    private static function clearFailures(string $email): void
    {
        self::start();
        unset($_SESSION[self::failureKey($email)]);
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        $expected = (string) ($_SESSION['csrf_token'] ?? '');

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}
