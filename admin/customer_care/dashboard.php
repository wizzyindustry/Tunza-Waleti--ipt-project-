<?php
// admin/customer_care/dashboard.php

// 1. Initialize role-isolated session context to avoid collisions
session_name('TUNZA_CARE_OFFICER_SESSION');
session_start();

require_once '../../database/auth.php';
require_once '../../database/config.php';

// Normalize database connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Strict Role Authorization Guard
if (!isset($_SESSION['care_id']) || ($_SESSION['care_role'] ?? '') !== 'customer_care') {
    header("Location: ../../login.php?role=customer_care");
    exit();
}

$officer_id   = $_SESSION['care_id'];
$officer_name = $_SESSION['care_name'] ?? 'Customer Care Officer';

// 3. Fetch Branding Settings
$site_logo = 'assets/images/panta logo-07.jpg';
try {
    $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
    if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['setting_value'])) { 
            $site_logo = $row['setting_value']; 
        }
    }
} catch (PDOException $e) {
    // Fallback silently if settings table read fails
}

// 4. Metrics & Stats Calculation
$total_open      = 0;
$total_escalated = 0;
$total_resolved  = 0;
$total_users     = 0;
$recentTickets   = [];

try {
    $total_open      = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
    $total_escalated = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'escalated_to_finance'")->fetchColumn();
    $total_resolved  = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('resolved', 'closed')")->fetchColumn();
    $total_users     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    
    // Recent 5 Customer Inquiries
    $stmtRecent = $pdo->prepare("
        SELECT st.*, u.full_name, u.phone_number 
        FROM support_tickets st 
        JOIN users u ON st.user_id = u.id 
        ORDER BY st.created_at DESC 
        LIMIT 5
    ");
    $stmtRecent->execute();
    $recentTickets = $stmtRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $recentTickets = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Care Dashboard | Tunza Waleti</title>
    <link rel="shortcut icon" href="../../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { height: 100vh; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }
        
        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }
        
        /* Sidebar Styling */
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

        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; gap: 15px; flex-wrap: wrap; }
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
        
        /* Grid Metrics */
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-left: 5px solid #510049; }
        .stat-card h4 { font-size: 13px; color: #666; text-transform: uppercase; margin-bottom: 8px; }
        .stat-card .value { font-size: 26px; font-weight: 700; color: #2c3e50; }

        /* Responsive Table Section */
        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; min-width: 700px; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; vertical-align: middle; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge.open { background: #fff3cd; color: #856404; }
        .badge.escalated { background: #cce5ff; color: #004085; }
        .badge.resolved { background: #d4edda; color: #155724; }

        .btn-action { padding: 6px 14px; border-radius: 6px; background: #510049; color: #fff; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: 0.3s; border: none; cursor: pointer; white-space: nowrap; }
        .btn-action:hover { background: #000; }

        /* Mobile Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar { position: fixed; top: 0; left: -260px; }
            .sidebar.active { left: 0; }
            .main-content { padding: 20px 15px; width: 100vw; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="admin-container">
    <div class="sidebar" id="careSidebar">
        <div class="sidebar-brand">
            <img src="../../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Logo" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
            <h2 style="font-size: 18px; margin-top: 8px;">Customer Care</h2>
        </div>
        <ul class="sidebar-menu">
            <li class="active"><a href="dashboard.php"><i class="fas fa-chart-line"></i> Care Dashboard</a></li>
            <li><a href="tickets.php"><i class="fas fa-headset"></i> Support Tickets</a></li>
            <li><a href="users_view.php"><i class="fas fa-users"></i> Inspect Customers</a></li>
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
                    <h2>Welcome back, <?= htmlspecialchars($officer_name) ?></h2>
                    <p style="font-size: 13px; color: #666;">Customer support operational metrics & incoming inquiries</p>
                </div>
            </div>
        </div>

        <div class="metrics-grid">
            <div class="stat-card" style="border-color: #f39c12;">
                <h4>Open Tickets</h4>
                <div class="value"><?= $total_open ?></div>
            </div>
            <div class="stat-card" style="border-color: #2980b9;">
                <h4>Escalated to Finance</h4>
                <div class="value"><?= $total_escalated ?></div>
            </div>
            <div class="stat-card" style="border-color: #27ae60;">
                <h4>Resolved Tickets</h4>
                <div class="value"><?= $total_resolved ?></div>
            </div>
            <div class="stat-card" style="border-color: #8e44ad;">
                <h4>Active Customers</h4>
                <div class="value"><?= $total_users ?></div>
            </div>
        </div>

        <div class="panel-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
                <h3>Recent Customer Inquiries</h3>
                <a href="tickets.php" class="btn-action"><i class="fas fa-list"></i> View All Tickets</a>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Customer</th>
                            <th>Category</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentTickets)): ?>
                            <?php foreach ($recentTickets as $tck): ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($tck['ticket_no']) ?></strong></td>
                                    <td><?= htmlspecialchars($tck['full_name']) ?><br><small style="color: #777;"><?= htmlspecialchars($tck['phone_number']) ?></small></td>
                                    <td><span style="text-transform: capitalize; font-weight: 500;"><?= htmlspecialchars($tck['category']) ?></span></td>
                                    <td><?= htmlspecialchars($tck['subject']) ?></td>
                                    <td>
                                        <?php 
                                            $st = strtolower($tck['status']);
                                            $cls = ($st === 'open') ? 'open' : (($st === 'escalated_to_finance') ? 'escalated' : 'resolved');
                                        ?>
                                        <span class="badge <?= $cls ?>"><?= str_replace('_', ' ', $st) ?></span>
                                    </td>
                                    <td><?= date('d M Y, H:i', strtotime($tck['created_at'])) ?></td>
                                    <td>
                                        <a href="tickets.php?search=<?= urlencode($tck['ticket_no']) ?>" class="btn-action"><i class="fas fa-reply"></i> Handle</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; color: #777; padding: 20px;">No support tickets recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('careSidebar').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    // Cross-Tab Session Guard
    window.addEventListener('storage', function(event) {
        if (event.key === 'tunza_session_update') {
            const sessionData = JSON.parse(event.newValue);
            if (sessionData && sessionData.role !== 'customer_care') {
                window.location.reload();
            }
        }
    });
</script>

</body>
</html>