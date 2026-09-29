<?php
/**
 * Read helpers for the admin dashboard, reports, and audit log (Phase 6).
 */

/**
 * Top summary cards: totals used by admin/dashboard.php and manager/dashboard.php.
 */
function fetchDashboardSummary(PDO $pdo): array
{
    $total = (int)$pdo->query('SELECT COUNT(*) FROM checklists')->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM checklists WHERE status = 'pending'")->fetchColumn();
    $approved = (int)$pdo->query("SELECT COUNT(*) FROM checklists WHERE status = 'approved'")->fetchColumn();
    $rejectedThisMonth = (int)$pdo->query(
        "SELECT COUNT(*) FROM checklists
         WHERE status = 'rejected' AND decided_at IS NOT NULL
           AND YEAR(decided_at) = YEAR(CURDATE()) AND MONTH(decided_at) = MONTH(CURDATE())"
    )->fetchColumn();

    return [
        'total' => $total,
        'pending' => $pending,
        'approved' => $approved,
        'rejected_this_month' => $rejectedThisMonth,
    ];
}

/**
 * KPI cards for engineer/dashboard.php: this engineer's own checklists
 * created in the current calendar month, by status.
 * @return array{draft:int, pending:int, approved:int, rejected:int}
 */
function fetchEngineerDashboardSummary(PDO $pdo, int $engineerId): array
{
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS c FROM checklists
         WHERE created_by = ?
           AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
         GROUP BY status"
    );
    $stmt->execute([$engineerId]);

    $summary = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($stmt->fetchAll() as $r) {
        if (isset($summary[$r['status']])) {
            $summary[$r['status']] = (int)$r['c'];
        }
    }
    return $summary;
}

/**
 * How many checklists this engineer sent to each manager this calendar
 * month (any checklist that ever left draft, i.e. has an assigned manager),
 * most-sent-to first.
 * @return list<array{manager_name:string, count:int}>
 */
function fetchEngineerSentToBreakdown(PDO $pdo, int $engineerId): array
{
    $stmt = $pdo->prepare(
        "SELECT mgr.full_name AS manager_name, COUNT(*) AS c
         FROM checklists c
         JOIN users mgr ON mgr.id = c.assigned_manager_id
         WHERE c.created_by = ? AND c.assigned_manager_id IS NOT NULL
           AND YEAR(c.created_at) = YEAR(CURDATE()) AND MONTH(c.created_at) = MONTH(CURDATE())
         GROUP BY mgr.id, mgr.full_name
         ORDER BY c DESC"
    );
    $stmt->execute([$engineerId]);
    return array_map(static function (array $r): array {
        return ['manager_name' => $r['manager_name'], 'count' => (int)$r['c']];
    }, $stmt->fetchAll());
}

/**
 * Checklist counts by status, among checklists created in the current calendar month.
 * @return array{pending:int, approved:int, rejected:int, draft:int}
 */
function fetchStatusBreakdownThisMonth(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT status, COUNT(*) AS c FROM checklists
         WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
         GROUP BY status"
    )->fetchAll();

    $breakdown = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'draft' => 0];
    foreach ($rows as $r) {
        if (isset($breakdown[$r['status']])) {
            $breakdown[$r['status']] = (int)$r['c'];
        }
    }
    return $breakdown;
}

/**
 * Number of checklists created per month for the trailing $months months (oldest first).
 * @return list<array{label:string, count:int}>
 */
function fetchMonthlyTrend(PDO $pdo, int $months = 12): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM checklists
         WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL ? MONTH)
         GROUP BY ym"
    );
    $stmt->execute([$months - 1]);
    $counts = array_column($stmt->fetchAll(), 'c', 'ym');

    return buildTrailingMonthSeries($months, static function (string $ym) use ($counts): int {
        return isset($counts[$ym]) ? (int)$counts[$ym] : 0;
    });
}

/**
 * Reject rate (%) per month for the trailing $months months (oldest first):
 * rejected checklists / all checklists created that month.
 * @return list<array{label:string, total:int, rejected:int, rate:float}>
 */
function fetchMonthlyRejectRate(PDO $pdo, int $months = 12): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, status, COUNT(*) AS c
         FROM checklists
         WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL ? MONTH)
         GROUP BY ym, status"
    );
    $stmt->execute([$months - 1]);

    $totals = [];
    $rejected = [];
    foreach ($stmt->fetchAll() as $row) {
        $ym = $row['ym'];
        $totals[$ym] = ($totals[$ym] ?? 0) + (int)$row['c'];
        if ($row['status'] === 'rejected') {
            $rejected[$ym] = (int)$row['c'];
        }
    }

    $series = buildTrailingMonthSeries($months, static function (string $ym) use ($totals, $rejected): array {
        $total = $totals[$ym] ?? 0;
        $rej = $rejected[$ym] ?? 0;
        return [
            'total' => $total,
            'rejected' => $rej,
            'rate' => $total > 0 ? round($rej / $total * 100, 1) : 0.0,
        ];
    });

    return array_map(static function (array $point): array {
        return [
            'label' => $point['label'],
            'total' => $point['count']['total'],
            'rejected' => $point['count']['rejected'],
            'rate' => $point['count']['rate'],
        ];
    }, $series);
}

/**
 * Shared month-range scaffolding: builds the last $months labels ('Y-m', oldest first)
 * and fills each with $valueFn($ym).
 */
function buildTrailingMonthSeries(int $months, callable $valueFn): array
{
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $series[] = ['label' => $ym, 'count' => $valueFn($ym)];
    }
    return $series;
}

/**
 * Top N master checklist items most often marked "fail", across all checklists.
 * Custom (non-master) items are excluded since they have no shared identity to rank.
 * @return list<array{id:int, topic:string, fail_count:int}>
 */
function fetchTopFailingItems(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        "SELECT ci.id, ci.topic, COUNT(*) AS fail_count
         FROM checklist_results cr
         JOIN checklist_items ci ON ci.id = cr.item_id
         WHERE cr.result = 'fail'
         GROUP BY ci.id, ci.topic
         ORDER BY fail_count DESC
         LIMIT $limit"
    );
    $stmt->execute();
    return array_map(static function (array $r): array {
        return [
            'id' => (int)$r['id'],
            'topic' => $r['topic'],
            'fail_count' => (int)$r['fail_count'],
        ];
    }, $stmt->fetchAll());
}

/**
 * Filtered checklist listing for admin/reports.php.
 * $filters keys (all optional): date_from, date_to, status, customer_id, model_id, die_type, engineer_id, manager_id
 */
function fetchReportRows(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'c.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'c.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['status'])) {
        $where[] = 'c.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'c.customer_id = ?';
        $params[] = (int)$filters['customer_id'];
    }
    if (!empty($filters['model_id'])) {
        $where[] = 'c.model_id = ?';
        $params[] = (int)$filters['model_id'];
    }
    if (!empty($filters['die_type'])) {
        $where[] = 'c.die_type = ?';
        $params[] = $filters['die_type'];
    }
    if (!empty($filters['engineer_id'])) {
        $where[] = 'c.created_by = ?';
        $params[] = (int)$filters['engineer_id'];
    }
    if (!empty($filters['manager_id'])) {
        $where[] = 'c.assigned_manager_id = ?';
        $params[] = (int)$filters['manager_id'];
    }

    $sql = "SELECT c.id, c.die_no, c.die_type, c.status, c.created_at, c.decided_at,
                   dm.name AS model_name, cu.name AS customer_name,
                   creator.full_name AS creator_name, mgr.full_name AS manager_name
            FROM checklists c
            JOIN die_models dm ON dm.id = c.model_id
            JOIN customers cu ON cu.id = c.customer_id
            JOIN users creator ON creator.id = c.created_by
            LEFT JOIN users mgr ON mgr.id = c.assigned_manager_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Filtered checklist_history listing for admin/audit_log.php.
 * $filters keys (all optional): date_from, date_to, action, action_by
 */
function fetchAuditLog(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'h.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'h.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['action'])) {
        $where[] = 'h.action = ?';
        $params[] = $filters['action'];
    }
    if (!empty($filters['action_by'])) {
        $where[] = 'h.action_by = ?';
        $params[] = (int)$filters['action_by'];
    }

    $sql = "SELECT h.id, h.checklist_id, c.die_no, h.action, h.note, h.created_at,
                   u.full_name AS action_by_name, u.role AS action_by_role
            FROM checklist_history h
            JOIN checklists c ON c.id = h.checklist_id
            JOIN users u ON u.id = h.action_by";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY h.created_at DESC, h.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
