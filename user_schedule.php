<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Month and Year navigation
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = date('t', $first_day_timestamp);
// 1 (for Monday) through 7 (for Sunday)
$first_day_of_week = date('N', $first_day_timestamp);

$month_name = date('F', $first_day_timestamp);

// Previous and Next Month Links
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

// Fetch Admin Calendar Schedules for this month
$start_date_str = sprintf('%04d-%02d-01', $year, $month);
$end_date_str = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$admin_schedules = [];
$cal_stmt = $conn->prepare("SELECT * FROM calendar_schedules WHERE event_date BETWEEN ? AND ? ORDER BY id ASC");
if ($cal_stmt) {
    $cal_stmt->bind_param("ss", $start_date_str, $end_date_str);
    $cal_stmt->execute();
    $res = $cal_stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $day_num = intval(date('j', strtotime($row['event_date'])));
        $admin_schedules[$day_num][] = $row;
    }
}

// Fetch user's own requests/orders schedules for list view and calendar display
$user_schedules = [];

// 1. Document printing schedules (all users)
$prt_res = $conn->query("
    SELECT request_group_id, requisitioner_name, department, date_needed, scheduled_time, paper_size, print_color, total_price, status
    FROM document_printing_requests
    WHERE date_needed IS NOT NULL
");
if ($prt_res) {
    while ($r = $prt_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['date_needed'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Document Printing Pickup',
            'badge' => 'bg-info text-white',
            'id' => $r['request_group_id'],
            'requisitioner' => $r['requisitioner_name'] . ' (' . $r['department'] . ')',
            'items' => 'Doc Print (' . $r['paper_size'] . ', ' . $r['print_color'] . ') - ₱' . number_format($r['total_price'], 2),
            'status' => $r['status']
        ];
    }
}

// 4. Borrow requests schedules (all users)
$brw_res = $conn->query("
    SELECT r.request_group_id, r.requisitioner_name, r.department, r.borrow_date, r.expected_return_date, r.scheduled_time, r.quantity, r.status,
           IFNULL(i.item_name, r.item_name) as item_title
    FROM borrow_requests r
    LEFT JOIN items i ON r.item_id = i.id AND r.item_id > 0
");
if ($brw_res) {
    while ($r = $brw_res->fetch_assoc()) {
        $user_schedules[] = [
            'date' => $r['borrow_date'],
            'time' => $r['scheduled_time'] ?? '09:00 AM - 10:00 AM',
            'type' => 'Borrow Start',
            'badge' => 'bg-secondary',
            'id' => $r['request_group_id'],
            'requisitioner' => $r['requisitioner_name'] . ' (' . $r['department'] . ')',
            'items' => 'Borrow: ' . ($r['item_title'] ?? 'Equipment') . ' (x' . $r['quantity'] . ')',
            'status' => $r['status']
        ];
        $user_schedules[] = [
            'date' => $r['expected_return_date'],
            'time' => 'Before End of Day',
            'type' => 'Borrow Return Deadline',
            'badge' => 'bg-danger',
            'id' => $r['request_group_id'],
            'requisitioner' => $r['requisitioner_name'] . ' (' . $r['department'] . ')',
            'items' => 'RETURN Item: ' . ($r['item_title'] ?? 'Equipment') . ' (x' . $r['quantity'] . ')',
            'status' => $r['status']
        ];
    }
}

// Organize user personal schedules by day number for current month
$user_month_schedules = [];
foreach ($user_schedules as $us) {
    $us_time = strtotime($us['date']);
    if (date('Y', $us_time) == $year && date('m', $us_time) == $month) {
        $day_num = intval(date('j', $us_time));
        $user_month_schedules[$day_num][] = $us;
    }
}

// Sort user schedules for list view
usort($user_schedules, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calendar - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Permanent+Marker&family=Caveat:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/request_history.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <style>
        /* Whiteboard Calendar Styling */
        .whiteboard-frame {
            background: #d8dee8;
            border: 12px solid #a3b1c6;
            border-radius: 12px;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.2), 0 10px 25px rgba(0,0,0,0.25);
            padding: 15px;
            position: relative;
        }

        .whiteboard-surface {
            background-color: #fdfcf7;
            background-image:
                radial-gradient(#e2decb 0.5px, transparent 0.5px),
                linear-gradient(to bottom, rgba(255,255,255,0.8), rgba(240,235,220,0.6));
            background-size: 10px 10px, 100% 100%;
            border: 3px solid #4a5568;
            border-radius: 4px;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.05);
            padding: 10px;
            font-family: 'Arial', sans-serif;
        }

        .whiteboard-header-title {
            font-family: 'Permanent Marker', cursive;
            color: #b91c1c;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 1.8rem;
            text-shadow: 1px 1px 0px rgba(0,0,0,0.1);
        }

        .whiteboard-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 2px solid #333;
            background-color: #333;
            gap: 2px;
        }

        .whiteboard-day-header {
            background: #eae5d9;
            color: #111;
            font-family: 'Permanent Marker', cursive;
            font-size: 1.1rem;
            text-align: center;
            padding: 8px 2px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .whiteboard-cell {
            background: #fcfbfa;
            min-height: 110px;
            padding: 6px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            overflow: hidden;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.02);
        }

        .whiteboard-cell.empty {
            background: #f3efe6;
            opacity: 0.6;
        }

        .whiteboard-date-num {
            font-family: 'Permanent Marker', cursive;
            color: #dc2626; /* Marker red */
            font-size: 1.35rem;
            line-height: 1;
            margin-bottom: 4px;
        }

        .marker-text-red {
            font-family: 'Permanent Marker', 'Caveat', cursive;
            color: #c51d1d;
            font-size: 1rem;
            line-height: 1.15;
            word-break: break-word;
            text-shadow: 0.5px 0.5px 0px rgba(197, 29, 29, 0.2);
        }

        .marker-text-blue {
            font-family: 'Permanent Marker', 'Caveat', cursive;
            color: #1d4ed8;
            font-size: 0.95rem;
            line-height: 1.15;
            word-break: break-word;
        }

        .marker-text-dark {
            font-family: 'Permanent Marker', 'Caveat', cursive;
            color: #111827;
            font-size: 0.95rem;
            line-height: 1.15;
        }

        .whiteboard-cell .schedule-item {
            margin-bottom: 4px;
            padding: 2px 4px;
            border-radius: 3px;
        }

        .whiteboard-cell .slash-mark {
            font-family: 'Permanent Marker', cursive;
            color: #c51d1d;
            font-size: 1.6rem;
            text-align: center;
            line-height: 1;
            margin-top: 10px;
            opacity: 0.85;
        }

        @media (max-width: 768px) {
            .whiteboard-cell {
                min-height: 80px;
                padding: 3px;
            }
            .whiteboard-date-num {
                font-size: 1rem;
            }
            .marker-text-red, .marker-text-blue {
                font-size: 0.78rem;
            }
            .whiteboard-day-header {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-history sticky-top shadow-sm" style="background-color: #1b4f9c;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="<?= (strtolower($_SESSION['role'] ?? '') === 'admin') ? 'admin_dashboard.php' : 'user_dashboard.php' ?>">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white me-2" style="width: 38px;">
            <div class="lh-1">
                <span class="fs-5 d-block">SIBTECH SCHEDULE & CALENDAR</span>
                <small class="fw-light text-white-50" style="font-size: 0.72rem;">Timeline & System Schedule</small>
            </div>
        </a>
        <div class="d-flex align-items-center">
            <?php if (strtolower($_SESSION['role'] ?? '') === 'admin'): ?>
                <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-speedometer2 me-1"></i> Admin Dashboard
                </a>
            <?php else: ?>
                <a href="user_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-grid-fill me-1"></i> Supply Store
                </a>
                <a href="borrow_items.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-hand-holding-box me-1"></i> Borrow Items
                </a>
                <a href="request_history.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-bag-check-fill me-1"></i> My Requests
                </a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 1100px;">
    <!-- Navigation Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-calendar3 text-primary me-2"></i>Iskedyul at Kalendaryo (Schedule & Calendar)</h4>
            <p class="text-muted small mb-0">Tingnan ang opisyal na whiteboard schedule mula sa Admin pati na rin ang iyong nakaiskedyul na mga pickup at hiram na gamit.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-chevron-left me-1"></i> Prev
            </a>
            <span class="fw-bold fs-5 px-2 text-primary"><?= $month_name ?> <?= $year ?></span>
            <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                Next <i class="bi bi-chevron-right ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Whiteboard Schedule Calendar Frame -->
    <div class="whiteboard-frame mb-5">
        <div class="whiteboard-surface">
            <!-- Whiteboard Title Banner -->
            <div class="d-flex justify-content-between align-items-center mb-3 px-2">
                <div class="whiteboard-header-title">
                    <i class="bi bi-pen-fill me-2"></i>SCHEDULE BOARD - <?= strtoupper($month_name) ?> <?= $year ?>
                </div>
                <div class="text-end">
                    <span class="badge bg-danger text-white rounded-pill px-3 py-1 me-1"><i class="bi bi-circle-fill me-1 fs-6"></i> Admin Postings</span>
                    <span class="badge bg-primary text-white rounded-pill px-3 py-1"><i class="bi bi-circle-fill me-1 fs-6"></i> My Scheduled Orders</span>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="whiteboard-grid">
                <!-- Day Columns -->
                <div class="whiteboard-day-header">MONDAY</div>
                <div class="whiteboard-day-header">Tuesday</div>
                <div class="whiteboard-day-header">Wednesday</div>
                <div class="whiteboard-day-header">Thursday</div>
                <div class="whiteboard-day-header">Friday</div>
                <div class="whiteboard-day-header">Saturday</div>
                <div class="whiteboard-day-header">Sunday</div>

                <?php
                // Empty cells before first day of month
                for ($i = 1; $i < $first_day_of_week; $i++) {
                    echo '<div class="whiteboard-cell empty"></div>';
                }

                // Days of current month
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $has_admin_content = isset($admin_schedules[$day]) && count($admin_schedules[$day]) > 0;
                    $has_user_content = isset($user_month_schedules[$day]) && count($user_month_schedules[$day]) > 0;
                    ?>
                    <div class="whiteboard-cell">
                        <div class="whiteboard-date-num"><?= $day ?></div>

                        <!-- Admin Whiteboard Entries -->
                        <?php if ($has_admin_content): ?>
                            <?php foreach ($admin_schedules[$day] as $as): ?>
                                <div class="marker-text-red fw-bold mb-1">
                                    <?= htmlspecialchars($as['department'] ? $as['department'] . ' ' : '') ?>
                                    <?= htmlspecialchars($as['title']) ?>
                                    <?php if (!empty($as['scheduled_time'])): ?>
                                        <div style="font-size: 0.85rem;" class="fw-normal"><?= htmlspecialchars($as['scheduled_time']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- User Personal Order Schedules -->
                        <?php if ($has_user_content): ?>
                            <?php foreach ($user_month_schedules[$day] as $us): ?>
                                <div class="marker-text-blue fw-bold bg-white p-1 rounded border border-primary-subtle shadow-sm mb-1" title="<?= htmlspecialchars($us['requisitioner'] ?? '') ?>">
                                    <i class="bi bi-clock me-1"></i><?= htmlspecialchars($us['type']) ?>
                                    <div style="font-size: 0.78rem;" class="text-dark fw-normal"><?= htmlspecialchars($us['time']) ?> <?= !empty($us['requisitioner']) ? ' - ' . htmlspecialchars($us['requisitioner']) : '' ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Red slashes on empty weekend days like in photo -->
                        <?php
                        $current_col = ($first_day_of_week + $day - 2) % 7 + 1; // 1=Mon, 7=Sun
                        if (!$has_admin_content && !$has_user_content && ($current_col == 7)) {
                            echo '<div class="slash-mark">///</div>';
                        }
                        ?>
                    </div>
                <?php } ?>

                <?php
                // Fill trailing empty cells to complete the grid week
                $total_cells = ($first_day_of_week - 1) + $days_in_month;
                $remaining_cells = (7 - ($total_cells % 7)) % 7;
                for ($i = 0; $i < $remaining_cells; $i++) {
                    echo '<div class="whiteboard-cell empty"></div>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- All Scheduled Events Table Breakdown -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center"><i class="bi bi-clock-history me-2 text-primary"></i>Detailed List of All Scheduled Pickups & Deadlines</h6>
            <span class="badge bg-secondary"><?= count($user_schedules) ?> Total Items</span>
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
                            <th>Requisitioner & Dept</th>
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
                                    <td><span class="badge bg-light text-dark border fw-semibold"><?= htmlspecialchars($sched['requisitioner'] ?? 'N/A') ?></span></td>
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
                                <td colspan="7" class="text-center text-muted py-4">
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