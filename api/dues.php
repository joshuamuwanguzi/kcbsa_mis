<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$officials = ['Secretary', 'Treasurer', 'Chairperson'];

try {
    if ($method === 'GET' && $action === 'list') {
        $user = require_login();
        $riderId = filter_var($_GET['rider_id'] ?? null, FILTER_VALIDATE_INT);
        if ($user['role'] === 'Rider') {
            $riderId = (int) $user['rider_id'];
        } else {
            require_role($officials);
        }
        $sql = 'SELECT d.payment_id,d.rider_id,r.full_name,d.amount,d.payment_date,d.period_covered,d.method,d.recorded_by
                FROM DUES_PAYMENT d JOIN RIDER r ON r.rider_id=d.rider_id';
        $params = [];
        if ($riderId) {
            $sql .= ' WHERE d.rider_id=:rider';
            $params['rider'] = $riderId;
        }
        $sql .= ' ORDER BY d.payment_date DESC,d.payment_id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok($stmt->fetchAll());
    }

    if ($method === 'GET' && $action === 'receipt') {
        $user = require_login();
        if (!in_array($user['role'], ['Secretary', 'Treasurer', 'Chairperson', 'Rider'], true)) {
            fail('Your account cannot view receipts.', 403);
        }
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare(
            'SELECT d.*,r.full_name,r.phone,a.username AS recorded_by_name
             FROM DUES_PAYMENT d JOIN RIDER r ON r.rider_id=d.rider_id
             JOIN USER_ACCOUNT a ON a.user_id=d.recorded_by WHERE d.payment_id=:id'
        );
        $stmt->execute(['id' => $id ?: 0]);
        $receipt = $stmt->fetch();
        if (!$receipt || ($user['role'] === 'Rider' && (int) $user['rider_id'] !== (int) $receipt['rider_id'])) {
            fail('Receipt not found or not available to this account.', 403);
        }
        ok($receipt);
    }

    if ($method === 'POST' && $action === 'pay') {
        $user = require_role(['Treasurer']);
        $input = request_body();
        $rider = filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = valid_amount($input['amount'] ?? null);
        $date = (string) ($input['payment_date'] ?? date('Y-m-d'));
        $methodName = (string) ($input['method'] ?? '');
        $period = trim((string) ($input['period_covered'] ?? ''));
        if (!$rider || $amount === null || $period === '' || strlen($period) > 80 || !in_array($methodName, ['Cash', 'Mobile Money'], true)) {
            fail('Enter a valid rider, amount, period and payment method.');
        }
        $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            fail('Enter a valid payment date.');
        }
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT rider_id FROM RIDER WHERE rider_id=:rider FOR UPDATE');
        $lock->execute(['rider' => $rider]);
        if (!$lock->fetch()) {
            $pdo->rollBack();
            fail('Rider not found.', 404);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO DUES_PAYMENT (rider_id,recorded_by,amount,payment_date,period_covered,method)
             VALUES (:rider,:user,:amount,:date,:period,:method)"
        );
        $stmt->execute(['rider' => $rider, 'user' => $user['user_id'], 'amount' => $amount, 'date' => $date, 'period' => $period, 'method' => $methodName]);
        $id = (int) $pdo->lastInsertId();
        audit($pdo, $user['user_id'], 'CREATE', 'DUES_PAYMENT', $id, ['amount' => $amount, 'period' => $period]);
        $pdo->commit();
        ok(['payment_id' => $id], 201);
    }

    if ($method === 'POST' && $action === 'reverse') {
        $user = require_role(['Treasurer']);
        $input = request_body();
        $id = filter_var($input['payment_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!$id || strlen($reason) < 5) {
            fail('Choose a payment and provide a reversal reason of at least 5 characters.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM DUES_PAYMENT WHERE payment_id=:id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $original = $stmt->fetch();
        if (!$original || (float) $original['amount'] <= 0) {
            $pdo->rollBack();
            fail('Only an original payment can be reversed.', 422);
        }
        $check = $pdo->prepare("SELECT payment_id FROM DUES_PAYMENT WHERE period_covered=:period LIMIT 1 FOR UPDATE");
        $check->execute(['period' => 'REVERSAL of #' . $id]);
        if ($check->fetch()) {
            $pdo->rollBack();
            fail('This payment has already been reversed.', 422);
        }
        $insert = $pdo->prepare(
            'INSERT INTO DUES_PAYMENT (rider_id,recorded_by,amount,payment_date,period_covered,method)
             VALUES (:rider,:user,:amount,CURDATE(),:period,:method)'
        );
        $period = 'REVERSAL of #' . $id;
        $insert->execute([
            'rider' => $original['rider_id'],
            'user' => $user['user_id'],
            'amount' => -abs((float) $original['amount']),
            'period' => $period,
            'method' => $original['method'],
        ]);
        $reversalId = (int) $pdo->lastInsertId();
        audit($pdo, $user['user_id'], 'REVERSE', 'DUES_PAYMENT', $reversalId, ['reversed_payment_id' => $id, 'reason' => $reason]);
        $pdo->commit();
        ok(['payment_id' => $reversalId, 'reversed_payment_id' => $id]);
    }

    fail('Unknown dues action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
