<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    require_role(['Chairperson']);
    $stmt = $pdo->query(
        'SELECT a.log_id,a.user_id,u.username,u.role,a.action,a.table_name,a.record_id,a.details,a.created_at
         FROM AUDIT_LOG a JOIN USER_ACCOUNT u ON u.user_id=a.user_id
         ORDER BY a.created_at DESC,a.log_id DESC LIMIT 500'
    );
    ok($stmt->fetchAll());
} catch (Throwable $error) {
    api_error($error);
}
