<?php
// pages/support.php

session_name('TUNZA_USER_SESSION');
session_start();

require_once '../database/auth.php'; // Runs suspension & login checks
require_once '../database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// Flash Messages
$flash_success = $_SESSION['success_msg'] ?? '';
$flash_error   = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// ------------------------------------------------------------------
// 1. SUBMIT NEW SUPPORT TICKET
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $subject  = trim($_POST['subject'] ?? '');
    $category = trim($_POST['category'] ?? 'general');
    $message  = trim($_POST['message'] ?? '');

    if (empty($subject) || empty($message)) {
        $_SESSION['error_msg'] = "Please provide both a subject and a message detailing your problem.";
    } else {
        try {
            $ticket_no = 'TCK' . date('YmdHis') . rand(10, 99);
            
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets (ticket_no, user_id, subject, category, message, status) 
                VALUES (:ticket_no, :user_id, :subject, :category, :message, 'open')
            ");
            $stmt->execute([
                'ticket_no' => $ticket_no,
                'user_id'   => $user_id,
                'subject'   => $subject,
                'category'  => $category,
                'message'   => $message
            ]);

            $_SESSION['success_msg'] = "Your problem (Ticket #{$ticket_no}) has been sent to Customer Care. We will reply shortly!";
            header("Location: support.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to submit ticket: " . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------
// 2. FETCH USER'S TICKETS & RESPONSES
// ------------------------------------------------------------------
$tickets = [];
try {
    $stmtTickets = $pdo->prepare("
        SELECT ticket_no, subject, category, message, status, user_reply, created_at, updated_at 
        FROM support_tickets 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC
    ");
    $stmtTickets->execute(['user_id' => $user_id]);
    $tickets = $stmtTickets->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Fail silently
}

// Fetch logo dynamically
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
    <title>Customer Help & Support | Tunza Waleti</title>
    <link rel="stylesheet" href="../assets/style/main.css">
    <link rel="stylesheet" href="../assets/style/media.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../<?= htmlspecialchars($site_logo) ?>">
    <style>
        .support-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        .support-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .support-card h3 {
            font-size: 18px;
            color: #3d0037;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1.5px solid #ddd;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
        }
        .btn-submit {
            background: #510049;
            color: #fff;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: 0.3s;
        }
        .btn-submit:hover { background: #000; }
        .ticket-item {
            background: #f8f9fa;
            border: 1px solid #eef0f2;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 15px;
        }
        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .ticket-badge {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-open { background: #fff3cd; color: #856404; }
        .badge-escalated { background: #cce5ff; color: #004085; }
        .badge-resolved { background: #d4edda; color: #155724; }

        .care-reply-box {
            background: #eef9f5;
            border-left: 4px solid #27ae60;
            padding: 12px 15px;
            border-radius: 6px;
            margin-top: 12px;
            font-size: 13px;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="header">
        <div class="user-profile">
            <h3>Support Desk</h3>
        </div>
        <div class="navigation">
            <nav>
                <ul>
                    <li><a href="dashboard.php">Home</a></li>
                    <li><a href="deposit.php">Deposit</a></li>
                    <li><a href="withdraw.php">Withdraw</a></li>
                    <li><a href="support.php" class="active">Help Center</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="dashboard-main" style="padding: 30px;">
        
        <?php if (!empty($flash_success)): ?>
            <div class="dashboard-alert success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>

        <?php if (!empty($flash_error)): ?>
            <div class="dashboard-alert error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <div class="support-grid">
            
            <!-- Send Ticket Form -->
            <div class="support-card">
                <h3><i class="fas fa-headset"></i> Report an Issue to Customer Care</h3>
                <form method="POST" action="support.php">
                    <input type="hidden" name="submit_ticket" value="1">

                    <div class="form-group">
                        <label>Problem Category</label>
                        <select name="category" required>
                            <option value="deposit">Deposit Issue</option>
                            <option value="withdrawal">Withdrawal Issue</option>
                            <option value="account">Account Access / Settings</option>
                            <option value="general">General Question</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Subject / Brief Summary</label>
                        <input type="text" name="subject" placeholder="e.g. Deposit done via M-Pesa not reflected" required>
                    </div>

                    <div class="form-group">
                        <label>Describe Your Issue in Detail</label>
                        <textarea name="message" rows="5" placeholder="Include reference numbers or details to help us assist you quickly..." required></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i> Submit Ticket to Support
                    </button>
                </form>
            </div>

            <!-- View My Support Tickets -->
            <div class="support-card">
                <h3><i class="fas fa-history"></i> My Reported Issues & Instructions</h3>

                <?php if (empty($tickets)): ?>
                    <p style="font-size: 13px; color: #777; text-align: center; margin-top: 30px;">You have not reported any issues yet.</p>
                <?php else: ?>
                    <?php foreach ($tickets as $tck): ?>
                        <?php 
                            $status = strtolower($tck['status']);
                            $badgeClass = 'badge-open';
                            $statusLabel = 'Pending Review';

                            if ($status === 'escalated_to_finance') {
                                $badgeClass = 'badge-escalated';
                                $statusLabel = 'Escalated to Finance';
                            } elseif (in_array($status, ['resolved', 'closed'])) {
                                $badgeClass = 'badge-resolved';
                                $statusLabel = 'Resolved';
                            }
                        ?>
                        <div class="ticket-item">
                            <div class="ticket-header">
                                <strong style="font-size: 14px; color: #3d0037;">#<?= htmlspecialchars($tck['ticket_no']) ?></strong>
                                <span class="ticket-badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                            </div>
                            <h4 style="font-size: 14px; margin-bottom: 6px; color: #2c3e50;"><?= htmlspecialchars($tck['subject']) ?></h4>
                            <p style="font-size: 13px; color: #555;"><?= nl2br(htmlspecialchars($tck['message'])) ?></p>
                            
                            <!-- Customer Care Instructions Reply Box -->
                            <?php if (!empty($tck['user_reply'])): ?>
                                <div class="care-reply-box">
                                    <strong style="color: #27ae60;"><i class="fas fa-user-shield"></i> Customer Care Instructions:</strong>
                                    <p style="margin-top: 5px; color: #2c3e50;"><?= nl2br(htmlspecialchars($tck['user_reply'])) ?></p>
                                </div>
                            <?php endif; ?>

                            <small style="display: block; font-size: 11px; color: #888; margin-top: 8px;">
                                Submitted on: <?= date('d M Y, H:i', strtotime($tck['created_at'])) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

    </main>

</body>
</html>