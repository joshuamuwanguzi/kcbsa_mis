<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    $user = require_login();
    if ($user['role'] === 'Rider') {
        $riderId = (int) $user['rider_id'];
        $stmt = $pdo->prepare(
            "SELECT r.rider_id,r.full_name,r.national_id,r.phone,r.address,r.date_joined,
                    r.next_of_kin_name,r.next_of_kin_phone,r.status,
                    GREATEST(0,(SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
                    * FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7)-COALESCE(d.paid,0)) arrears,
                    COALESCE(s.balance,0) savings_balance,COALESCE(m.plates,'') motorcycle_plates
             FROM RIDER r
             LEFT JOIN (SELECT rider_id,SUM(amount) paid FROM DUES_PAYMENT GROUP BY rider_id) d ON d.rider_id=r.rider_id
             LEFT JOIN (SELECT rider_id,SUM(IF(trans_type='Deposit',amount,-amount)) balance
                        FROM SAVINGS_TRANSACTION GROUP BY rider_id) s ON s.rider_id=r.rider_id
             LEFT JOIN (SELECT rider_id,GROUP_CONCAT(plate_number ORDER BY plate_number SEPARATOR ', ') plates
                        FROM MOTORCYCLE GROUP BY rider_id) m ON m.rider_id=r.rider_id
             WHERE r.rider_id=:id"
        );
        $stmt->execute(['id' => $riderId]);
        $summary = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT claim_id,claim_type,amount_requested,amount_approved,status,date_submitted,decision_reason FROM WELFARE_CLAIM WHERE rider_id=:id ORDER BY date_submitted DESC");
        $stmt->execute(['id' => $riderId]);
        $summary['claims'] = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT payment_id,amount,payment_date,period_covered,method FROM DUES_PAYMENT WHERE rider_id=:id ORDER BY payment_date DESC LIMIT 12');
        $stmt->execute(['id' => $riderId]);
        $summary['recent_payments'] = $stmt->fetchAll();
        ok($summary);
    }

    require_role(['Secretary', 'Treasurer', 'Welfare Officer', 'Safety Officer', 'Chairperson']);
    $counts = $pdo->query(
        "SELECT COUNT(*) total_riders,
                SUM(status='Active') active_riders,
                SUM(status='Suspended') suspended_riders
         FROM RIDER"
    )->fetch();
    $collected = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM DUES_PAYMENT WHERE payment_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
    $weeklyCollected = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM DUES_PAYMENT WHERE payment_date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
    $paidClaimsTotal = (float) $pdo->query("SELECT COALESCE(SUM(amount_approved),0) FROM WELFARE_CLAIM WHERE status='Paid'")->fetchColumn();
    $arrears = (float) $pdo->query(
        "SELECT COALESCE(SUM(GREATEST(0,(SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
         * FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7)-COALESCE(p.paid,0))),0)
         FROM RIDER r LEFT JOIN (SELECT rider_id,SUM(amount) paid FROM DUES_PAYMENT GROUP BY rider_id) p ON p.rider_id=r.rider_id
         WHERE r.status='Active'"
    )->fetchColumn();
    $fundShare = (float) $pdo->query("SELECT setting_value FROM SETTINGS WHERE setting_key='welfare_share_percent'")->fetchColumn();
    $totalDues = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM DUES_PAYMENT')->fetchColumn();
    $paidClaims = (float) $pdo->query("SELECT COALESCE(SUM(amount_approved),0) FROM WELFARE_CLAIM WHERE status='Paid'")->fetchColumn();
    $fund = max(0, round($totalDues * $fundShare / 100 - $paidClaims, 2));
    $claims = $pdo->query("SELECT COUNT(*) FROM WELFARE_CLAIM WHERE status IN ('Submitted','Approved','Pending')")->fetchColumn();
    $alertDocs = $pdo->query(
        "SELECT COUNT(*) FROM PERMIT_INSURANCE WHERE expiry_date<=DATE_ADD(CURDATE(),INTERVAL
         (SELECT setting_value FROM SETTINGS WHERE setting_key='alert_days') DAY)"
    )->fetchColumn();
    $stmt = $pdo->query(
        "SELECT r.rider_id,r.full_name,r.phone,r.status,
                GREATEST(0,(SELECT setting_value FROM SETTINGS WHERE setting_key='weekly_dues')
                  * FLOOR(DATEDIFF(CURDATE(),r.date_joined)/7)-COALESCE(p.paid,0)) arrears
         FROM RIDER r LEFT JOIN (SELECT rider_id,SUM(amount) paid FROM DUES_PAYMENT GROUP BY rider_id) p ON p.rider_id=r.rider_id
         WHERE r.status='Active' ORDER BY arrears DESC LIMIT 8"
    );
    $arrearsList = $stmt->fetchAll();
    $stmt = $pdo->query(
        "SELECT claim_id,r.full_name,claim_type,amount_requested,status,date_submitted FROM WELFARE_CLAIM c
         JOIN RIDER r ON r.rider_id=c.rider_id WHERE status IN ('Submitted','Approved','Pending')
         ORDER BY date_submitted LIMIT 8"
    );
    $claimList = $stmt->fetchAll();
    $stmt = $pdo->query(
        "SELECT d.doc_type,d.expiry_date,m.plate_number,r.full_name,
                DATEDIFF(d.expiry_date,CURDATE()) days_to_expiry
         FROM PERMIT_INSURANCE d JOIN MOTORCYCLE m ON m.motorcycle_id=d.motorcycle_id
         JOIN RIDER r ON r.rider_id=m.rider_id
         WHERE d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL
         (SELECT setting_value FROM SETTINGS WHERE setting_key='alert_days') DAY)
         ORDER BY d.expiry_date LIMIT 8"
    );
    $documents = $stmt->fetchAll();
    $monthSeries = "(SELECT DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL seq MONTH) month_start
                    FROM (SELECT 0 seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
                          SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
                          SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) seqs)";
    $monthly = $pdo->query(
        "SELECT DATE_FORMAT(m.month_start,'%Y-%m') month_key,DATE_FORMAT(m.month_start,'%b %Y') label,
                COALESCE(SUM(d.amount),0) collected FROM $monthSeries m
         LEFT JOIN DUES_PAYMENT d ON d.payment_date>=m.month_start AND d.payment_date<DATE_ADD(m.month_start,INTERVAL 1 MONTH)
         GROUP BY m.month_start ORDER BY m.month_start"
    )->fetchAll();
    $membership = $pdo->query(
        "SELECT DATE_FORMAT(m.month_start,'%Y-%m') month_key,DATE_FORMAT(m.month_start,'%b %Y') label,
                COUNT(r.rider_id) joined FROM $monthSeries m
         LEFT JOIN RIDER r ON r.date_joined>=m.month_start AND r.date_joined<DATE_ADD(m.month_start,INTERVAL 1 MONTH)
         GROUP BY m.month_start ORDER BY m.month_start"
    )->fetchAll();
    $monthlyClaims = $pdo->query(
        "SELECT DATE_FORMAT(m.month_start,'%Y-%m') month_key,DATE_FORMAT(m.month_start,'%b %Y') label,
                COALESCE(SUM(c.amount_approved),0) paid
         FROM $monthSeries m
         LEFT JOIN AUDIT_LOG a ON a.table_name='WELFARE_CLAIM' AND a.action='PAY'
           AND a.created_at>=m.month_start AND a.created_at<DATE_ADD(m.month_start,INTERVAL 1 MONTH)
         LEFT JOIN WELFARE_CLAIM c ON c.claim_id=a.record_id
         GROUP BY m.month_start ORDER BY m.month_start"
    )->fetchAll();
    ok([
        'summary' => [
            'total_riders' => (int) $counts['total_riders'],
            'active_riders' => (int) $counts['active_riders'],
            'suspended_riders' => (int) $counts['suspended_riders'],
            'monthly_collections' => $collected,
            'weekly_collections' => $weeklyCollected,
            'arrears_total' => $arrears,
            'claims_paid_total' => $paidClaimsTotal,
            'claims_awaiting_action' => (int) $claims,
            'welfare_fund_balance' => $fund,
            'documents_alert' => (int) $alertDocs,
        ],
        'arrears_list' => $arrearsList,
        'claims_list' => $claimList,
        'documents_alerts' => $documents,
        'monthly_collections' => $monthly,
        'membership_growth' => $membership,
        'monthly_claims_paid' => $monthlyClaims,
    ]);
} catch (Throwable $error) {
    api_error($error);
}
