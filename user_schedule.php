<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'user') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Fetch user fullname
$user_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$default_fullname = $user_stmt->get_result()->fetch_assoc()['fullname'] ?? '';

// Fetch all scheduled events for this user
$user_schedules = [];

// 1. Office supply schedules
$off_res = $conn->query("
    SELECT r.request_group_id, r.date_needed, r.scheduled_time, r.purpose, r.status,
           GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Item'), ' (x', r.quantity, ')') SEPARATOR ', ') AS items_summary
    FROM supply_requests r
    LEFT JOIN items i ON r.item_id = i.id
    WHERE r.user_id = {$user_id} AND r.date_needed IS NOT NULL
    GROUP BY r.request_group_id, r.date_needed, r.scheduled_time, r.purpose, r.status
");
if ($off_res) {
    while ($r = $off_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['date_needed'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Office Supply Pickup',
            'badge' => 'bg-primary',
            'id' => $r['request_group_id'],
            'items' => $r['items_summary'],
            'status' => $r['status']
        ];
    }
}

// 2. Maintenance supply schedules
$mnt_res = $conn->query("
    SELECT r.request_group_id, r.date_needed, r.scheduled_time, r.purpose, r.status,
           GROUP_CONCAT(CONCAT(IFNULL(m.item_name, 'Item'), ' (x', r.quantity, ')') SEPARATOR ', ') AS items_summary
    FROM maintenance_requests r
    LEFT JOIN maintenance_items m ON r.item_id = m.id
    WHERE r.user_id = {$user_id} AND r.date_needed IS NOT NULL
    GROUP BY r.request_group_id, r.date_needed, r.scheduled_time, r.purpose, r.status
");
if ($mnt_res) {
    while ($r = $mnt_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['date_needed'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Maintenance Pickup',
            'badge' => 'bg-warning text-dark',
            'id' => $r['request_group_id'],
            'items' => $r['items_summary'],
            'status' => $r['status']
        ];
    }
}

// 3. Document printing schedules
$prt_res = $conn->query("
    SELECT request_group_id, date_needed, scheduled_time, paper_size, print_color, total_price, status
    FROM document_printing_requests
    WHERE user_id = {$user_id} AND date_needed IS NOT NULL
");
if ($prt_res) {
    while ($r = $prt_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['date_needed'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Document Printing Pickup',
            'badge' => 'bg-info text-white',
            'id' => $r['request_group_id'],
            'items' => 'Doc Print (' . $r['paper_size'] . ', ' . $r['print_color'] . ') - ₱' . number_format($r['total_price'], 2),
            'status' => $r['status']
        ];
    }
}

// 4. Borrow requests schedules (borrow date & expected return date)
$brw_res = $conn->query("
    SELECT r.request_group_id, r.borrow_date, r.expected_return_date, r.scheduled_time, r.quantity, r.status,
           IFNULL(i.item_name, r.item_name) as item_title
    FROM borrow_requests r
    LEFT JOIN items i ON r.item_id = i.id AND r.item_id > 0
    WHERE r.user_id = {$user_id}
");
if ($brw_res) {
    while ($r = $brw_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['borrow_date'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Borrow Start',
            'badge' => 'bg-secondary',
            'id' => $r['request_group_id'],
            'items' => 'Borrow: ' . ($r['item_title'] ?? 'Equipment') . ' (x' . $r['quantity'] . ')',
            'status' => $r['status']
        ];
        $user_schedules[] = [
            'date' => $r['expected_return_date'],
            'time' => 'Before End of Day',
            'type' => 'Borrow Return Deadline',
            'badge' => 'bg-danger',
            'id' => $r['request_group_id'],
            'items' => 'RETURN Item: ' . ($r['item_title'] ?? 'Equipment') . ' (x' . $r['quantity'] . ')',
            'status' => $r['status']
        ];
    }
}

// Sort user schedules by date ASC
usort($user_schedules, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule & Calendar - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/request_history.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-history sticky-top shadow-sm" style="background-color: #1b4f9c;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="user_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white me-2" style="width: 38px;">
            <div class="lh-1">
                <span class="fs-5 d-block">SIBTECH SCHEDULE & CALENDAR</span>
                <small class="fw-light text-white-50" style="font-size: 0.72rem;">User Timeline & Deadlines</small>
            </div>
        </a>
        <div class="d-flex align-items-center">
            <a href="user_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                <i class="bi bi-grid-fill me-1"></i> Supply Store
            </a>
            <a href="borrow_items.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                <i class="bi bi-hand-holding-box me-1"></i> Borrow Items
            </a>
            <a href="request_history.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                <i class="bi bi-bag-check-fill me-1"></i> My Requests
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 960px;">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-calendar3 text-primary me-2"></i>Aking Iskedyul at Kalendaryo (My Schedule & Calendar)</h4>
            <p class="text-muted small mb-0">Subaybayan ang lahat ng iyong mga nakaiskedyul na pickup, printing requests, at deadlines ng mga hiniram na gamit.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-light p-3 border-bottom">
            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center"><i class="bi bi-clock-history me-2 text-primary"></i>Upcoming Scheduled Events</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Scheduled Date</th>
                            <th>Time Slot</th>
                            <th>Event / Request Type</th>
                            <th>Order ID</th>
                            <th>Details / Items</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($user_schedules)): ?>
                            <?php foreach ($user_schedules as $sched): ?>
                                <tr>
                                    <td class="fw-bold text-primary text-nowrap"><i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars($sched['date']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($sched['time']) ?></span></td>
                                    <td><span class="badge <?= $sched['badge'] ?> fw-bold"><?= htmlspecialchars($sched['type']) ?></span></td>
                                    <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($sched['id']) ?></td>
                                    <td class="small text-dark fw-semibold"><?= htmlspecialchars($sched['items']) ?></td>
                                    <td>
                                        <?php
                                        $st = $sched['status'];
                                        $stClass = ($st == 'Approved' || $st == 'Returned' || $st == 'Completed') ? 'bg-success text-white' : (($st == 'Rejected') ? 'bg-danger text-white' : 'bg-warning text-dark');
                                        ?>
                                        <span class="badge rounded-pill px-3 py-1 <?= $stClass ?>"><?= $st ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x fs-2 d-block mb-1 text-secondary"></i>
                                    Walang nakaiskedyul na mga gawain sa kasalukuyan.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>