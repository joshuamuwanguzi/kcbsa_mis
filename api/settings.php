<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        require_role(['Secretary', 'Treasurer', 'Welfare Officer', 'Safety Officer', 'Chairperson']);
        $stmt = $pdo->query('SELECT setting_key,setting_value FROM SETTINGS ORDER BY setting_key');
        ok($stmt->fetchAll());
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'update') {
        $user = require_role(['Chairperson']);
        $input = request_body();
        $rules = [
            'weekly_dues' => [1, 1000000],
            'arrears_limit' => [0, 100000000],
            'alert_days' => [1, 365],
            'welfare_share_percent' => [0, 100],
        ];
        $pdo->beginTransaction();
        foreach ($rules as $key => [$min, $max]) {
            $value = isset($input[$key]) ? filter_var((string) $input[$key], FILTER_VALIDATE_INT) : false;
            if ($value === false || $value < $min || $value > $max) {
                $pdo->rollBack();
                fail('Enter valid values for all settings.');
            }
            $value = (string) $value;
            $stmt = $pdo->prepare(
                'INSERT INTO SETTINGS (setting_key,setting_value) VALUES (:key,:value)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
            );
            $stmt->execute(['key' => $key, 'value' => $value]);
            audit($pdo, $user['user_id'], 'UPDATE', 'SETTINGS', null, ['setting' => $key, 'value' => $value]);
        }
        $pdo->commit();
        ok(['updated' => true]);
    }
    fail('Unknown settings action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
