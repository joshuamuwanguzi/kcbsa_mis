<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    require_role(['Chairperson']);
    $months = $pdo->query(
        "SELECT DATE_FORMAT(month_start,'%Y-%m') month_key,DATE_FORMAT(month_start,'%b %Y') label,
                COALESCE((SELECT SUM(amount) FROM DUES_PAYMENT d WHERE d.payment_date>=m.month_start
                          AND d.payment_date<DATE_ADD(m.month_start,INTERVAL 1 MONTH)),0) collections,
                COALESCE((SELECT COUNT(*) FROM RIDER expected_riders
                          WHERE expected_riders.status='Active'
                            AND expected_riders.date_joined<DATE_ADD(m.month_start,INTERVAL 1 MONTH)),0)
                  * (SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues') * 4 expected,
                COALESCE((SELECT COUNT(*) FROM RIDER r WHERE r.date_joined>=m.month_start
                          AND r.date_joined<DATE_ADD(m.month_start,INTERVAL 1 MONTH)),0) new_members,
                COALESCE((SELECT SUM(c.amount_approved) FROM AUDIT_LOG a
                          JOIN WELFARE_CLAIM c ON c.claim_id=a.record_id
                          WHERE a.table_name='WELFARE_CLAIM' AND a.action='PAY'
                            AND a.created_at>=m.month_start AND a.created_at<DATE_ADD(m.month_start,INTERVAL 1 MONTH)),0) claims_paid
         FROM (
           SELECT DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL seq MONTH) month_start
           FROM (SELECT 0 seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
                 SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) sequence_values
         ) m ORDER BY month_start"
    )->fetchAll();
    $arrears = (float) $pdo->query(
        "SELECT COALESCE(SUM(GREATEST(0,(SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
         * FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7)-COALESCE(p.paid,0))),0)
         FROM RIDER r LEFT JOIN (SELECT rider_id,SUM(amount) paid FROM DUES_PAYMENT GROUP BY rider_id) p ON p.rider_id=r.rider_id
         WHERE r.status='Active'"
    )->fetchColumn();
    $active = (int) $pdo->query("SELECT COUNT(*) FROM RIDER WHERE status='Active'")->fetchColumn();
    $compliant = (int) $pdo->query(
        "SELECT COUNT(DISTINCT r.rider_id) FROM RIDER r
         WHERE r.status='Active'
           AND EXISTS (SELECT 1 FROM MOTORCYCLE m JOIN PERMIT_INSURANCE p ON p.motorcycle_id=m.motorcycle_id
                       WHERE m.rider_id=r.rider_id AND p.doc_type='Permit' AND p.expiry_date>=CURDATE())
           AND EXISTS (SELECT 1 FROM MOTORCYCLE m JOIN PERMIT_INSURANCE i ON i.motorcycle_id=m.motorcycle_id
                       WHERE m.rider_id=r.rider_id AND i.doc_type='Insurance' AND i.expiry_date>=CURDATE())"
    )->fetchColumn();
    $monthlyExpected = $active * (float) $pdo->query("SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues'")->fetchColumn() * 4;
    $currentCollections = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM DUES_PAYMENT WHERE payment_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
    ok([
        'months' => $months,
        'summary' => [
            'arrears_total' => $arrears,
            'claims_paid_total' => (float) $pdo->query("SELECT COALESCE(SUM(amount_approved),0) FROM WELFARE_CLAIM WHERE status='Paid'")->fetchColumn(),
            'compliance_rate' => $active ? round($compliant / $active * 100, 1) : 0,
            'collection_rate_month' => $monthlyExpected > 0 ? round($currentCollections / $monthlyExpected * 100, 1) : 0,
        ],
    ]);
} catch (Throwable $error) {
    api_error($error);
}
