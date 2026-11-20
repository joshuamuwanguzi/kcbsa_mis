<?php
declare(strict_types=1);

// XAMPP default: MySQL user "root" with a blank password. Change these for your installation.
const DB_HOST = 'localhost';
const DB_NAME = 'kcbsa_mis';
const DB_USER = 'root';
const DB_PASS = '';
const SESSION_IDLE_SECONDS = 1800;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$appPath = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
$appPath = rtrim($appPath, '/') . '/';
if ($appPath === '//') {
    $appPath = '/';
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $appPath,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $error) {
    error_log('KCBSA database connection failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Check api/config.php and confirm MySQL is running.']);
    exit;
}

function json_out(bool $success, mixed $data = null, string $message = '', int $status = 200): never
{
    http_response_code($status);
    $payload = ['success' => $success];
    if ($success) {
        $payload['data'] = $data;
    } else {
        $payload['message'] = $message;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ok(mixed $data = [], int $status = 200): never
{
    json_out(true, $data, '', $status);
}

function fail(string $message, int $status = 422): never
{
    json_out(false, null, $message, $status);
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail('Request body must be a JSON object.', 422);
    }
    return $decoded;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        fail('Please sign in to continue.', 401);
    }
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals((string) $_SESSION['csrf_token'], $sent)) {
            fail('Your session token is missing or expired. Refresh the page and try again.', 403);
        }
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        fail('Your account does not have permission to do that.', 403);
    }
    return $user;
}

function audit(PDO $pdo, int $userId, string $action, string $table, ?int $recordId, array $details = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO AUDIT_LOG (user_id, action, table_name, record_id, details, created_at)
         VALUES (:user_id, :action, :table_name, :record_id, :details, NOW())'
    );
    $statement->execute([
        'user_id' => $userId,
        'action' => $action,
        'table_name' => $table,
        'record_id' => $recordId,
        'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function valid_amount(mixed $value, bool $allowZero = false): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $amount = round((float) $value, 2);
    if ($amount < 0 || (!$allowZero && $amount <= 0) || $amount > 100000000) {
        return null;
    }
    return $amount;
}

function api_error(Throwable $error): never
{
    error_log('KCBSA API error: ' . $error->getMessage());
    fail('The request could not be completed. Please check the entered information and try again.', 500);
}
