<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    require_role(['Secretary', 'Chairperson']);
    $stmt = $pdo->query(
        "SELECT r.rider_id,r.full_name,r.phone,r.national_id,r.status,m.plate_number,
                COALESCE(MAX(CASE WHEN d.doc_type='Permit' THEN d.expiry_date END),'') AS permit_expiry,
                COALESCE(MAX(CASE WHEN d.doc_type='Insurance' THEN d.expiry_date END),'') AS insurance_expiry
         FROM RIDER r LEFT JOIN MOTORCYCLE m ON m.rider_id=r.rider_id
         LEFT JOIN PERMIT_INSURANCE d ON d.motorcycle_id=m.motorcycle_id
         GROUP BY r.rider_id,r.full_name,r.phone,r.national_id,r.status,m.plate_number
         ORDER BY r.full_name,m.plate_number"
    );
    $rows = $stmt->fetchAll();
    $alertDays = (int) $pdo->query("SELECT setting_value FROM SETTINGS WHERE setting_key='alert_days'")->fetchColumn();
    $alertDate = date('Y-m-d', strtotime('+' . $alertDays . ' days'));
    foreach ($rows as &$row) {
        foreach (['permit', 'insurance'] as $kind) {
            $key = $kind . '_expiry';
            $row[$kind . '_status'] = $row[$key] === ''
                ? 'Not recorded'
                : ($row[$key] < date('Y-m-d') ? 'Expired' : ($row[$key] <= $alertDate ? 'Expiring soon' : 'Valid'));
        }
    }
    unset($row);
    if (($_GET['format'] ?? '') === 'csv') {
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kcbsa-verified-rider-list.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Rider ID','Rider','Phone','National ID','Status','Plate number','Permit expiry','Permit validity','Insurance expiry','Insurance validity'], ',', '"', '');
        foreach ($rows as $row) {
            $values = [
                $row['rider_id'],$row['full_name'],$row['phone'],$row['national_id'],$row['status'],
                $row['plate_number'],$row['permit_expiry'],$row['permit_status'],$row['insurance_expiry'],$row['insurance_status'],
            ];
            foreach ($values as &$value) {
                $value = (string) $value;
                if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
                    $value = "'" . $value;
                }
            }
            unset($value);
            fputcsv($out, $values, ',', '"', '');
        }
        fclose($out);
        exit;
    }
    ok($rows);
} catch (Throwable $error) {
    api_error($error);
}
