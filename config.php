<?php
date_default_timezone_set('Asia/Kolkata');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'bugtracker');

// define('DB_HOST', '192.168.0.100');
// define('DB_USER', 'thekarti_yuvrajbugfix');
// define('DB_PASS', '12345678');
// define('DB_NAME', 'thekarti_yuvrajbugfix');


// Site configuration
define('SITE_NAME', 'Bug Tracker Pro');
define('UPLOAD_DIR', 'uploads/');

// Create uploads directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true); // Changed from 0777 to 0755 for security
}

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // Set MySQL timezone to match PHP timezone (IST)
    $pdo->exec("SET time_zone = '+05:30'");
    
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}

// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    session_start();
}

// Authentication helper functions
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth() {
        if (!isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }
}

if (!function_exists('getUserRole')) {
    function getUserRole() {
        return $_SESSION['user_role'] ?? null;
    }
}

if (!function_exists('getUserId')) {
    function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getUserName')) {
    function getUserName() {
        return $_SESSION['user_name'] ?? 'Unknown User';
    }
}

if (!function_exists('requireRole')) {
    function requireRole($role) {
        requireAuth();
        if (getUserRole() !== $role) {
            header('Location: dashboard.php');
            exit();
        }
    }
}

if (!function_exists('hasRole')) {
    function hasRole($roles) {
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        return in_array(getUserRole(), $roles);
    }
}

// Security helper functions
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map('sanitizeInput', $input);
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('validateCSRF')) {
    function validateCSRF($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('generateCSRF')) {
    function generateCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Date and time helper functions
if (!function_exists('formatDate')) {
    function formatDate($date) {
        if (empty($date) || $date === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('M d, Y g:i A', strtotime($date));
    }
}

if (!function_exists('formatDateOnly')) {
    function formatDateOnly($date) {
        if (empty($date) || $date === '0000-00-00') {
            return 'N/A';
        }
        return date('M d, Y', strtotime($date));
    }
}

if (!function_exists('getCurrentTimestamp')) {
    function getCurrentTimestamp() {
        return date('Y-m-d H:i:s');
    }
}

// Priority and status helper functions
if (!function_exists('getPriorityColor')) {
    function getPriorityColor($priority) {
        $colors = [
            'P1' => '#ef4444',    // Critical - Red
            'P2' => '#f97316',    // High - Orange
            'P3' => '#eab308',    // Medium - Yellow
            'P4' => '#22c55e',    // Low - Green
            'critical' => '#7c2d12',  // Dark red
            'high' => '#ef4444',      // Red
            'medium' => '#f59e0b',    // Amber
            'low' => '#10b981'        // Green
        ];
        return $colors[strtolower($priority)] ?? '#6b7280'; // Default gray
    }
}

if (!function_exists('getStatusColor')) {
    function getStatusColor($status) {
        $colors = [
            'pending' => '#6b7280',         // Gray
            'in_progress' => '#3b82f6',     // Blue
            'fixed' => '#f59e0b',           // Amber
            'awaiting_approval' => '#1d98c4', // Light blue
            'approved' => '#10b981',        // Green
            'rejected' => '#ef4444',        // Red
            'resolved' => '#10b981',        // Green
            'closed' => '#6b7280'           // Gray
        ];
        return $colors[strtolower($status)] ?? '#6b7280'; // Default gray
    }
}

if (!function_exists('getPriorityText')) {
    function getPriorityText($priority) {
        $priorities = [
            'P1' => 'Critical',
            'P2' => 'High',
            'P3' => 'Medium',
            'P4' => 'Low'
        ];
        return $priorities[$priority] ?? ucfirst($priority);
    }
}

if (!function_exists('getStatusText')) {
    function getStatusText($status) {
        return ucwords(str_replace(['_', '-'], ' ', $status));
    }
}

// File upload helper functions
if (!function_exists('isValidImageType')) {
    function isValidImageType($file) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return in_array($file['type'], $allowedTypes);
    }
}

if (!function_exists('generateUniqueFileName')) {
    function generateUniqueFileName($originalName) {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        return uniqid() . '_' . time() . '.' . $extension;
    }
}

// Error handling and logging
if (!function_exists('logError')) {
    function logError($message, $context = []) {
        $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
        if (!empty($context)) {
            $logMessage .= ' - Context: ' . json_encode($context);
        }
        error_log($logMessage);
    }
}

if (!function_exists('handleException')) {
    function handleException($e, $userMessage = 'An error occurred') {
        logError($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if (headers_sent()) {
            echo json_encode(['success' => false, 'message' => $userMessage]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $userMessage]);
        }
        exit;
    }
}

// Utility functions
if (!function_exists('redirect')) {
    function redirect($url, $permanent = false) {
        if (!headers_sent()) {
            if ($permanent) {
                header('HTTP/1.1 301 Moved Permanently');
            }
            header('Location: ' . $url);
        } else {
            echo '<script>window.location.href="' . htmlspecialchars($url) . '";</script>';
        }
        exit;
    }
}

if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script = dirname($_SERVER['SCRIPT_NAME']);
        return rtrim($protocol . $host . $script, '/') . '/';
    }
}

// Initialize error reporting based on environment
if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Set default timezone for the entire application
ini_set('date.timezone', 'Asia/Kolkata');
?>