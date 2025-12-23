<?php
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if ($_SESSION['user_role'] === 'tester') {
        header('Location: dashboard.php');
        exit();
    } elseif ($_SESSION['user_role'] === 'developer') {
        header('Location: bugshift.php');
        exit();
    } else {
        header('Location: dashboard.php');
        exit();
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            
            // Redirect based on user role
            if ($user['role'] === 'tester') {
                header('Location: dashboard.php');
                exit();
            } elseif ($user['role'] === 'developer') {
                header('Location: bug_shift.php');
                exit();
            } else {
                header('Location: dashboard.php');
                exit();
            }
        } else {
            $error = 'Invalid email or password';
        }
    } catch (PDOException $e) {
        $error = 'Login failed. Please try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="icon" href="logo.ico" type="image/x-icon">
     <link rel="icon" href="logo.ico" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1><?php echo SITE_NAME; ?></h1>
                <p>Sign in to your account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Sign In</button>
            </form>

            <div class="auth-footer">
                <p>Don't have an account? <a href="register.php">Sign up</a></p>
                <p><a href="index.php">← Back to Home</a></p>
            </div>

            <div class="demo-accounts">
                <h3>Demo Accounts</h3>
                <div class="demo-grid">
                    <div class="demo-account">
                        <strong>Admin:</strong> admin@bugtracker.com<br>
                        <strong>Password:</strong> admin123
                    </div>
                    <div class="demo-account">
                        <strong>Tester:</strong> tester@bugtracker.com<br>
                        <strong>Password:</strong> admin123
                    </div>
                    <div class="demo-account">
                        <strong>Developer:</strong> john@bugtracker.com<br>
                        <strong>Password:</strong> admin123
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>