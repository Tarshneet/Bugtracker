<?php
// Set timezone - change this to your local timezone
date_default_timezone_set('America/New_York'); // Change to your timezone

require_once 'config.php';
requireAuth();

// Set MySQL timezone to match PHP
try {
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    error_log('Failed to set MySQL timezone: ' . $e->getMessage());
}

// Helper function to get current timestamp in proper timezone
function getCurrentTimestamp() {
    return date('Y-m-d H:i:s');
}

// Enhanced formatDateTime function with timezone support
function formatDateTime($datetime) {
    if (!$datetime) return 'N/A';
    
    try {
        $date = new DateTime($datetime);
        return $date->format('M j, Y g:i A T');
    } catch (Exception $e) {
        return date('M j, Y g:i A', strtotime($datetime));
    }
}

function formatDate($date) {
    if (!$date) return 'N/A';
    
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format('M j, Y g:i A');
    } catch (Exception $e) {
        return date('M j, Y g:i A', strtotime($date));
    }
}

function getPriorityColor($priority) {
    $colors = [
        'low' => '#10b981',
        'medium' => '#f59e0b', 
        'high' => '#ef4444',
        'critical' => '#7c2d12'
    ];
    return $colors[$priority] ?? '#6b7280';
}

function getStatusColor($status) {
    $colors = [
        'pending' => '#6b7280',
        'in_progress' => '#3b82f6',
        'fixed' => '#f59e0b',
        'approved' => '#10b981',
        'rejected' => '#ef4444'
    ];
    return $colors[$status] ?? '#6b7280';
}

$userRole = getUserRole();
$selectedBugId = intval($_GET['bug_id'] ?? 0);
$searchQuery = $_GET['search'] ?? '';
$error = '';
$success = '';

// Get bugs based on user role with search functionality
$bugs = [];
try {
    $searchCondition = '';
    $searchParams = [];
    
    if (!empty($searchQuery)) {
        $searchCondition = " AND (bt.title LIKE ? OR bt.module LIKE ? OR bt.submodule LIKE ?)";
        $searchParams = ["%$searchQuery%", "%$searchQuery%", "%$searchQuery%"];
    }
    
    if ($userRole == 'tester') {
        // Testers can chat about their own bugs
        $stmt = $pdo->prepare("
            SELECT bt.id, bt.title, bt.module, bt.submodule, bt.status, bt.priority, 
                   bt.created_at, u.name as developer_name,
                   (SELECT COUNT(*) FROM bug_remarks br WHERE br.bug_id = bt.id) as message_count,
                   (SELECT MAX(br.timestamp) FROM bug_remarks br WHERE br.bug_id = bt.id) as last_message_time
            FROM bug_tickets bt 
            LEFT JOIN users u ON bt.assigned_dev_id = u.id 
            WHERE bt.created_by = ? $searchCondition
            ORDER BY last_message_time DESC, bt.created_at DESC
        ");
        $params = array_merge([$_SESSION['user_id']], $searchParams);
        $stmt->execute($params);
    } elseif ($userRole == 'developer') {
        // Developers can chat about bugs assigned to them
        $stmt = $pdo->prepare("
            SELECT bt.id, bt.title, bt.module, bt.submodule, bt.status, bt.priority, 
                   bt.created_at, u.name as tester_name,
                   (SELECT COUNT(*) FROM bug_remarks br WHERE br.bug_id = bt.id) as message_count,
                   (SELECT MAX(br.timestamp) FROM bug_remarks br WHERE br.bug_id = bt.id) as last_message_time
            FROM bug_tickets bt 
            JOIN users u ON bt.created_by = u.id 
            WHERE bt.assigned_dev_id = ? $searchCondition
            ORDER BY last_message_time DESC, bt.created_at DESC
        ");
        $params = array_merge([$_SESSION['user_id']], $searchParams);
        $stmt->execute($params);
    } elseif ($userRole == 'admin') {
        // Admins can chat about all bugs
        $stmt = $pdo->prepare("
            SELECT bt.id, bt.title, bt.module, bt.submodule, bt.status, bt.priority, 
                   bt.created_at, u1.name as tester_name, u2.name as developer_name,
                   (SELECT COUNT(*) FROM bug_remarks br WHERE br.bug_id = bt.id) as message_count,
                   (SELECT MAX(br.timestamp) FROM bug_remarks br WHERE br.bug_id = bt.id) as last_message_time
            FROM bug_tickets bt 
            LEFT JOIN users u1 ON bt.created_by = u1.id 
            LEFT JOIN users u2 ON bt.assigned_dev_id = u2.id 
            WHERE 1=1 $searchCondition
            ORDER BY last_message_time DESC, bt.created_at DESC
        ");
        $stmt->execute($searchParams);
    }
    $bugs = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load bugs: ' . $e->getMessage();
    error_log('Bug loading error: ' . $e->getMessage());
}

// Get selected bug details
$selectedBug = null;
if ($selectedBugId > 0) {
    try {
        if ($userRole == 'tester') {
            $stmt = $pdo->prepare("
                SELECT bt.*, u.name as developer_name, p.name as project_name
                FROM bug_tickets bt 
                LEFT JOIN users u ON bt.assigned_dev_id = u.id 
                LEFT JOIN projects p ON bt.project_id = p.id
                WHERE bt.id = ? AND bt.created_by = ?
            ");
            $stmt->execute([$selectedBugId, $_SESSION['user_id']]);
        } elseif ($userRole == 'developer') {
            $stmt = $pdo->prepare("
                SELECT bt.*, u.name as tester_name, p.name as project_name
                FROM bug_tickets bt 
                JOIN users u ON bt.created_by = u.id 
                LEFT JOIN projects p ON bt.project_id = p.id
                WHERE bt.id = ? AND bt.assigned_dev_id = ?
            ");
            $stmt->execute([$selectedBugId, $_SESSION['user_id']]);
        } elseif ($userRole == 'admin') {
            $stmt = $pdo->prepare("
                SELECT bt.*, u1.name as tester_name, u2.name as developer_name, p.name as project_name
                FROM bug_tickets bt 
                LEFT JOIN users u1 ON bt.created_by = u1.id 
                LEFT JOIN users u2 ON bt.assigned_dev_id = u2.id 
                LEFT JOIN projects p ON bt.project_id = p.id
                WHERE bt.id = ?
            ");
            $stmt->execute([$selectedBugId]);
        }
        $selectedBug = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Selected bug error: ' . $e->getMessage());
    }
}

// Get chat messages for selected bug
$messages = [];
if ($selectedBug) {
    try {
        $stmt = $pdo->prepare("
            SELECT br.*, u.name as user_name, u.role as user_role
            FROM bug_remarks br 
            JOIN users u ON br.user_id = u.id 
            WHERE br.bug_id = ? 
            ORDER BY br.timestamp ASC
        ");
        $stmt->execute([$selectedBugId]);
        $messages = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Messages loading error: ' . $e->getMessage());
    }
}

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message']) && $selectedBug) {
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        // Handle file upload
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['image']['name'];
            $filesize = $_FILES['image']['size'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($filetype, $allowed) && $filesize < 5000000) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $image = time() . '_' . $filename;
                move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $image);
            }
        }
        
        try {
            $currentTime = getCurrentTimestamp();
            $stmt = $pdo->prepare("INSERT INTO bug_remarks (bug_id, user_id, remark, image, timestamp) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$selectedBugId, $_SESSION['user_id'], $message, $image, $currentTime]);
            
            // Redirect to prevent form resubmission
            $redirectUrl = "chat_portal.php?bug_id=$selectedBugId&success=1";
            if (!empty($searchQuery)) {
                $redirectUrl .= "&search=" . urlencode($searchQuery);
            }
            header("Location: $redirectUrl");
            exit();
        } catch (PDOException $e) {
            $error = 'Failed to send message: ' . $e->getMessage();
            error_log('Message sending error: ' . $e->getMessage());
        }
    }
}

$success = isset($_GET['success']) ? 'Message sent successfully!' : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Portal - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
    <link rel="icon" href="logo.ico" type="image/x-icon">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS Variables - Enhanced for dark mode */
        :root {
            --primary-color: #3b82f6;
            --success-color: #10b981;
            --error-color: #ef4444;
            --warning-color: #f59e0b;
            --background-color: #f8fafc;
            --surface-color: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-radius: 8px;
            --box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        /* Dark mode variables */
        [data-theme="dark"] {
            --primary-color: #60a5fa;
            --success-color: #34d399;
            --error-color: #f87171;
            --warning-color: #fbbf24;
            --background-color: #0f172a;
            --surface-color: #1e293b;
            --border-color: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--background-color);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .app-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: 1px solid var(--border-color);
        }

        .page-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .timezone-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid var(--primary-color);
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Dark mode timezone info */
        [data-theme="dark"] .timezone-info {
            background: rgba(96, 165, 250, 0.2);
            border-color: var(--primary-color);
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-weight: 500;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-color: var(--error-color);
            color: var(--error-color);
        }

        /* Dark mode alerts */
        [data-theme="dark"] .alert-success {
            background: rgba(52, 211, 153, 0.2);
            color: #6ee7b7;
            border-color: rgba(52, 211, 153, 0.3);
        }

        [data-theme="dark"] .alert-error {
            background: rgba(248, 113, 113, 0.2);
            color: #fca5a5;
            border-color: rgba(248, 113, 113, 0.3);
        }

        .chat-portal-container {
            display: grid;
            grid-template-columns: 400px 1fr;
            height: calc(100vh - 250px);
            gap: 0;
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .bugs-sidebar {
            background: var(--background-color);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--surface-color);
        }

        .sidebar-header h3 {
            margin: 0 0 1rem 0;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .search-box {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: var(--surface-color);
            color: var(--text-primary);
            transition: border-color 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-input::placeholder {
            color: var(--text-secondary);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .bugs-list-sidebar {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            max-height: calc(100vh - 350px);
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) var(--background-color);
        }

        .bugs-list-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .bugs-list-sidebar::-webkit-scrollbar-track {
            background: var(--background-color);
        }

        .bugs-list-sidebar::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }

        .bugs-list-sidebar::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        .no-bugs-sidebar {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
            background: var(--surface-color);
            border-radius: var(--border-radius);
            margin: 1rem;
            border: 1px solid var(--border-color);
        }

        .no-bugs-sidebar a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .no-bugs-sidebar a:hover {
            text-decoration: underline;
        }

        .bug-item-sidebar {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            background: var(--surface-color);
        }

        .bug-item-sidebar:hover {
            border-color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .bug-item-sidebar.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .bug-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .bug-id {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .bug-item-sidebar.active .bug-id {
            color: white;
        }

        .bug-badges-small {
            display: flex;
            gap: 0.25rem;
        }

        .priority-badge-small,
        .status-badge-small {
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            color: white;
        }

        .bug-item-sidebar h4 {
            margin: 0 0 0.5rem 0;
            font-size: 0.9rem;
            line-height: 1.3;
            font-weight: 600;
            color: var(--text-primary);
        }

        .bug-item-sidebar.active h4 {
            color: white;
        }

        .bug-module-info {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .module-tag,
        .submodule-tag {
            padding: 0.125rem 0.5rem;
            background: var(--background-color);
            border-radius: 4px;
            font-size: 0.7rem;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .bug-item-sidebar.active .module-tag,
        .bug-item-sidebar.active .submodule-tag {
            background: rgba(255, 255, 255, 0.2);
            color: rgba(255, 255, 255, 0.9);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .bug-assignee-small {
            font-size: 0.8rem;
            margin: 0.5rem 0 0 0;
            opacity: 0.8;
            color: var(--text-secondary);
        }

        .bug-item-sidebar.active .bug-assignee-small {
            color: rgba(255, 255, 255, 0.9);
        }

        .bug-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .message-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            color: white;
        }

        .bug-item-sidebar:not(.active) .message-count {
            background: var(--primary-color);
            color: white;
        }

        .last-message-time {
            font-style: italic;
            color: var(--text-secondary);
        }

        .bug-item-sidebar.active .last-message-time {
            color: rgba(255, 255, 255, 0.8);
        }

        .chat-area {
            display: flex;
            flex-direction: column;
            height: 100%;
            background: var(--surface-color);
        }

        .no-bug-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-secondary);
            text-align: center;
            padding: 2rem;
        }

        .no-bug-selected h3 {
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .no-bug-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .chat-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--surface-color);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .selected-bug-info h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-primary);
            font-size: 1.1rem;
            line-height: 1.3;
        }

        .bug-meta-chat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .module-info {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .bug-badges {
            display: flex;
            gap: 0.5rem;
        }

        .status-badge,
        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }

        .chat-with {
            margin: 0;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .chat-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            border: 1px solid var(--primary-color);
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-outline:hover {
            background: var(--background-color);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-small {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-height: calc(100vh - 450px);
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) var(--background-color);
        }

        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: var(--background-color);
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        .no-messages {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-secondary);
            text-align: center;
            background: var(--background-color);
            border-radius: var(--border-radius);
            margin: 1rem;
            padding: 2rem;
        }

        .no-messages h4 {
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .no-messages-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .message {
            display: flex;
            flex-direction: column;
            max-width: 75%;
            animation: messageSlideIn 0.3s ease-out;
        }

        @keyframes messageSlideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.own-message {
            align-self: flex-end;
        }

        .message.other-message {
            align-self: flex-start;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .message-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .author-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .message.tester .author-avatar {
            background: var(--success-color);
        }

        .message.developer .author-avatar {
            background: var(--warning-color);
        }

        .message.admin .author-avatar {
            background: var(--error-color);
        }

        .author-name {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .author-role {
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            background: var(--primary-color);
            color: white;
        }

        .message.tester .author-role {
            background: var(--success-color);
        }

        .message.developer .author-role {
            background: var(--warning-color);
        }

        .message.admin .author-role {
            background: var(--error-color);
        }

        .message-time {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .message-content {
            background: var(--background-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            position: relative;
            color: var(--text-primary);
        }

        .message.own-message .message-content {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .message-content::before {
            content: '';
            position: absolute;
            top: -5px;
            width: 0;
            height: 0;
            border-style: solid;
        }

        .message.own-message .message-content::before {
            right: 15px;
            border-width: 0 10px 5px 10px;
            border-color: transparent transparent var(--primary-color) transparent;
        }

        .message.other-message .message-content::before {
            left: 15px;
            border-width: 0 10px 5px 10px;
            border-color: transparent transparent var(--background-color) transparent;
        }

        /* Dark mode message content arrow fix */
        [data-theme="dark"] .message.other-message .message-content::before {
            border-color: transparent transparent var(--background-color) transparent;
        }

        .message-content p {
            margin: 0;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .message-image {
            margin-top: 1rem;
        }

        .message-image img {
            max-width: 250px;
            height: auto;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .message-image img:hover {
            transform: scale(1.05);
        }

        .chat-input {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            background: var(--background-color);
        }

        .message-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .input-group {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .input-group textarea {
            flex: 1;
            padding: 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            resize: vertical;
            min-height: 80px;
            max-height: 150px;
            font-family: inherit;
            font-size: 0.9rem;
            transition: border-color 0.2s;
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .input-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .input-group textarea::placeholder {
            color: var(--text-secondary);
        }

        .input-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .file-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.8rem;
            color: var(--text-primary);
        }

        .file-upload-btn:hover {
            background: var(--background-color);
            border-color: var(--primary-color);
        }

        .file-preview {
            margin-top: 1rem;
        }

        .preview-item {
            position: relative;
            display: inline-block;
            margin-right: 1rem;
        }

        .preview-item img {
            max-width: 120px;
            height: auto;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
        }

        .preview-item div {
            color: var(--text-secondary);
        }

        .remove-preview {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--error-color);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .remove-preview:hover {
            background: #dc2626;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            animation: fadeIn 0.3s ease;
        }

        /* Dark mode modal */
        [data-theme="dark"] .modal {
            background-color: rgba(0, 0, 0, 0.9);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            position: relative;
            margin: auto;
            padding: 0;
            width: 90%;
            max-width: 800px;
            top: 50%;
            transform: translateY(-50%);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50%) scale(0.8);
                opacity: 0;
            }
            to {
                transform: translateY(-50%) scale(1);
                opacity: 1;
            }
        }

        .modal-content img {
            width: 100%;
            height: auto;
            border-radius: var(--border-radius);
        }

        .close {
            position: absolute;
            top: -40px;
            right: 0;
            color: white;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .close:hover {
            background: rgba(0, 0, 0, 0.8);
        }

        /* Search results highlighting */
        .search-highlight {
            background: var(--warning-color);
            color: white;
            padding: 0.125rem 0.25rem;
            border-radius: 3px;
        }

        /* Dark mode search highlight */
        [data-theme="dark"] .search-highlight {
            background: rgba(251, 191, 36, 0.8);
            color: var(--text-primary);
        }

        /* Enhanced scrollbar for better UX */
        .bugs-list-sidebar,
        .chat-messages {
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) var(--background-color);
        }

        /* Loading and Success States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .success-flash {
            animation: successFlash 0.5s ease;
        }

        @keyframes successFlash {
            0%, 100% { background: var(--surface-color); }
            50% { background: rgba(16, 185, 129, 0.2); }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .chat-portal-container {
                grid-template-columns: 350px 1fr;
            }
        }

        @media (max-width: 768px) {
            .chat-portal-container {
                grid-template-columns: 1fr;
                height: auto;
            }

            .bugs-sidebar {
                max-height: 400px;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }

            .chat-area {
                min-height: 500px;
            }

            .message {
                max-width: 90%;
            }

            .input-group {
                flex-direction: column;
                align-items: stretch;
            }

            .input-actions {
                flex-direction: row;
                justify-content: space-between;
            }

            .bug-meta-chat {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .app-container {
                padding: 0.5rem;
            }

            .page-header {
                padding: 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .chat-portal-container {
                height: calc(100vh - 200px);
            }

            .bugs-sidebar {
                max-height: 300px;
            }

            .close {
                width: 35px;
                height: 35px;
                font-size: 1.5rem;
                top: -35px;
            }
        }

        /* Focus states for accessibility */
        .search-input:focus,
        .input-group textarea:focus,
        .btn:focus,
        .file-upload-btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        /* Enhanced hover states */
        .bug-item-sidebar:hover {
            box-shadow: var(--box-shadow);
        }

        /* Smooth transitions */
        .bug-item-sidebar,
        .message,
        .btn,
        .file-upload-btn {
            transition: all 0.3s ease;
        }
</style>

</head>
<body>
    <?php if (file_exists('includes/navbar.php')): ?>
        <?php include 'includes/navbar.php'; ?>
    <?php endif; ?>
    
    <div class="app-container">
        <div class="page-header">
            <h1>💬 Bug Chat Portal</h1>
            <p>Search and chat about bugs with your team members</p>
        </div>

        <!-- Timezone Information -->
        <div class="timezone-info">
            🕐 <strong>Server Timezone:</strong> <?php echo date_default_timezone_get(); ?> | 
            <strong>Current Time:</strong> <?php echo date('M j, Y g:i A T'); ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                ✅ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="chat-portal-container">
            <div class="bugs-sidebar">
                <div class="sidebar-header">
                    <h3>🐛 Select Bug to Chat</h3>
                    <form method="GET" class="search-box">
                        <div style="position: relative;">
                            <span class="search-icon">🔍</span>
                            <input type="text" 
                                   name="search" 
                                   class="search-input" 
                                   placeholder="Search bugs by title, module..." 
                                   value="<?php echo htmlspecialchars($searchQuery); ?>"
                                   onchange="this.form.submit()">
                            <?php if ($selectedBugId > 0): ?>
                                <input type="hidden" name="bug_id" value="<?php echo $selectedBugId; ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <div class="bugs-list-sidebar">
                    <?php if (empty($bugs)): ?>
                        <div class="no-bugs-sidebar">
                            <p>🔍 No bugs found</p>
                            <?php if (!empty($searchQuery)): ?>
                                <p><small>Try a different search term</small></p>
                                <a href="chat_portal.php" style="color: var(--primary-color); text-decoration: none;">Clear search</a>
                            <?php else: ?>
                                <p><small>No bugs available for chat</small></p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($bugs as $bug): ?>
                            <div class="bug-item-sidebar <?php echo ($bug['id'] == $selectedBugId) ? 'active' : ''; ?>" 
                                 onclick="selectBug(<?php echo $bug['id']; ?>)">
                                <div class="bug-item-header">
                                    <span class="bug-id">#<?php echo $bug['id']; ?></span>
                                    <div class="bug-badges-small">
                                        <span class="priority-badge-small" style="background-color: <?php echo getPriorityColor($bug['priority']); ?>">
                                            <?php echo ucfirst($bug['priority']); ?>
                                        </span>
                                        <span class="status-badge-small" style="background-color: <?php echo getStatusColor($bug['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $bug['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <h4><?php echo htmlspecialchars($bug['title']); ?></h4>
                                <div class="bug-module-info">
                                    <span class="module-tag"><?php echo htmlspecialchars($bug['module']); ?></span>
                                    <span class="submodule-tag"><?php echo htmlspecialchars($bug['submodule']); ?></span>
                                </div>
                                <p class="bug-assignee-small">
                                    <?php if ($userRole == 'tester'): ?>
                                        👨‍💻 Dev: <?php echo $bug['developer_name'] ? htmlspecialchars($bug['developer_name']) : 'Unassigned'; ?>
                                    <?php elseif ($userRole == 'developer'): ?>
                                        🧪 Tester: <?php echo htmlspecialchars($bug['tester_name']); ?>
                                    <?php else: ?>
                                        🧪 <?php echo htmlspecialchars($bug['tester_name'] ?? 'Unknown'); ?> → 
                                        👨‍💻 <?php echo htmlspecialchars($bug['developer_name'] ?? 'Unassigned'); ?>
                                    <?php endif; ?>
                                </p>
                                <div class="bug-stats">
                                    <span class="message-count">💬 <?php echo $bug['message_count']; ?> messages</span>
                                    <?php if ($bug['last_message_time']): ?>
                                        <span class="last-message-time"><?php echo formatDate($bug['last_message_time']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-area">
                <?php if (!$selectedBug): ?>
                    <div class="no-bug-selected">
                        <div class="no-bug-icon">💬</div>
                        <h3>Select a Bug to Start Chatting</h3>
                        <p>Choose a bug from the sidebar to begin the conversation with your team member.</p>
                        <?php if (!empty($searchQuery)): ?>
                            <p><small>Current search: "<?php echo htmlspecialchars($searchQuery); ?>"</small></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="chat-header">
                        <div class="selected-bug-info">
                            <h3>🐛 Bug #<?php echo $selectedBug['id']; ?>: <?php echo htmlspecialchars($selectedBug['title']); ?></h3>
                            <div class="bug-meta-chat">
                                <span class="module-info">
                                    📁 <?php echo htmlspecialchars($selectedBug['module']); ?> → <?php echo htmlspecialchars($selectedBug['submodule']); ?>
                                    <?php if (!empty($selectedBug['project_name'])): ?>
                                        | 🏗️ <?php echo htmlspecialchars($selectedBug['project_name']); ?>
                                    <?php endif; ?>
                                </span>
                                <div class="bug-badges">
                                    <span class="status-badge" style="background-color: <?php echo getStatusColor($selectedBug['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $selectedBug['status'])); ?>
                                    </span>
                                    <span class="priority-badge" style="background-color: <?php echo getPriorityColor($selectedBug['priority']); ?>">
                                        <?php echo ucfirst($selectedBug['priority']); ?>
                                    </span>
                                </div>
                            </div>
                            <p class="chat-with">
                                💬 Chatting with: 
                                <?php if ($userRole == 'tester'): ?>
                                    👨‍💻 <?php echo $selectedBug['developer_name'] ? htmlspecialchars($selectedBug['developer_name']) : 'Unassigned Developer'; ?>
                                <?php elseif ($userRole == 'developer'): ?>
                                    🧪 <?php echo htmlspecialchars($selectedBug['tester_name']); ?>
                                <?php else: ?>
                                    🧪 <?php echo htmlspecialchars($selectedBug['tester_name'] ?? 'Unknown'); ?> & 
                                    👨‍💻 <?php echo htmlspecialchars($selectedBug['developer_name'] ?? 'Unassigned'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="chat-actions">
                            <a href="view_bug.php?id=<?php echo $selectedBug['id']; ?>" class="btn btn-outline btn-small">
                                <span>👁️</span> View Details
                            </a>
                        </div>
                    </div>

                    <div class="chat-messages" id="chatMessages">
                        <?php if (empty($messages)): ?>
                            <div class="no-messages">
                                <div class="no-messages-icon">💬</div>
                                <h4>Start the conversation</h4>
                                <p>No messages yet. Be the first to start discussing this bug!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message <?php echo $message['user_role']; ?> <?php echo ($message['user_id'] == $_SESSION['user_id']) ? 'own-message' : 'other-message'; ?>">
                                    <div class="message-header">
                                        <div class="message-author">
                                            <span class="author-avatar"><?php echo strtoupper(substr($message['user_name'], 0, 2)); ?></span>
                                            <span class="author-name"><?php echo htmlspecialchars($message['user_name']); ?></span>
                                            <span class="author-role"><?php echo ucfirst($message['user_role']); ?></span>
                                        </div>
                                        <span class="message-time"><?php echo formatDate($message['timestamp']); ?></span>
                                    </div>
                                    <div class="message-content">
                                        <p><?php echo nl2br(htmlspecialchars($message['remark'])); ?></p>
                                        <?php if ($message['image']): ?>
                                            <div class="message-image">
                                                <img src="uploads/<?php echo htmlspecialchars($message['image']); ?>" 
                                                     alt="Attached Image" 
                                                     onclick="openModal(this.src)"
                                                     onerror="this.style.display='none'">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="chat-input">
                        <form method="POST" enctype="multipart/form-data" class="message-form" id="messageForm">
                            <div class="input-group">
                                <textarea name="message" 
                                         placeholder="Type your message..." 
                                         rows="3" 
                                         required
                                         onkeydown="handleKeyDown(event)"></textarea>
                                <div class="input-actions">
                                    <label for="image" class="file-upload-btn">
                                        <span>📎</span> Attach Image
                                        <input type="file" id="image" name="image" accept="image/*" style="display: none;">
                                    </label>
                                    <button type="submit" class="btn btn-primary">
                                        <span>📤</span> Send
                                    </button>
                                </div>
                            </div>
                            <div id="filePreview" class="file-preview"></div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <img id="modalImage" src="" alt="Image">
        </div>
    </div>

    <script>
        function selectBug(bugId) {
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('bug_id', bugId);
            window.location.href = currentUrl.toString();
        }

        function openModal(src) {
            document.getElementById('imageModal').style.display = 'block';
            document.getElementById('modalImage').src = src;
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Enhanced file preview with better UX
        document.getElementById('image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('filePreview');
            
            if (file) {
                // Check file size (5MB limit)
                if (file.size > 5000000) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG, and GIF files are allowed');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="preview-item">
                            <img src="${e.target.result}" alt="Preview">
                            <button type="button" onclick="clearFilePreview()" class="remove-preview">×</button>
                            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-secondary);">
                                ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)
                            </div>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            } else {
                preview.innerHTML = '';
            }
        });

        function clearFilePreview() {
            document.getElementById('image').value = '';
            document.getElementById('filePreview').innerHTML = '';
        }

        // Auto-scroll to bottom of messages
        function scrollToBottom() {
            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }

        // Handle Ctrl+Enter to send message
        function handleKeyDown(event) {
            if (event.ctrlKey && event.key === 'Enter') {
                event.preventDefault();
                document.getElementById('messageForm').submit();
            }
        }

        // Auto-scroll on page load and when new messages are added
        window.addEventListener('load', function() {
            scrollToBottom();
            
            // Add smooth scrolling behavior
            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) {
                chatMessages.style.scrollBehavior = 'smooth';
            }
        });

        // Enhanced search with debouncing
        let searchTimeout;
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 500);
            });
        }

        // Add loading state to form submission
        document.getElementById('messageForm')?.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span>⏳</span> Sending...';
            submitBtn.disabled = true;
            
            // Re-enable after 3 seconds as fallback
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modal
            if (e.key === 'Escape') {
                closeModal();
            }
            
            // Focus on message input with Ctrl+/
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                const messageInput = document.querySelector('textarea[name="message"]');
                if (messageInput) {
                    messageInput.focus();
                }
            }
        });

        // Auto-refresh messages every 30 seconds if on a bug chat
        <?php if ($selectedBugId > 0): ?>
        setInterval(function() {
            // Only refresh if user hasn't typed anything recently
            const messageInput = document.querySelector('textarea[name="message"]');
            if (messageInput && messageInput.value.trim() === '') {
                const currentUrl = new URL(window.location);
                currentUrl.searchParams.set('auto_refresh', '1');
                
                fetch(currentUrl.toString())
                    .then(response => response.text())
                    .then(html => {
                        // Extract just the messages part and update
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newMessages = doc.getElementById('chatMessages');
                        const currentMessages = document.getElementById('chatMessages');
                        
                        if (newMessages && currentMessages && 
                            newMessages.innerHTML !== currentMessages.innerHTML) {
                            currentMessages.innerHTML = newMessages.innerHTML;
                            scrollToBottom();
                        }
                    })
                    .catch(error => {
                        console.log('Auto-refresh failed:', error);
                    });
            }
        }, 30000);
        <?php endif; ?>

        // Add visual feedback for successful message sending
        <?php if ($success): ?>
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>