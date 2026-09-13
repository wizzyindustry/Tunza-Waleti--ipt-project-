<?php
// pages/profile.php

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
$error     = '';
$success   = '';

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

// 3. Handle Profile Image Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['profile_image']['tmp_name'];
        $fileName      = $_FILES['profile_image']['name'];
        $fileSize      = $_FILES['profile_image']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Allowed file types & max size limit (2MB)
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $maxFileSize       = 2 * 1024 * 1024; // 2MB

        if (!in_array($fileExtension, $allowedExtensions)) {
            $error = "Invalid file format. Allowed formats: JPG, JPEG, PNG, WEBP, GIF.";
        } elseif ($fileSize > $maxFileSize) {
            $error = "File size too large. Maximum allowed size is 2MB.";
        } else {
            // Upload directory path
            $uploadDir = '../assets/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename to prevent overwriting
            $newFileName = 'user_' . $user_id . '_' . time() . '.' . $fileExtension;
            $destPath    = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                try {
                    // Update user profile picture path in DB
                    $stmt = $pdo->prepare("UPDATE users SET profile_pic = :pic WHERE id = :user_id");
                    $stmt->execute(['pic' => $newFileName, 'user_id' => $user_id]);

                    $_SESSION['user_pic'] = $newFileName;
                    $success = "Profile image updated successfully!";
                } catch (PDOException $e) {
                    $error = "Database Error: " . $e->getMessage();
                }
            } else {
                $error = "An error occurred while moving the uploaded file.";
            }
        }
    } else {
        $error = "Please select a valid image file to upload.";
    }
}

// 4. Handle Profile Information Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $full_name    = trim($_POST['full_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    if (empty($full_name) || empty($phone_number)) {
        $error = "Full Name and Phone Number are required.";
    } else {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = :phone AND id != :user_id LIMIT 1");
            $checkStmt->execute(['phone' => $phone_number, 'user_id' => $user_id]);

            if ($checkStmt->rowCount() > 0) {
                $error = "This phone number is already registered to another account.";
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = :full_name, phone_number = :phone_number 
                    WHERE id = :user_id
                ");
                $updateStmt->execute([
                    'full_name'    => $full_name,
                    'phone_number' => $phone_number,
                    'user_id'      => $user_id
                ]);

                $_SESSION['user_name']  = $full_name;
                $_SESSION['user_phone'] = $phone_number;
                $user_name             = $full_name;

                $success = "Profile details updated successfully!";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// 5. Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirmation do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        try {
            $pwdStmt = $pdo->prepare("SELECT password FROM users WHERE id = :user_id LIMIT 1");
            $pwdStmt->execute(['user_id' => $user_id]);
            $user_data = $pwdStmt->fetch(PDO::FETCH_ASSOC);

            if ($user_data && password_verify($current_password, $user_data['password'])) {
                $new_hash  = password_hash($new_password, PASSWORD_BCRYPT);
                $updatePwd = $pdo->prepare("UPDATE users SET password = :password WHERE id = :user_id");
                $updatePwd->execute(['password' => $new_hash, 'user_id' => $user_id]);

                $success = "Password changed successfully!";
            } else {
                $error = "Incorrect current password.";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// 6. Fetch Current User Details
try {
    $userStmt = $pdo->prepare("SELECT full_name, phone_number, profile_pic, created_at FROM users WHERE id = :user_id LIMIT 1");
    $userStmt->execute(['user_id' => $user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    $current_fullname = $user['full_name'] ?? '';
    $current_phone    = $user['phone_number'] ?? '';
    $profile_pic      = !empty($user['profile_pic']) ? $user['profile_pic'] : 'default.png';
    $created_at       = $user['created_at'] ?? date('Y-m-d');

    // Profile Image Source Path Resolver
    if (file_exists('../assets/uploads/' . $profile_pic) && $profile_pic !== 'default.png') {
        $avatar_src = '../assets/uploads/' . $profile_pic;
    } else {
        $avatar_src = '../' . $site_logo;
    }
} catch (PDOException $e) {
    die("Error fetching profile details: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile | Tunza Waleti</title>
    <link rel="stylesheet" href="../assets/style/main.css">
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style/media.css">
    <script src="../assets/js/main.js" defer></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        .profile-main-container {
            width: 100%;
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 25px;
            margin-top: 20px;
        }

        /* Profile Left Card */
        .profile-card-left {
            background: #ffffff;
            border-radius: 12px;
            padding: 30px 20px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
            text-align: center;
        }

        .profile-avatar-container {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 15px auto;
        }

        .profile-avatar-container img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3.5px solid #510049;
            box-shadow: 0 4px 12px rgba(81, 0, 73, 0.15);
        }

        /* Upload Image Trigger Button Overlay */
        .avatar-upload-btn {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: #510049;
            color: #ffffff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .avatar-upload-btn:hover {
            background: #000000;
            transform: scale(1.08);
        }

        .profile-card-left h3 {
            font-size: 20px;
            color: #1a252f;
            margin-bottom: 4px;
        }

        .profile-card-left p.user-role {
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 20px;
        }

        .profile-stats-list {
            list-style: none;
            padding: 0;
            border-top: 1px solid #f1f2f6;
            margin-top: 15px;
            padding-top: 15px;
            text-align: left;
        }

        .profile-stats-list li {
            font-size: 13px;
            color: #34495e;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-stats-list li i {
            color: #510049;
            width: 18px;
        }

        /* Forms Right Column */
        .profile-form-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
        }

        .form-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a252f;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #f4f7f6;
            padding-bottom: 10px;
        }

        .form-card-title i {
            color: #510049;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .input-group {
            margin-bottom: 18px;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #34495e;
            margin-bottom: 6px;
        }

        .input-group input {
            width: 100%;
            height: 46px;
            padding: 10px 14px;
            border: 1.5px solid #dcdde1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.3s ease;
        }

        .input-group input:focus {
            border-color: #510049;
        }

        .btn-submit button {
            background-color: #510049;
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn-submit button:hover {
            background-color: #000000;
        }

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- ===== HEADER ===== -->
    <header class="header">
        <div class="user-profile">
            <img src="<?= htmlspecialchars($avatar_src) ?>" alt="user-image">
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
                    <li><a href="history.php">History</a></li>
                    <li><a href="profile.php" class="active">Profile</a></li>
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
                <li><a href="history.php"><i class="fas fa-history"></i> <span>History</span></a></li>
                <li class="active"><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </header>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="profile-main-container">

        <!-- Flash Notifications -->
        <?php if (!empty($error)): ?>
            <div class="alert error" style="background-color: #fce4e4; color: #c0392b; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert success" style="background-color: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="profile-grid">
            <!-- Left Info Sidebar -->
            <div class="profile-card-left">
                
                <!-- Profile Avatar Image with Form trigger -->
                <div class="profile-avatar-container">
                    <img src="<?= htmlspecialchars($avatar_src) ?>" alt="User Avatar" id="avatarPreview">
                    <label for="profile_image_input" class="avatar-upload-btn" title="Upload New Picture">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>

                <!-- Hidden form for image upload -->
                <form action="profile.php" method="POST" enctype="multipart/form-data" id="imageUploadForm">
                    <input type="hidden" name="action" value="upload_image">
                    <input type="file" name="profile_image" id="profile_image_input" accept="image/*" style="display: none;" onchange="submitAvatarForm()">
                </form>

                <h3><?= htmlspecialchars($current_fullname) ?></h3>
                <p class="user-role"><i class="fas fa-shield-alt"></i> Verified Saver</p>

                <ul class="profile-stats-list">
                    <li><i class="fas fa-phone"></i> <?= htmlspecialchars($current_phone) ?></li>
                    <li><i class="fas fa-calendar-alt"></i> Joined <?= date('M Y', strtotime($created_at)) ?></li>
                    <li><i class="fas fa-wallet"></i> Digital Saving Wallet</li>
                </ul>
            </div>

            <!-- Right Forms Column -->
            <div class="profile-forms-column">
                
                <!-- Personal Details Form -->
                <div class="profile-form-card">
                    <h4 class="form-card-title"><i class="fas fa-user-edit"></i> Edit Personal Details</h4>
                    <form action="profile.php" method="POST">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-row">
                            <div class="input-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($current_fullname) ?>" required>
                            </div>

                            <div class="input-group">
                                <label for="phone_number">Phone Number</label>
                                <input type="text" name="phone_number" id="phone_number" value="<?= htmlspecialchars($current_phone) ?>" required>
                            </div>
                        </div>

                        <div class="btn-submit">
                            <button type="submit">Update Information</button>
                        </div>
                    </form>
                </div>

                <!-- Password Change Form -->
                <div class="profile-form-card">
                    <h4 class="form-card-title"><i class="fas fa-lock"></i> Security & Password</h4>
                    <form action="profile.php" method="POST">
                        <input type="hidden" name="action" value="change_password">

                        <div class="input-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" name="current_password" id="current_password" required>
                        </div>

                        <div class="form-row">
                            <div class="input-group">
                                <label for="new_password">New Password</label>
                                <input type="password" name="new_password" id="new_password" placeholder="Min. 6 characters" required>
                            </div>

                            <div class="input-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirm_password" required>
                            </div>
                        </div>

                        <div class="btn-submit">
                            <button type="submit">Change Password</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

    </main>

    <!-- Client-side Cross-Tab Sync Listener -->
    <script>
        function submitAvatarForm() {
            const input = document.getElementById('profile_image_input');
            if (input.files && input.files[0]) {
                document.getElementById('imageUploadForm').submit();
            }
        }

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