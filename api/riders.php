<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$officialRoles = ['Secretary', 'Treasurer', 'Welfare Officer', 'Safety Officer', 'Chairperson'];

try {
    if ($method === 'GET' && $action === 'list') {
        $user = require_role($officialRoles);
        $query = trim((string) ($_GET['q'] ?? ''));
        $sql = "SELECT r.rider_id, r.national_id, r.full_name, r.phone, r.address, r.date_joined,
                       r.status, r.next_of_kin_name, r.next_of_kin_phone,
                       COALESCE(p.paid_total, 0) AS paid_total,
                       GREATEST(0, (SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
                         * FLOOR(DATEDIFF(CURDATE(), r.date_joined) / 7) - COALESCE(p.paid_total, 0)) AS arrears,
                       COALESCE(m.plates, '') AS plates
                FROM RIDER r
                LEFT JOIN (SELECT rider_id, SUM(amount) AS paid_total FROM DUES_PAYMENT GROUP BY rider_id) p ON p.rider_id=r.rider_id
                LEFT JOIN (SELECT rider_id, GROUP_CONCAT(plate_number ORDER BY plate_number SEPARATOR ', ') AS plates
                           FROM MOTORCYCLE GROUP BY rider_id) m ON m.rider_id=r.rider_id
                WHERE (:q = '' OR r.full_name LIKE :name_q OR r.phone LIKE :phone_q OR r.national_id LIKE :id_q)
                ORDER BY r.full_name";
        $stmt = $pdo->prepare($sql);
        $like = '%' . $query . '%';
        $stmt->execute(['q' => $query, 'name_q' => $like, 'phone_q' => $like, 'id_q' => $like]);
        $riders = $stmt->fetchAll();
        if (!in_array($user['role'], ['Secretary', 'Chairperson'], true)) {
            foreach ($riders as &$rider) {
                unset($rider['national_id'], $rider['address'], $rider['next_of_kin_name'], $rider['next_of_kin_phone']);
            }
            unset($rider);
        }
        ok($riders);
    }

    if ($method === 'GET' && $action === 'me') {
        $user = require_role(['Rider']);
        $_GET['id'] = (string) $user['rider_id'];
        $action = 'get';
    }

    if ($method === 'GET' && $action === 'get') {
        $user = current_user();
        if (!$user) {
            fail('Please sign in to continue.', 401);
        }
        if ($user['role'] === 'Rider') {
            require_login();
        } else {
            require_role(['Secretary', 'Chairperson']);
        }
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || ($user['role'] === 'Rider' && (int) $user['rider_id'] !== $id)) {
            fail('Rider record not found or not available to this account.', 403);
        }
        $stmt = $pdo->prepare(
            "SELECT r.*, COALESCE(p.paid_total,0) AS paid_total,
                    GREATEST(0, (SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
                      * FLOOR(DATEDIFF(CURDATE(), r.date_joined) / 7) - COALESCE(p.paid_total,0)) AS arrears,
                    COALESCE(s.balance,0) AS savings_balance
             FROM RIDER r
             LEFT JOIN (SELECT rider_id,SUM(amount) paid_total FROM DUES_PAYMENT GROUP BY rider_id) p ON p.rider_id=r.rider_id
             LEFT JOIN (SELECT rider_id,SUM(CASE WHEN trans_type='Deposit' THEN amount ELSE -amount END) balance
                        FROM SAVINGS_TRANSACTION GROUP BY rider_id) s ON s.rider_id=r.rider_id
             WHERE r.rider_id=:id"
        );
        $stmt->execute(['id' => $id]);
        $rider = $stmt->fetch();
        if (!$rider) {
            fail('Rider record not found.', 404);
        }
        $stmt = $pdo->prepare('SELECT * FROM MOTORCYCLE WHERE rider_id=:id ORDER BY plate_number');
        $stmt->execute(['id' => $id]);
        $rider['motorcycles'] = $stmt->fetchAll();
        ok($rider);
    }

    if ($method === 'POST' && in_array($action, ['create', 'update', 'suspend'], true)) {
        $user = require_role(['Secretary', 'Chairperson']);
        $input = request_body();
        if ($action === 'create') {
            foreach (['national_id', 'full_name', 'phone', 'address', 'date_joined'] as $field) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    fail('Complete all required rider fields.');
                }
            }
            if (strlen((string) $input['national_id']) > 32 || strlen((string) $input['full_name']) > 120 || strlen((string) $input['phone']) > 32) {
                fail('One or more rider details are too long.');
            }
            if (strlen((string) ($input['address'] ?? '')) > 180 || strlen((string) ($input['next_of_kin_name'] ?? '')) > 120 || strlen((string) ($input['next_of_kin_phone'] ?? '')) > 32) {
                fail('One or more rider details are too long.');
            }
            $date = DateTime::createFromFormat('Y-m-d', (string) $input['date_joined']);
            if (!$date || $date->format('Y-m-d') !== $input['date_joined']) {
                fail('Enter a valid joining date.');
            }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "INSERT INTO RIDER (national_id, full_name, phone, address, date_joined, status, next_of_kin_name, next_of_kin_phone)
                     VALUES (:national_id,:full_name,:phone,:address,:date_joined,'Active',:kin_name,:kin_phone)"
                );
                $stmt->execute([
                    'national_id' => trim($input['national_id']),
                    'full_name' => trim($input['full_name']),
                    'phone' => trim($input['phone']),
                    'address' => trim($input['address']),
                    'date_joined' => $date->format('Y-m-d'),
                    'kin_name' => trim((string) ($input['next_of_kin_name'] ?? '')) ?: null,
                    'kin_phone' => trim((string) ($input['next_of_kin_phone'] ?? '')) ?: null,
                ]);
                $id = (int) $pdo->lastInsertId();
                audit($pdo, $user['user_id'], 'CREATE', 'RIDER', $id);
                $pdo->commit();
                ok(['rider_id' => $id], 201);
            } catch (PDOException $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($error->getCode() === '23000') {
                    fail('That national ID is already registered.', 422);
                }
                throw $error;
            }
        }

        $id = filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            fail('A valid rider ID is required.');
        }
        if ($action === 'update') {
            foreach (['full_name', 'phone', 'address'] as $field) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    fail('Name, phone and address cannot be blank.');
                }
            }
            if (strlen((string) $input['full_name']) > 120 || strlen((string) $input['phone']) > 32 || strlen((string) $input['address']) > 180 ||
                strlen((string) ($input['next_of_kin_name'] ?? '')) > 120 || strlen((string) ($input['next_of_kin_phone'] ?? '')) > 32) {
                fail('One or more rider details are too long.');
            }
            $pdo->beginTransaction();
            $exists = $pdo->prepare('SELECT rider_id FROM RIDER WHERE rider_id=:id FOR UPDATE');
            $exists->execute(['id' => $id]);
            if (!$exists->fetch()) {
                $pdo->rollBack();
                fail('Rider not found.', 404);
            }
            $stmt = $pdo->prepare(
                'UPDATE RIDER SET full_name=:name, phone=:phone, address=:address, next_of_kin_name=:kin_name,
                 next_of_kin_phone=:kin_phone WHERE rider_id=:id'
            );
            $stmt->execute([
                'name' => trim((string) ($input['full_name'] ?? '')),
                'phone' => trim((string) ($input['phone'] ?? '')),
                'address' => trim((string) ($input['address'] ?? '')),
                'kin_name' => trim((string) ($input['next_of_kin_name'] ?? '')) ?: null,
                'kin_phone' => trim((string) ($input['next_of_kin_phone'] ?? '')) ?: null,
                'id' => $id,
            ]);
            audit($pdo, $user['user_id'], 'UPDATE', 'RIDER', $id);
            $pdo->commit();
            ok(['rider_id' => $id]);
        }

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT status FROM RIDER WHERE rider_id=:id FOR UPDATE');
        $lock->execute(['id' => $id]);
        $record = $lock->fetch();
        if (!$record || $record['status'] !== 'Active') {
            $pdo->rollBack();
            fail('Only an active rider can be suspended.', 422);
        }
        $stmt = $pdo->prepare(
            "SELECT GREATEST(0, (SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
              * FLOOR(DATEDIFF(CURDATE(), r.date_joined) / 7) - COALESCE(SUM(d.amount),0)) AS arrears
             FROM RIDER r LEFT JOIN DUES_PAYMENT d ON d.rider_id=r.rider_id
             WHERE r.rider_id=:id GROUP BY r.rider_id, r.date_joined"
        );
        $stmt->execute(['id' => $id]);
        $arrears = $stmt->fetch();
        $limit = (float) $pdo->query("SELECT setting_value FROM SETTINGS WHERE setting_key='arrears_limit'")->fetchColumn();
        if (!$arrears || (float) $arrears['arrears'] < $limit) {
            $pdo->rollBack();
            fail('This rider has not reached the suspension arrears limit.', 422);
        }
        $stmt = $pdo->prepare("UPDATE RIDER SET status='Suspended' WHERE rider_id=:id AND status='Active'");
        $stmt->execute(['id' => $id]);
        audit($pdo, $user['user_id'], 'SUSPEND', 'RIDER', $id, ['arrears' => (float) $arrears['arrears']]);
        $pdo->commit();
        ok(['rider_id' => $id, 'status' => 'Suspended', 'arrears' => (float) $arrears['arrears']]);
    }

    fail('Unknown rider action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
