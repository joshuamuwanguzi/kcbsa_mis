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
            require_role(['Secretary', 'Safety Officer', 'Chairperson']);
        }
        $sql = 'SELECT m.*, r.full_name FROM MOTORCYCLE m JOIN RIDER r ON r.rider_id=m.rider_id';
        if ($riderId) {
            $sql .= ' WHERE m.rider_id=:rider_id';
        }
        $sql .= ' ORDER BY r.full_name, m.plate_number';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($riderId ? ['rider_id' => $riderId] : []);
        ok($stmt->fetchAll());
    }

    if ($method === 'POST' && in_array($action, ['create', 'update'], true)) {
        $user = require_role(['Secretary', 'Chairperson']);
        $input = request_body();
        $riderId = filter_var($input['rider_id'] ?? null, FILTER_VALIDATE_INT);
        $plate = strtoupper(trim((string) ($input['plate_number'] ?? '')));
        $model = trim((string) ($input['make_model'] ?? ''));
        if (!$riderId || $plate === '' || $model === '') {
            fail('Rider, plate number and make/model are required.');
        }
        if (strlen($plate) > 20 || strlen($model) > 80 || strlen((string) ($input['colour'] ?? '')) > 40) {
            fail('One or more motorcycle details are too long.');
        }
        try {
            $pdo->beginTransaction();
            if ($action === 'create') {
                $stmt = $pdo->prepare('INSERT INTO MOTORCYCLE (rider_id,plate_number,make_model,colour) VALUES (:rider,:plate,:model,:colour)');
                $stmt->execute([
                    'rider' => $riderId,
                    'plate' => $plate,
                    'model' => $model,
                    'colour' => trim((string) ($input['colour'] ?? '')) ?: null,
                ]);
                $id = (int) $pdo->lastInsertId();
                audit($pdo, $user['user_id'], 'CREATE', 'MOTORCYCLE', $id);
                $pdo->commit();
                ok(['motorcycle_id' => $id], 201);
            }
            $id = filter_var($input['motorcycle_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                $pdo->rollBack();
                fail('A valid motorcycle ID is required.');
            }
            $exists = $pdo->prepare('SELECT motorcycle_id FROM MOTORCYCLE WHERE motorcycle_id=:id FOR UPDATE');
            $exists->execute(['id' => $id]);
            if (!$exists->fetch()) {
                $pdo->rollBack();
                fail('Motorcycle not found.', 404);
            }
            $stmt = $pdo->prepare(
                'UPDATE MOTORCYCLE SET rider_id=:rider,plate_number=:plate,make_model=:model,colour=:colour
                 WHERE motorcycle_id=:id'
            );
            $stmt->execute([
                'rider' => $riderId,
                'plate' => $plate,
                'model' => $model,
                'colour' => trim((string) ($input['colour'] ?? '')) ?: null,
                'id' => $id,
            ]);
            audit($pdo, $user['user_id'], 'UPDATE', 'MOTORCYCLE', $id);
            $pdo->commit();
            ok(['motorcycle_id' => $id]);
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error->getCode() === '23000') {
                fail('That plate number is already registered, or the rider was not found.', 422);
            }
            throw $error;
        }
    }

    fail('Unknown motorcycle action.', 404);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($error);
}
