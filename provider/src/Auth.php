<?php
declare(strict_types=1);
namespace BackupManager\Provider;

final class Auth {
    public static function requireManagement(Config $config): void {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $file = (string)$config->get('api', 'management_token_file', '/etc/backupmanager-provider/management-token');
        $expected = is_readable($file) ? trim((string)file_get_contents($file)) : '';
        if (!str_starts_with($header, 'Bearer ') || $expected === '' || !hash_equals($expected, trim(substr($header, 7)))) {
            Response::json(['success' => false, 'error_code' => 'provider_forbidden', 'error' => 'Management authentication required'], 403);
        }
    }
    public static function requireAdmin(Config $config): void {
        if (($_SERVER['HTTPS'] ?? '') !== 'on') {
            http_response_code(403);
            exit('Provider administration requires HTTPS. Configure the trusted web server accordingly.');
        }
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
        session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        ini_set('session.use_strict_mode', '1');
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $_SESSION['provider_lang'] = AdminText::language();
        $file = (string)$config->get('admin', 'password_hash_file', '/etc/backupmanager-provider/admin-password.hash');
        $hash = is_readable($file) ? trim((string)file_get_contents($file)) : '';
        if (isset($_SESSION['authenticated_until']) && (int)$_SESSION['authenticated_until'] > time()
            && hash_equals((string)($_SESSION['credential_version'] ?? ''), hash('sha256', $hash))) {
            if (($_POST['action'] ?? '') === 'logout' && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
                $_SESSION = [];
                session_destroy();
                header('Location: ./', true, 303);
                exit;
            }
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
            self::rateLimit('login', 10);
            if (hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')) && $hash !== ''
                && password_verify((string)($_POST['password'] ?? ''), $hash)) {
                session_regenerate_id(true);
                $_SESSION['authenticated_until'] = time() + 1800;
                $_SESSION['credential_version'] = hash('sha256', $hash);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ./', true, 303);
                exit;
            }
            http_response_code(401);
        }
        $csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
        $t = static fn(string $text): string => htmlspecialchars(AdminText::translate($text), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="' . AdminText::language() . '"><meta charset="utf-8"><title>' . $t('Provider sign in') . '</title>';
        echo '<nav><a href="?lang=nl">Nederlands</a> · <a href="?lang=en">English</a></nav><h1>' . $t('Backup Manager provider') . '</h1><form method="post"><input type="hidden" name="action" value="login">';
        echo '<input type="hidden" name="csrf" value="' . $csrf . '">';
        echo '<label>' . $t('Administrator password') . ' <input type="password" name="password" autocomplete="current-password" required></label><button>' . $t('Sign in') . '</button></form></html>';
        exit;
    }

    public static function rateLimit(string $scope, int $limit = 20): void {
        $directory = '/var/lib/backupmanager-provider-api/limits';
        if (!is_dir($directory)) {
            Response::json(['success' => false, 'error' => 'Provider rate limiter unavailable'], 503);
        }
        // Fixed bucket files keep disk usage bounded, even under spoofed/distributed requests.
        $bucket = hexdec(substr(hash('sha256', $scope . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 3));
        $stream = fopen($directory . '/' . $bucket, 'c+');
        if ($stream === false || !flock($stream, LOCK_EX)) {
            Response::json(['success' => false, 'error' => 'Provider unavailable'], 503);
        }
        $state = json_decode(stream_get_contents($stream), true) ?: ['minute' => 0, 'count' => 0];
        $minute = intdiv(time(), 60);
        $count = $state['minute'] === $minute ? (int)$state['count'] + 1 : 1;
        ftruncate($stream, 0);
        rewind($stream);
        fwrite($stream, json_encode(['minute' => $minute, 'count' => $count]));
        fclose($stream);
        if ($count > $limit) {
            header('Retry-After: 60');
            Response::json(['success' => false, 'error' => 'Too many requests'], 429);
        }
    }
}
