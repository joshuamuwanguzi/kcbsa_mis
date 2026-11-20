<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$roles = ['Safety Officer', 'Chairperson'];

try {
    if ($method === 'GET' && $action === 'list') {
        require_role($roles);
        $rider = filter_var($_GET['rider_id'] ?? null, FILTER_VALIDATE_INT);
        $sql = 'SELECT i.*,r.full_name,a.username AS recorded_by_name FROM INCIDENT i
                JOIN RIDER r ON r.rider_id=i.rider_id JOIN USER_ACCOUNT a ON a.user_id=i.recorded_by';
        $params = [];
        if ($rider) {
            $sql .= ' WHERE i.rider_id=:rider';
            $params['rider'] = $rider;
        }
        $sql .= ' ORDER BY i.incident_date DESC,i.incident_id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'create') {
        $user = require_role($roles);
        $input = request_body();
        $rider = filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
        $type = (string) ($input['incident_type'] ?? '');
        $date = (string) ($input['incident_date'] ?? date('Y-m-d'));
        $description = trim((string) ($input['description'] ?? ''));
        $validDate = DateTime::createFromFormat('Y-m-d', $date);
        $actionTaken = trim((string) ($input['action_taken'] ?? ''));
        if (!$rider || !in_array($type, ['Accident', 'Discipline', 'Other'], true) || !$validDate || $validDate->format('Y-m-d') !== $date ||
            $description === '' || strlen($description) > 1000 || strlen($actionTaken) > 1000) {
            fail('Enter a rider, incident type, valid date and description.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO INCIDENT (rider_id,recorded_by,incident_date,incident_type,description,action_taken)
             VALUES (:rider,:user,:date,:type,:description,:action)'
        );
        $stmt->execute([
            'rider' => $rider,
            'user' => $user['user_id'],
            'date' => $date,
            'type' => $type,
            'description' => $description,
            'action' => $actionTaken ?: null,
        ]);
        $id = (int) $pdo->lastInsertId();
        audit($pdo, $user['user_id'], 'CREATE', 'INCIDENT', $id);
        $pdo->commit();
        ok(['incident_id' => $id], 201);
    }

    fail('Unknown incident action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
