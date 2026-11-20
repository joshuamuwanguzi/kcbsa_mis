<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$complianceRoles = ['Safety Officer', 'Chairperson'];

try {
    if ($method === 'GET' && $action === 'list') {
        require_role($complianceRoles);
        $stmt = $pdo->query(
            "SELECT d.doc_id,d.motorcycle_id,m.plate_number,m.rider_id,r.full_name,d.doc_type,d.doc_number,
                    d.issue_date,d.expiry_date,DATEDIFF(d.expiry_date,CURDATE()) AS days_to_expiry,
                    CASE WHEN d.expiry_date<CURDATE() THEN 'Expired'
                         WHEN d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL (SELECT setting_value FROM SETTINGS WHERE setting_key='alert_days') DAY) THEN 'Expiring soon'
                         ELSE 'Valid' END AS validity
             FROM PERMIT_INSURANCE d JOIN MOTORCYCLE m ON m.motorcycle_id=d.motorcycle_id
             JOIN RIDER r ON r.rider_id=m.rider_id
             ORDER BY d.expiry_date ASC,d.doc_type"
        );
        ok($stmt->fetchAll());
    }

    if ($method === 'POST' && in_array($action, ['create', 'update'], true)) {
        $user = require_role($complianceRoles);
        $input = request_body();
        $motorcycle = filter_var($input['motorcycle_id'] ?? null, FILTER_VALIDATE_INT);
        $type = (string) ($input['doc_type'] ?? '');
        $number = trim((string) ($input['doc_number'] ?? ''));
        $issue = (string) ($input['issue_date'] ?? '');
        $expiry = (string) ($input['expiry_date'] ?? '');
        $validDate = static function (string $value): bool {
            $date = DateTime::createFromFormat('Y-m-d', $value);
            return $date && $date->format('Y-m-d') === $value;
        };
        if (!$motorcycle || !in_array($type, ['Permit', 'Insurance'], true) || $number === '' || strlen($number) > 80 || !$validDate($issue) || !$validDate($expiry) || $expiry < $issue) {
            fail('Enter a motorcycle, document type, number, and valid issue and expiry dates.');
        }
        if ($action === 'create') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO PERMIT_INSURANCE (motorcycle_id,doc_type,doc_number,issue_date,expiry_date)
                 VALUES (:motorcycle,:type,:number,:issue,:expiry)'
            );
            $stmt->execute(['motorcycle' => $motorcycle, 'type' => $type, 'number' => $number, 'issue' => $issue, 'expiry' => $expiry]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, $user['user_id'], 'CREATE', 'PERMIT_INSURANCE', $id);
            $pdo->commit();
            ok(['doc_id' => $id], 201);
        }
        $id = filter_var($input['doc_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            fail('A valid document ID is required.');
        }
        $pdo->beginTransaction();
        $exists = $pdo->prepare('SELECT doc_id FROM PERMIT_INSURANCE WHERE doc_id=:id FOR UPDATE');
        $exists->execute(['id' => $id]);
        if (!$exists->fetch()) {
            $pdo->rollBack();
            fail('Compliance document not found.', 404);
        }
        $stmt = $pdo->prepare(
            'UPDATE PERMIT_INSURANCE SET motorcycle_id=:motorcycle,doc_type=:type,doc_number=:number,
             issue_date=:issue,expiry_date=:expiry WHERE doc_id=:id'
        );
        $stmt->execute(['motorcycle' => $motorcycle, 'type' => $type, 'number' => $number, 'issue' => $issue, 'expiry' => $expiry, 'id' => $id]);
        audit($pdo, $user['user_id'], 'UPDATE', 'PERMIT_INSURANCE', $id);
        $pdo->commit();
        ok(['doc_id' => $id]);
    }

    fail('Unknown compliance document action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
