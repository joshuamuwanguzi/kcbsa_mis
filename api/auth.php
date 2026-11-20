<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'login' && $method === 'POST') {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals((string) $_SESSION['csrf_token'], $sent)) {
            fail('Refresh the sign-in page and try again.', 403);
        }
        $input = request_body();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($username === '' || $password === '' || strlen($username) > 80 || strlen($password) > 200) {
            fail('Enter your username and password.');
        }
        $statement = $pdo->prepare(
            "SELECT user_id, rider_id, username, password_hash, role, status
             FROM USER_ACCOUNT WHERE username = :username LIMIT 1"
        );
        $statement->execute(['username' => $username]);
        $account = $statement->fetch();
        if (!$account || $account['status'] !== 'Active' || !password_verify($password, $account['password_hash'])) {
            fail('The username or password is incorrect.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'user_id' => (int) $account['user_id'],
            'rider_id' => $account['rider_id'] === null ? null : (int) $account['rider_id'],
            'username' => $account['username'],
            'role' => $account['role'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        ok(['user' => $_SESSION['user'], 'csrf_token' => $_SESSION['csrf_token'], 'redirect' => $account['role'] === 'Rider' ? 'my_account.html' : 'dashboard.html']);
    }

    if ($action === 'logout' && $method === 'POST') {
        require_login();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        ok(['logged_out' => true]);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = current_user();
        ok(['user' => $user, 'csrf_token' => $_SESSION['csrf_token']]);
    }

    fail('Unknown authentication action.', 404);
} catch (Throwable $error) {
    api_error($error);
}
