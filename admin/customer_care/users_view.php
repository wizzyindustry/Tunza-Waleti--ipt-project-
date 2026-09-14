<?php
// admin/customer_care/users_view.php

session_name('TUNZA_CARE_OFFICER_SESSION');
session_start();

require_once '../../database/auth.php';
require_once '../../database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Check Customer Care Authorization Guard
if (!isset($_SESSION['care_id']) || ($_SESSION['care_role'] ?? '') !== 'customer_care') {
    header("Location: ../../login.php?role=customer_care");
    exit();
}

$officer_id   = $_SESSION['care_id'];
$officer_name = $_SESSION['care_name'] ?? 'Customer Care Officer';

$error_msg   = $_SESSION['care_error'] ?? '';
$success_msg = $_SESSION['care_success'] ?? '';
unset($_SESSION['care_error'], $_SESSION['care_success']);

// ------------------------------------------------------------------
// ACCOUNT STATUS TOGGLE (Active / Suspended)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $target_id  = filter_var($_POST['target_user_id'] ?? 0, FILTER_VALIDATE_INT);
    $new_status = trim($_POST['new_status'] ?? 'active');

    if ($target_id && in_array($new_status, ['active', 'suspended'])) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'user'");
            $stmt->execute(['status' => $new_status, 'id' => $target_id]);

            $_SESSION['care_success'] = "Customer account status updated to " . strtoupper($new_status) . ".";
        } catch (PDOException $e) {
            $_SESSION['care_error'] = "Action failed: " . $e->getMessage();
        }
    }
    header("Location: users_view.php");
    exit();
}

// ------------------------------------------------------------------
// FETCH CUSTOMERS WITH UNIQUE PDO PARAMETERS (FIXED SEARCH FILTER)
// ------------------------------------------------------------------
$searchQuery  = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "
    SELECT u.id, u.full_name, u.phone_number, u.status, u.created_at,
           COALESCE(w.balance, 0.00) AS wallet_balance,
           (SELECT COUNT(*) FROM savings_goals sg WHERE sg.user_id = u.id) AS total_goals,
           (SELECT COUNT(*) FROM support_tickets st WHERE st.user_id = u.id) AS total_tickets
    FROM users u
    LEFT JOIN wallets w ON u.id = w.user_id
    WHERE u.role = 'user'
";

$params = [];

if (!empty($searchQuery)) {
    // Unique parameter placeholders to prevent PDO HY093 exception
    $sql .= " AND (u.full_name LIKE :search1 OR u.phone_number LIKE :search2 OR CAST(u.id AS CHAR) = :search3)";
    $params['search1'] = "%{$searchQuery}%";
    $params['search2'] = "%{$searchQuery}%";
    $params['search3'] = $searchQuery;
}

if (!empty($statusFilter) && in_array($statusFilter, ['active', 'suspended'])) {
    $sql .= " AND u.status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$userList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch System Logo
$site_logo = 'assets/images/panta logo-07.jpg';
try {
    $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
    if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['setting_value'])) { $site_logo = $row['setting_value']; }
    }
} catch (PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Inspector | Customer Care</title>
    <link rel="shortcut icon" href="../../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { height: 100vh; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }
        
        /* Sidebar Navigation Styling */
        .sidebar { 
            width: 260px; 
            height: 100vh; 
            background: #3d0037; 
            color: #fff; 
            padding: 25px 0; 
            flex-shrink: 0; 
            transition: all 0.3s ease; 
            z-index: 1000;
        }
        .sidebar-brand { text-align: center; padding-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; margin-top: 20px; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 14px 25px; color: #d8c2d5; text-decoration: none; font-size: 14px; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background: #510049; color: #fff; border-left: 4px solid #27ae60; }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* Main Content Container */
        .main-content { 
            flex-grow: 1; 
            height: 100vh; 
            padding: 30px; 
            overflow-y: auto; 
            width: calc(100vw - 260px); 
        }

        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
        .header-title-container { display: flex; align-items: center; gap: 15px; }

        .menu-toggle { 
            display: none; 
            background: #510049; 
            color: #fff; 
            border: none; 
            font-size: 18px; 
            padding: 10px 14px; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.3s;
        }
        .menu-toggle:hover { background: #000; }

        /* Filter Controls */
        .filter-card { background: #fff; padding: 18px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 22px; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; width: 100%; }
        .filter-form input, .filter-form select { padding: 10px 14px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; }

        /* Responsive Table Container */
        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; min-width: 800px; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; vertical-align: middle; white-space: nowrap; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge.active { background: #d4edda; color: #155724; }
        .badge.suspended { background: #f8d7da; color: #721c24; }

        .btn-action { padding: 6px 14px; border-radius: 6px; background: #510049; color: #fff; border: none; cursor: pointer; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; transition: 0.3s; }
        .btn-action:hover { background: #000; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #d35400; }

        /* Modal Overlays */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 400px; padding: 25px; border-radius: 16px; text-align: center; box-shadow: 0 15px 35px rgba(0,0,0,0.25); }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar { position: fixed; top: 0; left: -260px; }
            .sidebar.active { left: 0; }
            .main-content { padding: 20px 15px; width: 100vw; }
            .filter-form { flex-direction: column; }
            .filter-form input, .filter-form select, .filter-form button { width: 100%; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="admin-container">
    <div class="sidebar" id="careSidebar">
        <div class="sidebar-brand">
            <img src="../../<?= htmlspecialchars($site_logo) ?>" alt="Logo" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
            <h2 style="font-size: 18px; margin-top: 8px;">Customer Care</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Care Dashboard</a></li>
            <li><a href="tickets.php"><i class="fas fa-headset"></i> Support Tickets</a></li>
            <li class="active"><a href="users_view.php"><i class="fas fa-users"></i> Inspect Customers</a></li>
            <li><a href="send_sms.php"><i class="fas fa-comment-sms"></i> Send SMS Notice</a></li>
            <li><a href="../../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header-bar">
            <div class="header-title-container">
                <button class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h2>Inspect Customer Accounts</h2>
                    <p style="font-size: 13px; color: #666;">Look up customer profiles, activity counts, and account status</p>
                </div>
            </div>
        </div>

        <div class="filter-card">
            <form method="GET" action="users_view.php" class="filter-form">
                <input type="text" name="search" placeholder="Search by name, phone, or User ID..." value="<?= htmlspecialchars($searchQuery) ?>" style="flex-grow:1;">
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
                </select>
                <button type="submit" class="btn-action"><i class="fas fa-search"></i> Search</button>
            </form>
        </div>

        <div class="panel-section">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer Name</th>
                            <th>Phone Number</th>
                            <th>Wallet Balance</th>
                            <th>Tickets</th>
                            <th>Status</th>
                            <th>Registered Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($userList)): ?>
                            <?php foreach ($userList as $usr): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($usr['id']) ?></td>
                                    <td><strong><?= htmlspecialchars($usr['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($usr['phone_number']) ?></td>
                                    <td style="color: #27ae60; font-weight: bold;">TZS <?= number_format($usr['wallet_balance'], 2) ?></td>
                                    <td><?= $usr['total_tickets'] ?> Ticket(s)</td>
                                    <td><span class="badge <?= strtolower($usr['status']) ?>"><?= htmlspecialchars($usr['status']) ?></span></td>
                                    <td><?= date('d M Y', strtotime($usr['created_at'])) ?></td>
                                    <td>
                                        <div style="display: flex; gap: 6px;">
                                            <a href="send_sms.php?phone=<?= urlencode($usr['phone_number']) ?>" class="btn-action" style="background:#27ae60;">
                                                <i class="fas fa-sms"></i> SMS
                                            </a>

                                            <form method="POST" action="users_view.php" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="target_user_id" value="<?= $usr['id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= $usr['status'] === 'active' ? 'suspended' : 'active' ?>">
                                                
                                                <?php if ($usr['status'] === 'active'): ?>
                                                    <button type="submit" class="btn-action btn-warning"><i class="fas fa-ban"></i> Suspend</button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn-action" style="background: #27ae60;"><i class="fas fa-check-circle"></i> Activate</button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; color: #777; padding: 20px;">No customer accounts found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Notification Modal -->
<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
        <h3 id="notifTitle">Notification</h3>
        <p id="notifMessage" style="font-size: 14px; color: #555; margin: 15px 0;">Details...</p>
        <button type="button" class="btn-action" style="width: 100%; justify-content: center;" onclick="closeModal('notificationModal')">OK</button>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('careSidebar').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    window.addEventListener('DOMContentLoaded', () => {
        <?php if (!empty($error_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Action Failed';
            document.getElementById('notifMessage').innerText = '<?= htmlspecialchars(addslashes($error_msg)) ?>';
            document.getElementById('notificationModal').classList.add('active');
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Success';
            document.getElementById('notifMessage').innerText = '<?= htmlspecialchars(addslashes($success_msg)) ?>';
            document.getElementById('notificationModal').classList.add('active');
        <?php endif; ?>
    });
</script>

</body>
</html>