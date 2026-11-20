<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    require_role(['Treasurer', 'Chairperson']);
    $from = (string) ($_GET['from'] ?? date('Y-m-01'));
    $to = (string) ($_GET['to'] ?? date('Y-m-d'));
    $fromDate = DateTime::createFromFormat('Y-m-d', $from);
    $toDate = DateTime::createFromFormat('Y-m-d', $to);
    if (!$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $from || $toDate->format('Y-m-d') !== $to || $from > $to) {
        fail('Choose a valid report date range.');
    }
    $stmt = $pdo->prepare(
        "SELECT d.payment_date,COUNT(*) payment_count,SUM(d.amount) total_collected,
                SUM(d.method='Cash') cash_total,SUM(d.method='Mobile Money') mobile_money_total
         FROM DUES_PAYMENT d WHERE d.payment_date BETWEEN :from_date AND :to_date
         GROUP BY d.payment_date ORDER BY d.payment_date"
    );
    $stmt->execute(['from_date' => $from, 'to_date' => $to]);
    $daily = $stmt->fetchAll();
    $stmt = $pdo->prepare(
        "SELECT r.rider_id,r.full_name,r.phone,GREATEST(0,
         (SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')*FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7)
         -COALESCE(SUM(d.amount),0)) arrears
         FROM RIDER r LEFT JOIN DUES_PAYMENT d ON d.rider_id=r.rider_id
         GROUP BY r.rider_id,r.full_name,r.phone,r.date_joined
         HAVING arrears>0 ORDER BY arrears DESC"
    );
    $stmt->execute();
    $arrears = $stmt->fetchAll();
    $stmt = $pdo->prepare(
        "SELECT status,COUNT(*) claim_count,COALESCE(SUM(amount_requested),0) requested,
                COALESCE(SUM(amount_approved),0) approved
         FROM WELFARE_CLAIM WHERE DATE(date_submitted) BETWEEN :from_date AND :to_date
         GROUP BY status ORDER BY status"
    );
    $stmt->execute(['from_date' => $from, 'to_date' => $to]);
    ok(['from' => $from, 'to' => $to, 'daily_collections' => $daily, 'arrears' => $arrears, 'claims' => $stmt->fetchAll()]);
} catch (Throwable $error) {
    api_error($error);
}
