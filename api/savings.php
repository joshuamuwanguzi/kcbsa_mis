<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET' && $action === 'list') {
        $user = require_login();
        $riderId = filter_var($_GET['rider_id'] ?? null, FILTER_VALIDATE_INT);
        if ($user['role'] === 'Rider') {
            $riderId = (int) $user['rider_id'];
        } else {
            require_role(['Treasurer', 'Chairperson']);
        }
        $sql = "SELECT s.savings_id,s.rider_id,r.full_name,s.trans_type,s.amount,s.trans_date,s.recorded_by
                FROM SAVINGS_TRANSACTION s JOIN RIDER r ON r.rider_id=s.rider_id";
        $params = [];
        if ($riderId) {
            $sql .= ' WHERE s.rider_id=:rider';
            $params['rider'] = $riderId;
        }
        $sql .= ' ORDER BY s.trans_date DESC,s.savings_id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'record') {
        $user = require_role(['Treasurer']);
        $input = request_body();
        $rider = filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = valid_amount($input['amount'] ?? null);
        $type = (string) ($input['trans_type'] ?? '');
        if (!$rider || $amount === null || !in_array($type, ['Deposit', 'Withdrawal'], true)) {
            fail('Enter a valid rider, amount and transaction type.');
        }
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT rider_id FROM RIDER WHERE rider_id=:id FOR UPDATE');
        $lock->execute(['id' => $rider]);
        if (!$lock->fetch()) {
            $pdo->rollBack();
            fail('Rider not found.', 404);
        }
        $balanceQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN trans_type='Deposit' THEN amount ELSE -amount END),0)
             FROM SAVINGS_TRANSACTION WHERE rider_id=:id"
        );
        $balanceQuery->execute(['id' => $rider]);
        $balance = (float) $balanceQuery->fetchColumn();
        if ($type === 'Withdrawal' && $amount > $balance) {
            $pdo->rollBack();
            fail('Withdrawal exceeds the rider’s available savings balance.', 422);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO SAVINGS_TRANSACTION (rider_id,recorded_by,trans_type,amount,trans_date)
             VALUES (:rider,:user,:type,:amount,CURDATE())'
        );
        $stmt->execute(['rider' => $rider, 'user' => $user['user_id'], 'type' => $type, 'amount' => $amount]);
        $id = (int) $pdo->lastInsertId();
        audit($pdo, $user['user_id'], 'CREATE', 'SAVINGS_TRANSACTION', $id, ['type' => $type, 'amount' => $amount]);
        $pdo->commit();
        ok(['savings_id' => $id, 'balance' => $balance + ($type === 'Deposit' ? $amount : -$amount)], 201);
    }

    fail('Unknown savings action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
