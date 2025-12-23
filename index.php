<?php
require_once 'config.php';

// Redirect to appropriate dashboard if logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Professional Bug Tracking System</title>
    <link rel="icon" href="logo.ico" type="image/x-icon">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="landing-container">
        <header class="landing-header">
            <div class="container">
                <div class="logo">
                    <h1><?php echo SITE_NAME; ?></h1>
                    <span class="tagline">Professional Bug Tracking & Resolution</span>
                </div>
                <nav class="nav-links">
                    <a href="login.php" class="btn btn-outline">Login</a>
                    <a href="register.php" class="btn btn-primary">Get Started</a>
                </nav>
            </div>
        </header>

        <main class="landing-main">
            <section class="hero">
                <div class="container">
                    <div class="hero-content">
                        <h2>Streamline Your Bug Tracking Workflow</h2>
                        <p>A comprehensive platform for testers, developers, and project managers to efficiently track, resolve, and manage software bugs from creation to resolution.</p>
                        <div class="hero-buttons">
                            <a href="register.php" class="btn btn-primary btn-large">Start Tracking Bugs</a>
                            <a href="login.php" class="btn btn-outline btn-large">Sign In</a>
                        </div>
                    </div>
                    <div class="hero-image">
                        <div class="feature-card">
                            <div class="feature-icon">🐛</div>
                            <h3>Bug Tracking</h3>
                            <p>Comprehensive bug reporting with priority levels and visual evidence</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">👨‍💻</div>
                            <h3>Developer Portal</h3>
                            <p>Dedicated dashboard for developers to manage and resolve assigned bugs</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">📊</div>
                            <h3>Progress Tracking</h3>
                            <p>Real-time status updates and approval workflows</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="features">
                <div class="container">
                    <h2>Powerful Features for Every Role</h2>
                    <div class="features-grid">
                        <div class="feature-item">
                            <div class="feature-icon">🎯</div>
                            <h3>Priority-Based Tracking</h3>
                            <p>Categorize bugs from P1 (Critical) to P4 (Minor) for efficient resource allocation</p>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">📸</div>
                            <h3>Visual Evidence</h3>
                            <p>Upload screenshots and images to provide clear context for bug reports</p>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">🔄</div>
                            <h3>Approval Workflow</h3>
                            <p>Structured approval process with tester verification of bug fixes</p>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">📝</div>
                            <h3>Collaborative Comments</h3>
                            <p>Rich communication system between testers and developers</p>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">📊</div>
                            <h3>PDF Reports</h3>
                            <p>Generate comprehensive bug reports for stakeholders and documentation</p>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">⚡</div>
                            <h3>Real-time Updates</h3>
                            <p>Instant status notifications and progress tracking for all stakeholders</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="landing-footer">
            <div class="container">
                <p>&copy; 2025 <?php echo SITE_NAME; ?>. Built for efficient bug tracking and resolution.</p>
            </div>
        </footer>
    </div>
</body>
</html>