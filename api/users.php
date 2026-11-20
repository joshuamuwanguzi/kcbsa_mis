<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    $user = require_role(['Chairperson']);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $stmt = $pdo->query(
            'SELECT user_id,rider_id,username,role,status FROM USER_ACCOUNT ORDER BY role,username'
        );
        ok($stmt->fetchAll());
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'create') {
        $input = request_body();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? '');
        $allowedRoles = ['Secretary', 'Treasurer', 'Welfare Officer', 'Safety Officer', 'Chairperson', 'Rider'];
        if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $username) || strlen($password) < 10 || strlen($password) > 200 || !in_array($role, $allowedRoles, true)) {
            fail('Use a valid username, a password of at least 10 characters, and an allowed role.');
        }
        $riderId = null;
        if ($role === 'Rider') {
            $riderId = filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$riderId) {
                fail('A rider account must be linked to a rider record.');
            }
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO USER_ACCOUNT (rider_id,username,password_hash,role,status)
                 VALUES (:rider,:username,:hash,:role,'Active')"
            );
            $stmt->execute([
                'rider' => $riderId,
                'username' => $username,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, $user['user_id'], 'CREATE', 'USER_ACCOUNT', $id, ['username' => $username, 'role' => $role]);
            $pdo->commit();
            ok(['user_id' => $id], 201);
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error->getCode() === '23000') {
                fail('That username already exists, or the rider already has a login.', 422);
            }
            throw $error;
        }
    }
    fail('Unknown user action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
