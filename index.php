<?php
// index.php - Tunza Waleti Fully Responsive Landing Page with Real Operator Brand Logos

// session_name('TUNZA_USER_SESSION');
// session_start();

require_once 'database/config.php';

// Normalize PDO connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Redirect logged-in users directly to their respective dashboards
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['user_role'] ?? 'user') === 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: pages/dashboard.php");
    }
    exit();
}

// Fetch system logo dynamically
$site_logo = 'assets/images/panta logo-07.jpg'; 
if (isset($pdo) && $pdo !== null) {
    try {
        $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
        if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['setting_value'])) {
                $site_logo = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tunza Waleti - Digital Saving & Target Management Platform</title>
    <link rel="stylesheet" href="assets/style/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style/media.css">
    <link rel="icon" href="<?= htmlspecialchars($site_logo) ?>">
    <style>
        :root {
            --primary-purple: #510049;
            --dark-purple: #3d0037;
            --emerald-green: #117864;
            --light-green: #27ae60;
            --accent-gold: #f39c12;
            --text-dark: #2c3e50;
            --text-muted: #666666;
            --bg-light: #f8f9fa;
            --border-color: #e9ecef;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            color: var(--text-dark);
            background-color: #ffffff;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* SCROLL ANIMATION UTILITY CLASSES */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }

        .animate-on-scroll.animated {
            opacity: 1;
            transform: translateY(0);
        }

        .delay-1 { transition-delay: 0.1s; }
        .delay-2 { transition-delay: 0.2s; }
        .delay-3 { transition-delay: 0.3s; }
        .delay-4 { transition-delay: 0.4s; }

        /* Navigation Header */
        .landing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 8%;
            background: #ffffff;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--primary-purple);
            font-weight: 700;
            font-size: 20px;
        }

        .brand-logo img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-links a {
            text-decoration: none;
            color: #555;
            font-weight: 600;
            font-size: 14px;
            transition: 0.3s;
        }

        .nav-links a:hover {
            color: var(--primary-purple);
        }

        .btn-nav-login {
            border: 2px solid var(--primary-purple);
            color: var(--primary-purple) !important;
            padding: 8px 18px;
            border-radius: 8px;
        }

        .btn-nav-register {
            background: var(--primary-purple);
            color: #fff !important;
            padding: 8px 20px;
            border-radius: 8px;
        }

        /* Hamburger Toggle Button */
        .hamburger-btn {
            display: none;
            background: transparent;
            border: none;
            font-size: 24px;
            color: var(--primary-purple);
            cursor: pointer;
            padding: 5px;
            z-index: 1001;
        }

        /* Hero Section */
        .hero-section {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 40px;
            padding: 80px 8%;
            background: linear-gradient(135deg, #fdfbfd 0%, #f4e8f3 100%);
        }

        .hero-content h1 {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.25;
            color: var(--dark-purple);
            margin-bottom: 20px;
        }

        .hero-content p {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .hero-stats {
            display: flex;
            gap: 30px;
            margin-bottom: 30px;
            padding-top: 10px;
            border-top: 1px solid rgba(81, 0, 73, 0.1);
        }

        .stat-item h4 {
            font-size: 22px;
            color: var(--primary-purple);
            margin: 0;
            font-weight: 700;
        }

        .stat-item p {
            font-size: 12px;
            margin: 0;
            color: #777;
            text-transform: uppercase;
            font-weight: 600;
        }

        .hero-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-hero {
            padding: 14px 28px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: 0.3s ease;
        }

        .btn-hero-primary {
            background: var(--primary-purple);
            color: #fff;
            box-shadow: 0 6px 20px rgba(81, 0, 73, 0.25);
        }

        .btn-hero-primary:hover {
            background: var(--dark-purple);
            transform: translateY(-2px);
        }

        .btn-hero-secondary {
            background: #fff;
            color: var(--emerald-green);
            border: 2px solid var(--emerald-green);
        }

        .btn-hero-secondary:hover {
            background: var(--emerald-green);
            color: #fff;
            transform: translateY(-2px);
        }

        /* Hero Demo Component */
        .hero-preview-wrapper {
            background: #ffffff;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
        }

        .demo-card {
            background: linear-gradient(135deg, var(--dark-purple) 0%, var(--emerald-green) 100%);
            border-radius: 16px;
            padding: 25px;
            color: #fff;
            margin-bottom: 20px;
        }

        .demo-goal-item {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 15px;
            margin-top: 12px;
        }

        .demo-progress-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }

        .demo-progress-fill {
            height: 100%;
            background: var(--light-green);
            width: 65%;
        }

        /* ================================================================== */
        /* MARQUEE OPERATOR CARDS & ACTUAL IMAGE / SVG LOGOS                  */
        /* ================================================================== */
        .marquee-section {
            background: #ffffff;
            padding: 35px 0;
            border-bottom: 1px solid var(--border-color);
            overflow: hidden;
        }

        .marquee-title {
            text-align: center;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #888;
            font-weight: 700;
            margin-bottom: 25px;
        }

        .marquee-container {
            display: flex;
            width: 100%;
            overflow: hidden;
            position: relative;
            mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent);
            -webkit-mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent);
        }

        .marquee-track {
            display: flex;
            align-items: center;
            gap: 35px;
            white-space: nowrap;
            animation: marqueeScroll 22s linear infinite;
        }

        .marquee-container:hover .marquee-track {
            animation-play-state: paused;
        }

        @keyframes marqueeScroll {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .operator-badge {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            padding: 10px 22px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .operator-badge:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .operator-logo-img {
            width: 32px;
            height: 32px;
            object-fit: contain;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .operator-logo-svg {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .operator-vodacom { border-left: 4px solid #e60000; color: #cc0000; }
        .operator-halotel { border-left: 4px solid #ff6600; color: #e65c00; }
        .operator-yas { border-left: 4px solid #003399; color: #002266; }
        .operator-airtel { border-left: 4px solid #ff0000; color: #d60000; }

        /* Architecture Grid */
        .detailed-architecture {
            padding: 80px 8%;
            background: #ffffff;
        }

        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 50px auto;
        }

        .section-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark-purple);
            margin-bottom: 12px;
        }

        .section-subtitle {
            font-size: 15px;
            color: var(--text-muted);
        }

        .architecture-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .arch-card {
            background: var(--bg-light);
            padding: 35px 30px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            transition: 0.3s ease;
        }

        .arch-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.08);
            border-color: var(--primary-purple);
        }

        .arch-icon {
            width: 54px;
            height: 54px;
            background: #f4e8f3;
            color: var(--primary-purple);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 20px;
        }

        .arch-card h3 {
            font-size: 18px;
            color: var(--dark-purple);
            margin-bottom: 12px;
        }

        .arch-card p {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 15px;
        }

        .arch-card ul {
            padding-left: 20px;
            margin: 0;
            font-size: 13px;
            color: #555;
        }

        .arch-card ul li {
            margin-bottom: 6px;
        }

        /* Operations Section */
        .deep-dive {
            padding: 80px 8%;
            background: var(--bg-light);
        }

        .process-list {
            display: flex;
            flex-direction: column;
            gap: 30px;
            max-width: 900px;
            margin: 40px auto 0 auto;
        }

        .process-step {
            display: flex;
            gap: 25px;
            background: #ffffff;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border-left: 5px solid var(--primary-purple);
            transition: 0.3s ease;
        }

        .process-step:hover {
            transform: translateX(10px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.06);
        }

        .step-num-badge {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-purple);
            background: #f4e8f3;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-info h3 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: var(--dark-purple);
        }

        .step-info p {
            margin: 0;
            font-size: 14px;
            color: var(--text-muted);
        }

        /* FAQ Accordion */
        .faq-section {
            padding: 80px 8%;
            background: #ffffff;
        }

        .faq-container {
            max-width: 800px;
            margin: 40px auto 0 auto;
        }

        .faq-item {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 15px;
            overflow: hidden;
            background: #fff;
            transition: 0.3s ease;
        }

        .faq-question {
            padding: 20px 25px;
            background: #fff;
            font-weight: 700;
            font-size: 16px;
            color: var(--dark-purple);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.3s;
        }

        .faq-question:hover {
            background: var(--bg-light);
        }

        .faq-answer {
            padding: 0 25px 20px 25px;
            font-size: 14px;
            color: var(--text-muted);
            display: none;
            border-top: 1px solid var(--border-color);
            background: #fafafa;
        }

        .faq-item.active .faq-answer {
            display: block;
            padding-top: 15px;
        }

        .faq-item.active .faq-icon {
            transform: rotate(180deg);
        }

        .faq-icon {
            transition: 0.3s;
            color: var(--primary-purple);
        }

        /* CTA Banner */
        .cta-banner {
            margin: 60px 8%;
            padding: 60px 40px;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--emerald-green) 100%);
            border-radius: 20px;
            color: #fff;
            text-align: center;
        }

        .cta-banner h2 {
            font-size: 34px;
            margin-bottom: 15px;
            font-weight: 800;
        }

        .cta-banner p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Footer */
        .landing-footer {
            padding: 40px 8% 30px 8%;
            background: var(--dark-purple);
            color: #fff;
            font-size: 14px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .footer-brand p {
            opacity: 0.7;
            font-size: 13px;
            margin-top: 10px;
        }

        .footer-column h4 {
            margin-top: 0;
            font-size: 16px;
            margin-bottom: 15px;
        }

        .footer-column ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-column ul li {
            margin-bottom: 10px;
        }

        .footer-column ul li a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
        }

        .footer-column ul li a:hover {
            color: #fff;
        }

        .footer-bottom {
            text-align: center;
            opacity: 0.7;
            font-size: 13px;
        }

        /* RESPONSIVE MEDIA QUERIES & MOBILE HAMBURGER STYLES */
        @media (max-width: 900px) {
            .hero-section {
                grid-template-columns: 1fr;
                text-align: center;
                padding: 40px 5%;
            }
            .hero-content h1 {
                font-size: 32px;
            }
            .hero-stats {
                justify-content: center;
            }
            .hero-buttons {
                justify-content: center;
            }
            .cta-banner {
                margin: 40px 5%;
                padding: 40px 20px;
            }
            .cta-banner h2 {
                font-size: 26px;
            }
        }

        @media (max-width: 768px) {
            .landing-header {
                padding: 14px 5%;
            }

            .hamburger-btn {
                display: block;
            }

            .nav-links {
                position: absolute;
                top: 100%;
                left: 0;
                width: 100%;
                background: #ffffff;
                flex-direction: column;
                padding: 25px 0;
                gap: 20px;
                box-shadow: 0 10px 20px rgba(0,0,0,0.1);
                border-top: 1px solid var(--border-color);
                display: none;
            }

            .nav-links.active {
                display: flex;
            }

            .nav-links li {
                width: 100%;
                text-align: center;
            }

            .btn-nav-login, .btn-nav-register {
                display: inline-block;
                width: 80%;
                box-sizing: border-box;
            }

            .detailed-architecture, .deep-dive, .faq-section {
                padding: 50px 5%;
            }

            .section-title {
                font-size: 26px;
            }

            .architecture-grid {
                grid-template-columns: 1fr;
            }

            .process-step {
                flex-direction: column;
                gap: 15px;
                padding: 20px;
            }

            .process-step:hover {
                transform: translateY(-5px);
            }

            .footer-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }
        }

        @media (max-width: 480px) {
            .hero-stats {
                flex-direction: column;
                gap: 15px;
            }
            .btn-hero {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <!-- NAV HEADER WITH HAMBURGER BUTTON -->
    <header class="landing-header">
        <a href="index.php" class="brand-logo">
            <img src="<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
            <span>Tunza Waleti</span>
        </a>

        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle navigation menu">
            <i class="fas fa-bars"></i>
        </button>

        <nav>
            <ul class="nav-links" id="navLinks">
                <li><a href="#features" class="nav-item">Architecture</a></li>
                <li><a href="#how-it-works" class="nav-item">Operations</a></li>
                <li><a href="#faq" class="nav-item">FAQ</a></li>
                <li><a href="login.php" class="btn-nav-login nav-item">Sign In</a></li>
                <li><a href="register.php" class="btn-nav-register nav-item">Get Started</a></li>
            </ul>
        </nav>
    </header>

    <!-- HERO SECTION -->
    <section class="hero-section">
        <div class="hero-content animate-on-scroll">
            <h1>Master Your Savings with Structured Financial Target Control.</h1>
            <p>Tunza Waleti provides a dual-wallet architecture designed to isolate day-to-day liquidity from long-term target goals. Build financial discipline with clear progress metrics and real-time ledger accounting.</p>

            <div class="hero-stats">
                <div class="stat-item">
                    <h4>100%</h4>
                    <p>Ledger Accuracy</p>
                </div>
                <div class="stat-item">
                    <h4>Dual-Layer</h4>
                    <p>Wallet System</p>
                </div>
                <div class="stat-item">
                    <h4>24/7</h4>
                    <p>Real-Time Tracking</p>
                </div>
            </div>

            <div class="hero-buttons">
                <a href="register.php" class="btn-hero btn-hero-primary"><i class="fas fa-rocket"></i> Create Free Account</a>
                <a href="login.php" class="btn-hero btn-hero-secondary"><i class="fas fa-sign-in-alt"></i> Access Dashboard</a>
            </div>
        </div>

        <!-- HERO DEMO PREVIEW -->
        <div class="hero-preview-wrapper animate-on-scroll delay-2">
            <div class="demo-card">
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; text-transform:uppercase; opacity:0.8;">
                    <span><i class="fas fa-wallet"></i> Primary Wallet Balance</span>
                    <span><i class="fas fa-shield-alt"></i> Active</span>
                </div>
                <div style="font-size:28px; font-weight:700; margin: 10px 0;">TZS 850,000.00</div>
                <div style="font-size:12px; opacity:0.85;"><i class="fas fa-sync-alt"></i> Available for instant internal transfer</div>
            </div>

            <div style="font-size:13px; font-weight:700; color:var(--dark-purple); margin-bottom:8px;">Target Goals Breakdown</div>
            
            <div class="demo-goal-item">
                <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600;">
                    <span>Laptop Upgrade Fund</span>
                    <span style="color:var(--emerald-green);">65%</span>
                </div>
                <div class="demo-progress-bar">
                    <div class="demo-progress-fill"></div>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:11px; color:#777; margin-top:6px;">
                    <span>TZS 650,000 / TZS 1,000,000</span>
                    <span><i class="fas fa-clock"></i> 14 Days Left</span>
                </div>
            </div>
        </div>
    </section>

    <!-- MOBILE MONEY OPERATORS INFINITE MARQUEE WITH ACTUAL LOGOS -->
    <section class="marquee-section animate-on-scroll">
        <div class="marquee-title">Supported Tanzanian Mobile Money Networks</div>
        <div class="marquee-container">
            <div class="marquee-track">
                
                <!-- Vodacom M-Pesa Badge -->
                <div class="operator-badge operator-vodacom">
                    <div class="operator-logo-svg" style="background:#e60000;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14.5h-2v-2h2v2zm0-4h-2V7h2v5.5z"/>
                        </svg>
                    </div>
                    <span>Vodacom M-Pesa</span>
                </div>

                <!-- Halotel HaloPesa Badge -->
                <div class="operator-badge operator-halotel">
                    <div class="operator-logo-svg" style="background:#ff6600;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.8l6.9 3.5-6.9 3.4-6.9-3.4L12 4.8zM4.5 9.2l6.5 3.3v6.7l-6.5-3.2V9.2zm15 6.8l-6.5 3.2v-6.7l6.5-3.3v6.8z"/>
                        </svg>
                    </div>
                    <span>Halotel HaloPesa</span>
                </div>

                <!-- Yas (Tigo Pesa) Badge -->
                <div class="operator-badge operator-yas">
                    <div class="operator-logo-svg" style="background:#003399;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.5v-9l7 4.5-7 4.5z"/>
                        </svg>
                    </div>
                    <span>Yas (Tigo Pesa)</span>
                </div>

                <!-- Airtel Money Badge -->
                <div class="operator-badge operator-airtel">
                    <div class="operator-logo-svg" style="background:#ff0000;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2L1 21h22L12 2zm0 4.2L18.8 18H5.2L12 6.2z"/>
                        </svg>
                    </div>
                    <span>Airtel Money</span>
                </div>

                <!-- DUPLICATED MARQUEE LOOP FOR CONTINUOUS ANIMATION -->
                <div class="operator-badge operator-vodacom">
                    <div class="operator-logo-svg" style="background:#e60000;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14.5h-2v-2h2v2zm0-4h-2V7h2v5.5z"/>
                        </svg>
                    </div>
                    <span>Vodacom M-Pesa</span>
                </div>

                <div class="operator-badge operator-halotel">
                    <div class="operator-logo-svg" style="background:#ff6600;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.8l6.9 3.5-6.9 3.4-6.9-3.4L12 4.8zM4.5 9.2l6.5 3.3v6.7l-6.5-3.2V9.2zm15 6.8l-6.5 3.2v-6.7l6.5-3.3v6.8z"/>
                        </svg>
                    </div>
                    <span>Halotel HaloPesa</span>
                </div>

                <div class="operator-badge operator-yas">
                    <div class="operator-logo-svg" style="background:#003399;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.5v-9l7 4.5-7 4.5z"/>
                        </svg>
                    </div>
                    <span>Yas (Tigo Pesa)</span>
                </div>

                <div class="operator-badge operator-airtel">
                    <div class="operator-logo-svg" style="background:#ff0000;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff">
                            <path d="M12 2L1 21h22L12 2zm0 4.2L18.8 18H5.2L12 6.2z"/>
                        </svg>
                    </div>
                    <span>Airtel Money</span>
                </div>

            </div>
        </div>
    </section>

    <!-- SYSTEM ARCHITECTURE DETAILED SECTION -->
    <section class="detailed-architecture" id="features">
        <div class="section-header animate-on-scroll">
            <h2 class="section-title">In-Depth Platform Architecture</h2>
            <p class="section-subtitle">Understand how Tunza Waleti structures your funds, verifies transactions, and enforces financial discipline.</p>
        </div>

        <div class="architecture-grid">
            <div class="arch-card animate-on-scroll delay-1">
                <div class="arch-icon"><i class="fas fa-columns"></i></div>
                <h3>Dual-Engine Wallet System</h3>
                <p>Separates liquid funds from earmarked target allocations so users do not accidentally spend their long-term savings.</p>
                <ul>
                    <li><strong>Main Wallet:</strong> Holds liquid funds for general usage and manual deposits.</li>
                    <li><strong>Goal Savings Buckets:</strong> Isolates sub-balances assigned strictly to specific savings goals.</li>
                </ul>
            </div>

            <div class="arch-card animate-on-scroll delay-2">
                <div class="arch-icon"><i class="fas fa-clock"></i></div>
                <h3>Target Duration Engine</h3>
                <p>Supports flexible timeframe configurations to match your personal planning timeline accurately.</p>
                <ul>
                    <li><strong>Fixed Date Targets:</strong> Define exact completion calendar deadlines.</li>
                    <li><strong>Relative Duration:</strong> Specify flexible durations (e.g., 30 Days, 6 Months).</li>
                    <li><strong>Live Countdown Engine:</strong> Real-time JavaScript clock tracking maturity progress down to the second.</li>
                </ul>
            </div>

            <div class="arch-card animate-on-scroll delay-3">
                <div class="arch-icon"><i class="fas fa-exchange-alt"></i></div>
                <h3>Flexible Internal Transfers</h3>
                <p>Transfer funds instantly between your main wallet balance and target goal buckets with zero delays.</p>
                <ul>
                    <li><strong>Add Funds:</strong> Move available balance into a target goal anytime.</li>
                    <li><strong>Instant Withdrawals:</strong> Move money from any target back to your main wallet whenever needed.</li>
                    <li><strong>Atomic SQL Transactions:</strong> Guaranteed data consistency using database row locking (`FOR UPDATE`).</li>
                </ul>
            </div>

            <div class="arch-card animate-on-scroll delay-1">
                <div class="arch-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <h3>Audited Transaction Ledger</h3>
                <p>Every balance movement generates an immutable transaction log to ensure full accountability.</p>
                <ul>
                    <li>Unique reference ID generation for every operation (e.g. `GOAL-ADD-XXXX`).</li>
                    <li>Time-stamped audit records for complete financial tracking.</li>
                    <li>Status flags (`completed`, `pending`, `failed`) for precise record keeping.</li>
                </ul>
            </div>

            <div class="arch-card animate-on-scroll delay-2">
                <div class="arch-icon"><i class="fas fa-user-shield"></i></div>
                <h3>Account Security Controls</h3>
                <p>Robust authorization checks protect users and system integrity against unauthorized actions.</p>
                <ul>
                    <li>Session isolation to prevent unauthorized access across accounts.</li>
                    <li>Role-based access separation between regular users and administrative functions.</li>
                    <li>Instant account suspension safeguards enforced automatically at session entry.</li>
                </ul>
            </div>

            <div class="arch-card animate-on-scroll delay-3">
                <div class="arch-icon"><i class="fas fa-headset"></i></div>
                <h3>Dedicated Support Integration</h3>
                <p>Integrated customer care access points directly within the user portal for quick resolution.</p>
                <ul>
                    <li>Direct access to administrative customer support modules.</li>
                    <li>Clear issue tracking for suspended or restricted accounts.</li>
                    <li>Guaranteed support guidance for system operations.</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- OPERATIONS STEP-BY-STEP DEEP DIVE -->
    <section class="deep-dive" id="how-it-works">
        <div class="section-header animate-on-scroll">
            <h2 class="section-title">How Tunza Waleti Operates</h2>
            <p class="section-subtitle">A clear breakdown of the money management lifecycle on our platform.</p>
        </div>

        <div class="process-list">
            <div class="process-step animate-on-scroll delay-1">
                <div class="step-num-badge">1</div>
                <div class="step-info">
                    <h3>Account Initialization & Main Wallet Creation</h3>
                    <p>When you sign up, Tunza Waleti automatically generates your primary digital wallet record with your baseline currency preference. This main balance acts as your operational account for incoming deposits and goal allocations.</p>
                </div>
            </div>

            <div class="process-step animate-on-scroll delay-2">
                <div class="step-num-badge">2</div>
                <div class="step-info">
                    <h3>Setting Up Earmarked Target Savings Goals</h3>
                    <p>Create dedicated savings goals by setting a title, target amount, and deadline. The system calculates your progress percentage automatically as funds are added.</p>
                </div>
            </div>

            <div class="process-step animate-on-scroll delay-3">
                <div class="step-num-badge">3</div>
                <div class="step-info">
                    <h3>Allocating Funds & Tracking Live Progress</h3>
                    <p>Transfer funds from your available wallet into your target goals. Live countdown timers display your remaining target duration down to the second, helping you stay on track.</p>
                </div>
            </div>

            <div class="process-step animate-on-scroll delay-4">
                <div class="step-num-badge">4</div>
                <div class="step-info">
                    <h3>Releasing Funds Back to Available Wallet</h3>
                    <p>Need your funds back? Withdraw money from your target goals back into your main wallet at any time. The system updates your balances instantly and logs the transaction for complete transparency.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FREQUENTLY ASKED QUESTIONS -->
    <section class="faq-section" id="faq">
        <div class="section-header animate-on-scroll">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Everything you need to know about system features and policies.</p>
        </div>

        <div class="faq-container">
            <div class="faq-item animate-on-scroll delay-1">
                <div class="faq-question">
                    Can I withdraw money from a target goal before the target date?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="faq-answer">
                    Yes. You can transfer funds from any savings goal back to your available main wallet whenever you need them. The timer provides target tracking guidance, but your funds remain accessible.
                </div>
            </div>

            <div class="faq-item animate-on-scroll delay-2">
                <div class="faq-question">
                    How does the system ensure my transaction balances remain accurate?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="faq-answer">
                    Tunza Waleti uses atomic database transactions with row-level locking (`FOR UPDATE`). This guarantees that your main wallet and target savings balances update simultaneously without race conditions or discrepancies.
                </div>
            </div>

            <div class="faq-item animate-on-scroll delay-3">
                <div class="faq-question">
                    What happens if my account gets suspended?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="faq-answer">
                    If an administrator flags an account as suspended, an automated security screen restricts portal access immediately. A direct Customer Care support contact button is provided so you can resolve access issues quickly.
                </div>
            </div>

            <div class="faq-item animate-on-scroll delay-4">
                <div class="faq-question">
                    Is there a limit to how many target goals I can create?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="faq-answer">
                    No. You can create multiple target goals to organize different financial objectives concurrently (such as emergency funds, tech upgrades, or business investments).
                </div>
            </div>
        </div>
    </section>

    <!-- CALL TO ACTION BANNER -->
    <section class="cta-banner animate-on-scroll">
        <h2>Start Managing Your Savings Today</h2>
        <p>Set up your free Tunza Waleti account in minutes to start tracking your goals with confidence.</p>
        <a href="register.php" class="btn-hero btn-hero-primary" style="background:#fff; color:var(--primary-purple);"><i class="fas fa-user-plus"></i> Open Free Account</a>
    </section>

    <!-- FOOTER -->
    <footer class="landing-footer">
        <div class="footer-grid">
            <div class="footer-brand">
                <div class="brand-logo" style="color:#fff;">
                    <img src="<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
                    <span>Tunza Waleti</span>
                </div>
                <p>Digital Savings & Target Goal Management System designed to build financial discipline and simplify progress tracking.</p>
            </div>
            <div class="footer-column">
                <h4>Navigation</h4>
                <ul>
                    <li><a href="#features">Architecture</a></li>
                    <li><a href="#how-it-works">Operations</a></li>
                    <li><a href="#faq">FAQ</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>User Portal</h4>
                <ul>
                    <li><a href="login.php">Sign In</a></li>
                    <li><a href="register.php">Create Account</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> Tunza Waleti. All rights reserved.</p>
        </div>
    </footer>

    <!-- INTERACTIVE SCRIPT ENGINE -->
    <script>
        // 1. Mobile Hamburger Menu Toggle Engine
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const navLinks = document.getElementById('navLinks');
        const navItems = document.querySelectorAll('.nav-item');

        hamburgerBtn.addEventListener('click', () => {
            navLinks.classList.toggle('active');
            const icon = hamburgerBtn.querySelector('i');
            if (navLinks.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        navItems.forEach(item => {
            item.addEventListener('click', () => {
                if (navLinks.classList.contains('active')) {
                    navLinks.classList.remove('active');
                    const icon = hamburgerBtn.querySelector('i');
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });
        });

        // 2. Scroll-Triggered Card Observer Engine
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.15
            };

            const scrollObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animated');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.animate-on-scroll').forEach(el => {
                scrollObserver.observe(el);
            });
        });

        // 3. Accordion FAQ Engine
        document.querySelectorAll('.faq-question').forEach(item => {
            item.addEventListener('click', () => {
                const parent = item.parentElement;
                
                if (parent.classList.contains('active')) {
                    parent.classList.remove('active');
                } else {
                    document.querySelectorAll('.faq-item').forEach(child => child.classList.remove('active'));
                    parent.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>