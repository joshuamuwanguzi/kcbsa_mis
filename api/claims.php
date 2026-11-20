<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$officialRoles = ['Secretary', 'Treasurer', 'Welfare Officer', 'Safety Officer', 'Chairperson'];

function welfare_balance(PDO $pdo): float
{
    $share = (float) $pdo->query("SELECT setting_value FROM SETTINGS WHERE setting_key='welfare_share_percent'")->fetchColumn();
    $dues = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM DUES_PAYMENT')->fetchColumn();
    $paid = (float) $pdo->query("SELECT COALESCE(SUM(amount_approved),0) FROM WELFARE_CLAIM WHERE status='Paid'")->fetchColumn();
    return max(0, round($dues * $share / 100 - $paid, 2));
}

function rider_arrears(PDO $pdo, int $riderId): float
{
    $stmt = $pdo->prepare(
        "SELECT GREATEST(0, (SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
          * FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7) - COALESCE(SUM(d.amount),0))
         FROM RIDER r LEFT JOIN DUES_PAYMENT d ON d.rider_id=r.rider_id
         WHERE r.rider_id=:id GROUP BY r.rider_id,r.date_joined"
    );
    $stmt->execute(['id' => $riderId]);
    return (float) ($stmt->fetchColumn() ?: 0);
}

try {
    if ($method === 'GET' && $action === 'list') {
        $user = require_login();
        $riderId = filter_var($_GET['rider_id'] ?? null, FILTER_VALIDATE_INT);
        if ($user['role'] === 'Rider') {
            $riderId = (int) $user['rider_id'];
        } else {
            require_role(['Treasurer', 'Welfare Officer', 'Chairperson']);
        }
        $sql = "SELECT c.claim_id,c.rider_id,r.full_name,c.claim_type,c.amount_requested,c.amount_approved,
                       c.status,c.date_submitted,c.decision_reason,c.decided_by,
                       a.username AS decided_by_name,
                       GREATEST(0,(SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
                         * FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7)-COALESCE(dues.paid,0)) AS arrears
                FROM WELFARE_CLAIM c JOIN RIDER r ON r.rider_id=c.rider_id
                LEFT JOIN USER_ACCOUNT a ON a.user_id=c.decided_by
                LEFT JOIN (SELECT rider_id,SUM(amount) paid FROM DUES_PAYMENT GROUP BY rider_id) dues ON dues.rider_id=r.rider_id";
        $params = [];
        if ($riderId) {
            $sql .= ' WHERE c.rider_id=:rider';
            $params['rider'] = $riderId;
        }
        $sql .= ' ORDER BY c.date_submitted DESC,c.claim_id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok(['claims' => $stmt->fetchAll(), 'welfare_fund_balance' => welfare_balance($pdo)]);
    }

    if ($method === 'POST' && $action === 'submit') {
        $user = require_login();
        $input = request_body();
        $riderId = $user['role'] === 'Rider'
            ? (int) $user['rider_id']
            : filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
        if ($user['role'] !== 'Rider' && !in_array($user['role'], $officialRoles, true)) {
            fail('Your account cannot submit claims.', 403);
        }
        $type = (string) ($input['claim_type'] ?? '');
        $amount = valid_amount($input['amount_requested'] ?? null);
        if (!$riderId || $amount === null || !in_array($type, ['Accident', 'Sickness', 'Funeral', 'Emergency'], true)) {
            fail('Enter a rider, claim type and valid requested amount.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO WELFARE_CLAIM (rider_id,claim_type,amount_requested,status,date_submitted)
             VALUES (:rider,:type,:amount,'Submitted',NOW())"
        );
        $stmt->execute(['rider' => $riderId, 'type' => $type, 'amount' => $amount]);
        $id = (int) $pdo->lastInsertId();
        audit($pdo, $user['user_id'], 'CREATE', 'WELFARE_CLAIM', $id, ['claim_type' => $type, 'amount_requested' => $amount]);
        $pdo->commit();
        ok(['claim_id' => $id, 'eligible' => rider_arrears($pdo, $riderId) <= 0], 201);
    }

    if ($method === 'POST' && $action === 'decide') {
        $user = require_role(['Welfare Officer']);
        $input = request_body();
        $id = filter_var($input['claim_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string) ($input['decision'] ?? '');
        $reason = trim((string) ($input['decision_reason'] ?? ''));
        $approved = valid_amount($input['amount_approved'] ?? null);
        if (!$id || !in_array($decision, ['approve', 'reject'], true)) {
            fail('Choose a valid claim and decision.');
        }
        if (strlen($reason) > 500) {
            fail('Decision note must be 500 characters or fewer.');
        }
        $stmt = $pdo->prepare('SELECT * FROM WELFARE_CLAIM WHERE claim_id=:id FOR UPDATE');
        $pdo->beginTransaction();
        $stmt->execute(['id' => $id]);
        $claim = $stmt->fetch();
        if (!$claim || $claim['status'] !== 'Submitted') {
            $pdo->rollBack();
            fail('Only submitted claims can be reviewed.', 422);
        }
        $riderLock = $pdo->prepare('SELECT rider_id FROM RIDER WHERE rider_id=:id FOR UPDATE');
        $riderLock->execute(['id' => $claim['rider_id']]);
        if (!$riderLock->fetch()) {
            $pdo->rollBack();
            fail('Rider record not found.', 404);
        }
        $arrears = rider_arrears($pdo, (int) $claim['rider_id']);
        if ($decision === 'approve') {
            if ($arrears > 0) {
                $pdo->rollBack();
                fail('Approval blocked: the rider has outstanding dues arrears. Reject with reason “Dues arrears outstanding”.', 422);
            }
            if ($approved === null || $approved > (float) $claim['amount_requested']) {
                $pdo->rollBack();
                fail('Enter an approved amount greater than zero and no more than the requested amount.');
            }
            $stmt = $pdo->prepare(
                "UPDATE WELFARE_CLAIM SET status='Approved',amount_approved=:amount,decided_by=:user,decision_reason=:reason WHERE claim_id=:id"
            );
            $stmt->execute(['amount' => $approved, 'user' => $user['user_id'], 'reason' => $reason ?: null, 'id' => $id]);
        } else {
            if ($reason === '' && $arrears > 0) {
                $reason = 'Dues arrears outstanding';
            }
            if (strlen($reason) < 3) {
                $pdo->rollBack();
                fail('A rejection reason is required.');
            }
            $stmt = $pdo->prepare(
                "UPDATE WELFARE_CLAIM SET status='Rejected',amount_approved=NULL,decided_by=:user,decision_reason=:reason WHERE claim_id=:id"
            );
            $stmt->execute(['user' => $user['user_id'], 'reason' => $reason, 'id' => $id]);
        }
        audit($pdo, $user['user_id'], strtoupper($decision), 'WELFARE_CLAIM', $id, ['reason' => $reason, 'amount_approved' => $approved]);
        $pdo->commit();
        ok(['claim_id' => $id, 'status' => $decision === 'approve' ? 'Approved' : 'Rejected']);
    }

    if ($method === 'POST' && $action === 'pay') {
        $user = require_role(['Treasurer']);
        $input = request_body();
        $id = filter_var($input['claim_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            fail('A valid claim ID is required.');
        }
        $pdo->beginTransaction();
        $pdo->query("SELECT setting_value FROM SETTINGS WHERE setting_key='welfare_share_percent' FOR UPDATE")->fetchColumn();
        $stmt = $pdo->prepare('SELECT * FROM WELFARE_CLAIM WHERE claim_id=:id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $claim = $stmt->fetch();
        if (!$claim || !in_array($claim['status'], ['Approved', 'Pending'], true) || $claim['amount_approved'] === null) {
            $pdo->rollBack();
            fail('Only an approved or pending claim with an approved amount can be paid.', 422);
        }
        $balance = welfare_balance($pdo);
        if ($balance < (float) $claim['amount_approved']) {
            $notice = 'Approved; awaiting available welfare fund';
            $stmt = $pdo->prepare("UPDATE WELFARE_CLAIM SET status='Pending',decision_reason=:notice WHERE claim_id=:id");
            $stmt->execute(['notice' => $notice, 'id' => $id]);
            audit($pdo, $user['user_id'], 'PENDING_FUNDS', 'WELFARE_CLAIM', $id, ['required' => (float) $claim['amount_approved'], 'available' => $balance]);
            $pdo->commit();
            ok(['claim_id' => $id, 'status' => 'Pending', 'message' => 'The welfare fund does not have enough money yet. The rider can see this status.', 'welfare_fund_balance' => $balance]);
        }
        $stmt = $pdo->prepare("UPDATE WELFARE_CLAIM SET status='Paid' WHERE claim_id=:id");
        $stmt->execute(['id' => $id]);
        audit($pdo, $user['user_id'], 'PAY', 'WELFARE_CLAIM', $id, ['amount' => (float) $claim['amount_approved']]);
        $pdo->commit();
        ok(['claim_id' => $id, 'status' => 'Paid']);
    }

    if ($method === 'GET' && $action === 'fund') {
        require_role($officialRoles);
        ok(['balance' => welfare_balance($pdo)]);
    }

    fail('Unknown claim action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
