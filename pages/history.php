<?php
// pages/history.php

// 1. Set isolated session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_USER_SESSION');
session_start();

require_once '../database/auth.php'; // Ensures session exists & user is logged in
require_once '../database/config.php';

// Normalize PDO handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Guarantee active user authentication & role routing
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION['user_role'] ?? 'user') === 'admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// 2. Fetch system logo dynamically safely
$site_logo = 'assets/images/panta logo-07.jpg'; // Default fallback
if (isset($pdo) && $pdo !== null) {
    try {
        $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
        if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['setting_value'])) {
                $site_logo = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {
        // Fallback silently if table does not exist
    }
}

// 3. Filter & Action Parameters
$type_filter   = strtolower(trim($_GET['type'] ?? 'all'));
$search_query  = trim($_GET['search'] ?? '');
$time_range    = trim($_GET['range'] ?? 'all');
$action_export = strtolower(trim($_GET['export'] ?? ''));

// ------------------------------------------------------------------
// 4. CONSTRUCT DYNAMIC QUERY FOR FILTERS
// ------------------------------------------------------------------
$whereClauses = ["user_id = :user_id"];
$queryParams  = ['user_id' => $user_id];

// Filter by Type (deposit / withdraw)
if (!empty($type_filter) && $type_filter !== 'all') {
    if ($type_filter === 'deposit') {
        $whereClauses[] = "LOWER(type) = 'deposit'";
    } elseif ($type_filter === 'withdraw' || $type_filter === 'withdrawal') {
        $whereClauses[] = "LOWER(type) IN ('withdraw', 'withdrawal', 'payout')";
    }
}

// Filter by Search Query
if (!empty($search_query)) {
    $whereClauses[] = "(reference_no LIKE :search OR payment_method LIKE :search OR description LIKE :search)";
    $queryParams['search'] = '%' . $search_query . '%';
}

// Filter by Time Range
if ($time_range === 'last-7') {
    $whereClauses[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($time_range === 'this-month') {
    $whereClauses[] = "MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())";
} elseif ($time_range === 'last-30') {
    $whereClauses[] = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$whereSQL = implode(" AND ", $whereClauses);

// ------------------------------------------------------------------
// 5. ACTION HANDLER: EXPORT TRANSACTION STATEMENT (CSV)
// ------------------------------------------------------------------
if ($action_export === 'csv') {
    try {
        $exportSql = "SELECT reference_no, type, amount, status, payment_method, description, created_at 
                      FROM transactions 
                      WHERE $whereSQL 
                      ORDER BY created_at DESC";
        $exportStmt = $pdo->prepare($exportSql);
        $exportStmt->execute($queryParams);
        $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

        $fileName = 'statement_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($user_name)) . '_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        $output = fopen('php://output', 'w');

        // Document Header
        fputcsv($output, ['TUNZA WALETI - ACCOUNT TRANSACTION STATEMENT']);
        fputcsv($output, ['Account Holder', $user_name . ' (User ID #' . $user_id . ')']);
        fputcsv($output, ['Generated Date', date('Y-m-d H:i:s')]);
        fputcsv($output, ['Applied Filters', 'Type: ' . strtoupper($type_filter) . ' | Range: ' . strtoupper($time_range)]);
        fputcsv($output, []); // Blank line

        // CSV Table Columns
        fputcsv($output, ['Reference No', 'Description / Title', 'Type', 'Amount (TZS)', 'Payment Method', 'Status', 'Date & Time']);

        foreach ($exportData as $row) {
            $rawType    = strtolower($row['type']);
            $isWithdraw = in_array($rawType, ['withdraw', 'withdrawal', 'payout']);
            $formattedAmount = ($isWithdraw ? '-' : '+') . number_format($row['amount'], 2, '.', '');

            fputcsv($output, [
                $row['reference_no'],
                $row['description'] ?: ucfirst($row['type']),
                strtoupper($row['type']),
                $formattedAmount,
                $row['payment_method'],
                strtoupper($row['status']),
                date('Y-m-d H:i:s', strtotime($row['created_at']))
            ]);
        }

        fclose($output);
        exit();

    } catch (PDOException $e) {
        die("Export Statement Error: " . $e->getMessage());
    }
}

// ------------------------------------------------------------------
// 6. FETCH METRICS & PAGINATED RECORDS
// ------------------------------------------------------------------
$page  = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 10;
$offset = ($page - 1) * $limit;

try {
    // Live Metric Summaries for Current User
    $metricsStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN LOWER(type) = 'deposit' AND LOWER(status) IN ('completed', 'success', 'successful') THEN amount ELSE 0 END) AS total_deposits,
            SUM(CASE WHEN LOWER(type) IN ('withdraw', 'withdrawal', 'payout') AND LOWER(status) IN ('completed', 'success', 'successful') THEN amount ELSE 0 END) AS total_withdrawals,
            COUNT(id) AS total_count
        FROM transactions 
        WHERE user_id = :user_id
    ");
    $metricsStmt->execute(['user_id' => $user_id]);
    $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);

    $total_deposits    = (float)($metrics['total_deposits'] ?? 0);
    $total_withdrawals = (float)($metrics['total_withdrawals'] ?? 0);

    // Fetch Count for Pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE $whereSQL");
    $countStmt->execute($queryParams);
    $filtered_count = (int)$countStmt->fetchColumn();
    $total_pages    = max(1, ceil($filtered_count / $limit));

    // Fetch Transaction Records
    $sql = "SELECT id, reference_no, type, amount, status, payment_method, description, created_at 
            FROM transactions 
            WHERE $whereSQL 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($queryParams as $key => $val) {
        $stmt->bindValue(":$key", $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error fetching transaction history: " . $e->getMessage());
}

// 7. Fetch user's profile picture for header avatar
try {
    $headerUserStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = :user_id LIMIT 1");
    $headerUserStmt->execute(['user_id' => $user_id]);
    $headerUser = $headerUserStmt->fetch(PDO::FETCH_ASSOC);

    $pic_filename = !empty($headerUser['profile_pic']) ? $headerUser['profile_pic'] : 'default.png';

    if (file_exists('../assets/uploads/' . $pic_filename) && $pic_filename !== 'default.png') {
        $header_avatar = '../assets/uploads/' . $pic_filename;
    } else {
        $header_avatar = '../' . $site_logo;
    }
} catch (PDOException $e) {
    $header_avatar = '../' . $site_logo;
}

// Build URL query string for Export link while retaining active filters
$exportQueryParams = $_GET;
$exportQueryParams['export'] = 'csv';
$exportUrl = 'history.php?' . http_build_query($exportQueryParams);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History | Tunza Waleti</title>
    <link rel="stylesheet" href="../assets/style/main.css">
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style/media.css">
    <script src="../assets/js/main.js" defer></script>
    <style>
        .tx-type-icon.withdraw-icon {
            background-color: #fdeaea;
            color: #e74c3c;
        }
        .tx-type-icon.deposit-icon {
            background-color: #e8f8f0;
            color: #27ae60;
        }
        .tx-amount.negative {
            color: #e74c3c;
            font-weight: 700;
        }
        .tx-amount.positive {
            color: #27ae60;
            font-weight: 700;
        }
        .withdraw-tag {
            background-color: #fdeaea;
            color: #c0392b;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .deposit-tag {
            background-color: #e8f8f0;
            color: #27ae60;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .filter-tabs a{
            text-decoration: none;
        }
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #510049;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-export:hover {
            background-color: #000000;
            color: #ffffff;
        }
    </style>
</head>

<body>
    <!-- ===== HEADER ===== -->
    <header class="header">
        <div class="user-profile">
            <img src="<?= htmlspecialchars($header_avatar) ?>" alt="user-image" style="object-fit: cover; border-radius: 50%;">
            <div class="user-info">
                <h3><?= htmlspecialchars($user_name) ?></h3>
                <p><span class="status-dot"></span> Welcome Back</p>
            </div>
        </div>
        <div class="navigation">
            <nav>
                <ul>
                    <li><a href="dashboard.php">Home</a></li>
                    <li><a href="deposit.php">Deposit</a></li>
                    <li><a href="withdraw.php">Withdraw</a></li>
                    <li><a href="history.php" class="active">History</a></li>
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
            <i class="fas fa-list" title="Toggle Sidebar"></i>
        </div>
        <div class="side-bar">
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Home</span></a></li>
                <li><a href="deposit.php"><i class="fas fa-donate"></i> <span>Deposit</span></a></li>
                <li><a href="withdraw.php"><i class="fas fa-arrow-circle-down"></i> <span>Withdraw</span></a></li>
                <li class="active"><a href="history.php"><i class="fas fa-history"></i> <span>History</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </header>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="history-main-container">
        <!-- Page Header -->
        <div class="history-page-header">
            <div class="page-title">
                <h2><i class="fas fa-clock-rotate-left"></i> Transaction History</h2>
                <p>View, filter, and track all your account activity and mobile money transactions.</p>
            </div>
            <div class="header-actions">
                <!-- Export CSV Statement Button -->
                <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-export">
                    <i class="fas fa-file-csv"></i> Export Statement
                </a>
            </div>
        </div>

        <!-- Metric Summary Cards -->
        <div class="history-metrics-grid">
            <div class="metric-card deposit-metric">
                <div class="metric-icon"><i class="fas fa-donate"></i></div>
                <div class="metric-info">
                    <span class="metric-label">Total Deposits</span>
                    <span class="metric-value">+ TZS <?= number_format($total_deposits, 2) ?></span>
                </div>
            </div>

            <div class="metric-card withdraw-metric">
                <div class="metric-icon"><i class="fas fa-arrow-circle-down"></i></div>
                <div class="metric-info">
                    <span class="metric-label">Total Withdrawals</span>
                    <span class="metric-value">- TZS <?= number_format($total_withdrawals, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="history-toolbar-card">
            <form method="GET" action="history.php" id="historyFilterForm">
                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <a href="history.php?type=all&search=<?= urlencode($search_query) ?>&range=<?= urlencode($time_range) ?>" 
                       class="tab-btn <?= ($type_filter === 'all' || empty($type_filter)) ? 'active' : '' ?>">All</a>
                    <a href="history.php?type=deposit&search=<?= urlencode($search_query) ?>&range=<?= urlencode($time_range) ?>" 
                       class="tab-btn <?= $type_filter === 'deposit' ? 'active' : '' ?>">Deposits</a>
                    <a href="history.php?type=withdraw&search=<?= urlencode($search_query) ?>&range=<?= urlencode($time_range) ?>" 
                       class="tab-btn <?= in_array($type_filter, ['withdraw', 'withdrawal', 'payout']) ? 'active' : '' ?>">Withdrawals</a>
                </div> <br>

                <!-- Search & Range Controls -->
                <div class="toolbar-controls">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">

                    <div class="history-search-input">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search by ref, method, or description..." onchange="this.form.submit()">
                    </div>

                    <div class="select-range-wrapper">
                        <select name="range" class="time-range-select" onchange="this.form.submit()">
                            <option value="all" <?= $time_range === 'all' ? 'selected' : '' ?>>All Time</option>
                            <option value="this-month" <?= $time_range === 'this-month' ? 'selected' : '' ?>>This Month</option>
                            <option value="last-30" <?= $time_range === 'last-30' ? 'selected' : '' ?>>Last 30 Days</option>
                            <option value="last-7" <?= $time_range === 'last-7' ? 'selected' : '' ?>>Last 7 Days</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <!-- Transaction List Section -->
        <div class="history-list-card">
            <div class="list-header">
                <h3>Transactions Log</h3>
                <span class="count-badge"><?= $filtered_count ?> Items</span>
            </div>

            <div class="transactions-container">
                <?php if (empty($transactions)): ?>
                    <div class="no-results-state">
                        <i class="fas fa-folder-open fa-2x"></i>
                        <h4>No transactions found</h4>
                        <p>Try clearing your search query or switching filter tabs.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): 
                        $rawType    = strtolower($txn['type']);
                        $isWithdraw = in_array($rawType, ['withdraw', 'withdrawal', 'payout']);
                    ?>
                        <div class="transaction-item">
                            <div class="tx-type-icon <?= $isWithdraw ? 'withdraw-icon' : 'deposit-icon' ?>">
                                <i class="fas <?= $isWithdraw ? 'fa-arrow-circle-up' : 'fa-arrow-circle-down' ?>"></i>
                            </div>

                            <div class="tx-details">
                                <div class="tx-main-info">
                                    <h4 class="tx-title"><?= htmlspecialchars($txn['description'] ?: ucfirst($txn['type'])) ?></h4>
                                    <span class="tx-badge <?= $isWithdraw ? 'withdraw-tag' : 'deposit-tag' ?>">
                                        <?= ucfirst(htmlspecialchars($txn['type'])) ?>
                                    </span>
                                </div>
                                <div class="tx-meta-info">
                                    <span class="tx-target"><i class="fas fa-credit-card"></i> <?= htmlspecialchars($txn['payment_method']) ?></span>
                                    <span class="tx-dot">•</span>
                                    <span class="tx-date"><?= date('d M Y, H:i', strtotime($txn['created_at'])) ?></span>
                                    <span class="tx-dot">•</span>
                                    <span class="tx-ref">Ref: <?= htmlspecialchars($txn['reference_no']) ?></span>
                                </div>
                            </div>

                            <div class="tx-amount-status">
                                <span class="tx-amount <?= $isWithdraw ? 'negative' : 'positive' ?>">
                                    <?= $isWithdraw ? '-' : '+' ?> TZS <?= number_format($txn['amount'], 2) ?>
                                </span>
                                <span class="status-pill status-completed">
                                    <i class="fas fa-circle-check"></i> <?= ucfirst(htmlspecialchars($txn['status'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination Footer -->
            <?php if ($total_pages > 1): ?>
                <div class="history-pagination">
                    <?php if ($page > 1): ?>
                        <a href="history.php?page=<?= $page - 1 ?>&type=<?= $type_filter ?>&search=<?= urlencode($search_query) ?>&range=<?= $time_range ?>" class="page-btn">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <button type="button" class="page-btn disabled" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                    <?php endif; ?>

                    <div class="page-numbers">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="history.php?page=<?= $i ?>&type=<?= $type_filter ?>&search=<?= urlencode($search_query) ?>&range=<?= $time_range ?>" 
                               class="page-num <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="history.php?page=<?= $page + 1 ?>&type=<?= $type_filter ?>&search=<?= urlencode($search_query) ?>&range=<?= $time_range ?>" class="page-btn">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <button type="button" class="page-btn disabled" disabled>Next <i class="fas fa-chevron-right"></i></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Client-side Cross-Tab Sync Listener -->
    <script>
        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = '../admin/dashboard.php';
                }
            }
        });
    </script>
</body>

</html>