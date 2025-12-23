<?php
require_once 'config.php';
requireAuth();
date_default_timezone_set('Asia/Kolkata');

// Define the base URL for uploads (adjust the domain and path based on your server)
$baseUrl = './uploads/'; // e.g., http://yourdomain.com/uploads/

// Helper functions
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

function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('M j, Y g:i A', strtotime($datetime));
}

// Handle AJAX request for getting bug details
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_bug_detail'])) {
    header('Content-Type: application/json');
    
    $bugId = $_GET['bug_id'] ?? '';
    $userRole = getUserRole();
    $userId = $_SESSION['user_id'];

    if (empty($bugId)) {
        echo json_encode(['success' => false, 'message' => 'Missing bug ID']);
        exit;
    }

    try {
        // Get bug details with project and user information
        $stmt = $pdo->prepare("
            SELECT bt.*, 
                   COALESCE(p.name, 'No Project') as project_name,
                   COALESCE(u1.name, 'Unknown') as reporter_name,
                   COALESCE(u2.name, 'Unassigned') as developer_name
            FROM bug_tickets bt 
            LEFT JOIN projects p ON bt.project_id = p.id
            LEFT JOIN users u1 ON bt.created_by = u1.id 
            LEFT JOIN users u2 ON bt.assigned_dev_id = u2.id 
            WHERE bt.id = ?
        ");
        $stmt->execute([$bugId]);
        $bug = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bug) {
            echo json_encode(['success' => false, 'message' => 'Bug not found']);
            exit;
        }

        // Check permission
        $hasPermission = false;
        if ($userRole === 'admin') {
            $hasPermission = true;
        } elseif ($userRole === 'tester' && $bug['created_by'] == $userId) {
            $hasPermission = true;
        } elseif ($userRole === 'developer' && $bug['assigned_dev_id'] == $userId) {
            $hasPermission = true;
        }

        if (!$hasPermission) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        // Get attachments - check if table exists first
        $attachments = [];
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'bug_attachments'");
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT * FROM bug_attachments WHERE bug_id = ? ORDER BY created_at DESC");
                $stmt->execute([$bugId]);
                $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log('Attachments query error: ' . $e->getMessage());
        }

        // Ensure all required fields exist with defaults and adjust screenshot path
        $bug['module'] = $bug['module'] ?? 'N/A';
        $bug['submodule'] = $bug['submodule'] ?? 'N/A';
        $bug['visible_impact'] = $bug['visible_impact'] ?? 'N/A';
        $bug['priority'] = $bug['priority'] ?? 'medium';
        $bug['status'] = $bug['status'] ?? 'pending';
        $bug['screenshot'] = !empty($bug['screenshot']) && file_exists(__DIR__ . '/uploads/' . $bug['screenshot'])
            ? $baseUrl . $bug['screenshot'] 
            : '';

        echo json_encode([
            'success' => true,
            'bug' => $bug,
            'attachments' => $attachments
        ]);

    } catch (PDOException $e) {
        error_log('Database error in bug detail: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for updating bug status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    
    $bugId = $_POST['bug_id'] ?? '';
    $newStatus = $_POST['new_status'] ?? '';
    $userRole = getUserRole();
    $userId = $_SESSION['user_id'];

    // Add debug logging
    error_log("Status update request - Bug ID: $bugId, New Status: $newStatus, User Role: $userRole, User ID: $userId");

    // Validate input
    if (empty($bugId) || empty($newStatus)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }

    // Validate status values
    $validStatuses = ['pending', 'in_progress', 'fixed', 'approved', 'rejected'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    try {
        // Get current bug details
        $stmt = $pdo->prepare("SELECT * FROM bug_tickets WHERE id = ?");
        $stmt->execute([$bugId]);
        $bug = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bug) {
            echo json_encode(['success' => false, 'message' => 'Bug not found']);
            exit;
        }

        $currentStatus = $bug['status'];
        error_log("Current status: $currentStatus, New status: $newStatus");

        // Check if user has permission to update this bug
        $hasPermission = false;
        if ($userRole === 'admin') {
            $hasPermission = true;
        } elseif ($userRole === 'tester') {
            // Tester can approve/reject their own bugs when they are in 'fixed' status
            $hasPermission = ($bug['created_by'] == $userId && $currentStatus === 'fixed' && 
                             in_array($newStatus, ['approved', 'rejected']));
        } elseif ($userRole === 'developer') {
            // Developer can move bugs assigned to them through the development workflow
            $hasPermission = ($bug['assigned_dev_id'] == $userId && 
                             (($currentStatus === 'pending' && $newStatus === 'in_progress') ||
                              ($currentStatus === 'in_progress' && $newStatus === 'fixed') ||
                              ($currentStatus === 'rejected' && $newStatus === 'in_progress')));
        }

        if (!$hasPermission) {
            error_log("Permission denied for user $userId (role: $userRole) to update bug $bugId from $currentStatus to $newStatus");
            echo json_encode(['success' => false, 'message' => 'You do not have permission to update this bug status']);
            exit;
        }

        // Validate status transition
        $validTransitions = [
            'pending' => ['in_progress'],
            'in_progress' => ['fixed'],
            'fixed' => ['approved', 'rejected'],
            'approved' => [], // No transitions from approved
            'rejected' => ['in_progress']
        ];

        if (!isset($validTransitions[$currentStatus]) || !in_array($newStatus, $validTransitions[$currentStatus])) {
            error_log("Invalid status transition from $currentStatus to $newStatus");
            echo json_encode(['success' => false, 'message' => 'Invalid status transition from ' . $currentStatus . ' to ' . $newStatus]);
            exit;
        }

        // Begin transaction
        $pdo->beginTransaction();

        try {
            // Update the bug status
            $stmt = $pdo->prepare("UPDATE bug_tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$newStatus, $bugId]);

            if (!$result) {
                throw new Exception('Failed to update bug status');
            }

            // Log the status change - using correct column name 'timestamp'
            try {
                $stmt = $pdo->prepare("SHOW TABLES LIKE 'bug_status_logs'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("INSERT INTO bug_status_logs (bug_id, status, updated_by, timestamp) VALUES (?, ?, ?, NOW())");
                    $logResult = $stmt->execute([$bugId, $newStatus, $userId]);
                    if (!$logResult) {
                        error_log('Failed to log status change for bug ' . $bugId);
                    }
                }
            } catch (PDOException $e) {
                error_log('Failed to log status change: ' . $e->getMessage());
                // Don't fail the transaction for logging errors
            }

            // Commit transaction
            $pdo->commit();

            echo json_encode([
                'success' => true, 
                'message' => 'Bug status updated successfully',
                'bug_id' => $bugId,
                'new_status' => $newStatus,
                'old_status' => $currentStatus
            ]);

        } catch (Exception $e) {
            $pdo->rollback();
            error_log('Transaction failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update bug status: ' . $e->getMessage()]);
        }

    } catch (PDOException $e) {
        error_log('Database error in status update: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()]);
    }
    exit;
}

$userRole = getUserRole();

// Get filter parameters
$priorityFilter = $_GET['priority'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$projectFilter = $_GET['project'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$titlePrefixToggle = $_GET['title_prefix'] ?? '0'; // 0 = off (Bug), 1 = on (Change)

// Build WHERE clause for filters
$whereConditions = [];
$params = [];

// Base condition for user role
if ($userRole == 'tester') {
    $whereConditions[] = "bt.created_by = ?";
    $params[] = $_SESSION['user_id'];
} elseif ($userRole == 'developer') {
    $whereConditions[] = "bt.assigned_dev_id = ?";
    $params[] = $_SESSION['user_id'];
}

// Apply title prefix filter
if ($titlePrefixToggle == '1') {
    // When toggle is ON - filter titles starting with "Change"
    $whereConditions[] = "bt.title LIKE ?";
    $params[] = 'Change%';
} else {
    // When toggle is OFF (default) - filter titles starting with "Bug"
    $whereConditions[] = "bt.title LIKE ?";
    $params[] = 'Bug%';
}

// Apply other filters
if (!empty($priorityFilter)) {
    $whereConditions[] = "bt.priority = ?";
    $params[] = $priorityFilter;
}

if (!empty($statusFilter)) {
    $whereConditions[] = "bt.status = ?";
    $params[] = $statusFilter;
}

if (!empty($projectFilter)) {
    $whereConditions[] = "bt.project_id = ?";
    $params[] = $projectFilter;
}

if (!empty($searchFilter)) {
    $whereConditions[] = "(bt.title LIKE ? OR bt.description LIKE ?)";
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
}

if (!empty($dateFilter)) {
    $dateCondition = '';
    switch ($dateFilter) {
        case 'today':
            $dateCondition = "DATE(bt.created_at) = CURDATE()";
            break;
        case 'week':
            $dateCondition = "bt.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;
        case 'month':
            $dateCondition = "bt.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
        case '3months':
            $dateCondition = "bt.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
            break;
    }
    if ($dateCondition) {
        $whereConditions[] = $dateCondition;
    }
}

// Get all bugs for the user based on role with filters
$bugs = [];
try {
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    if ($userRole == 'tester') {
        $sql = "SELECT bt.*, COALESCE(u.name, 'Unassigned') as developer_name, COALESCE(p.name, 'No Project') as project_name 
                FROM bug_tickets bt 
                LEFT JOIN users u ON bt.assigned_dev_id = u.id 
                LEFT JOIN projects p ON bt.project_id = p.id 
                $whereClause 
                ORDER BY bt.created_at DESC";
    } elseif ($userRole == 'developer') {
        $sql = "SELECT bt.*, COALESCE(u.name, 'Unknown') as tester_name, COALESCE(p.name, 'No Project') as project_name 
                FROM bug_tickets bt 
                LEFT JOIN users u ON bt.created_by = u.id 
                LEFT JOIN projects p ON bt.project_id = p.id 
                $whereClause 
                ORDER BY bt.created_at DESC";
    } elseif ($userRole == 'admin') {
        $sql = "SELECT bt.*, COALESCE(u.name, 'Unknown') as tester_name, COALESCE(u2.name, 'Unassigned') as developer_name, COALESCE(p.name, 'No Project') as project_name 
                FROM bug_tickets bt 
                LEFT JOIN users u ON bt.created_by = u.id 
                LEFT JOIN users u2 ON bt.assigned_dev_id = u2.id 
                LEFT JOIN projects p ON bt.project_id = p.id 
                $whereClause 
                ORDER BY bt.created_at DESC";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Main query error: ' . $e->getMessage());
    die('Database error: ' . $e->getMessage());
}

// Get filter options
try {
    // Get unique priorities
    $stmt = $pdo->query("SELECT DISTINCT priority FROM bug_tickets WHERE priority IS NOT NULL ORDER BY priority");
    $priorities = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get projects from projects table
    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log('Filter options error: ' . $e->getMessage());
    $priorities = [];
    $projects = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bug Status Shift - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
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
        /* CSS Variables for Light and Dark Modes */
        :root {
            /* Light mode colors */
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --background-color: #f8fafc;
            --surface-color: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --hover-color: #f1f5f9;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            --box-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        /* Dark mode colors */
        [data-theme="dark"] {
            --primary-color: #60a5fa;
            --primary-hover: #3b82f6;
            --secondary-color: #94a3b8;
            --success-color: #34d399;
            --warning-color: #fbbf24;
            --error-color: #f87171;
            --background-color: #0f172a;
            --surface-color: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --border-color: #334155;
            --hover-color: #374151;
            --shadow-color: rgba(0, 0, 0, 0.3);
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            --box-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* Smooth transitions for theme switching */
        *, *::before, *::after {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .app-container {
            max-width: 1350px;
            width: 100%;
            margin: 0 auto;
            padding: 1rem;
        }

        .main-content {
            padding: 1rem;
            background: var(--background-color);
            border-radius: var(--border-radius);
        }

        /* Enhanced Dashboard Header */
        .dashboard-header {
            background: var(--surface-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .dashboard-header h1 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 2rem;
            font-weight: 700;
        }

        .dashboard-header p {
            color: var(--text-secondary);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        /* Filter Styles with Dark Mode */
        .filter-section {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.875rem;
            background: var(--surface-color);
            color: var(--text-primary);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-group input::placeholder {
            color: var(--text-secondary);
        }

        .filter-group select option {
            background: var(--surface-color);
            color: var(--text-primary);
        }

        /* Enhanced Toggle Switch Styles */
        .toggle-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .toggle-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .toggle-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .toggle-switch {
            position: relative;
            width: 60px;
            height: 32px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--border-color);
            transition: 0.3s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 4px;
            bottom: 4px;
            background-color: var(--surface-color);
            transition: 0.3s;
            border-radius: 50%;
            box-shadow: var(--box-shadow);
        }

        input:checked + .toggle-slider {
            background-color: var(--primary-color);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(28px);
        }

        .toggle-text {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
            min-width: 80px;
        }

        .toggle-text.active {
            color: var(--primary-color);
            font-weight: 600;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .clear-filters-btn {
            padding: 0.5rem 1rem;
            background: var(--error-color);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .clear-filters-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: var(--box-shadow);
        }

        .filter-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }

        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            background: var(--primary-color);
            color: white;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            box-shadow: var(--box-shadow);
        }

        .filter-tag .remove-filter {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            padding: 0;
            margin-left: 0.25rem;
            transition: transform 0.2s ease;
        }

        .filter-tag .remove-filter:hover {
            transform: scale(1.2);
        }

        .results-summary {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Enhanced Role Indicator */
        .role-indicator {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
            display: inline-block;
            font-weight: 600;
            box-shadow: var(--box-shadow);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Enhanced Status Columns */
        .status-columns {
            display: flex;
            flex-direction: row;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: nowrap;
        }

        .status-box {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            flex: 1;
            min-width: 250px;
            max-width: 300px;
            min-height: 300px;
            position: relative;
            transition: all 0.2s ease;
            border: 2px solid var(--border-color);
            overflow-y: auto;
            max-height: calc(100vh - 200px);
        }

        .status-box.dragover {
            border-color: var(--primary-color);
            background-color: var(--hover-color);
            transform: scale(1.02);
            box-shadow: var(--box-shadow-lg);
        }

        .status-box h3 {
            position: sticky;
            top: 0;
            background: var(--surface-color);
            z-index: 5;
            margin: -1.5rem -1.5rem 1rem -1.5rem;
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
            color: var(--text-primary);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }

        .count {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: normal;
        }

        /* Enhanced Bug Cards */
        .bug-card {
            background: var(--surface-color);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            width: 100%;
            max-width: 280px;
            height: auto;
            min-height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .bug-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
            border-color: var(--primary-color);
        }

        .bug-card.dragging {
            opacity: 0.6;
            transform: rotate(5deg) scale(0.95);
            z-index: 1000;
            box-shadow: var(--box-shadow-lg);
        }

        .bug-card.moving {
            transition: all 0.3s ease;
            opacity: 0.7;
        }

        .bug-header {
            margin-bottom: 0.5rem;
            pointer-events: none;
        }

        .bug-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            line-height: 1.3;
            pointer-events: none;
        }

        .bug-id {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            pointer-events: none;
            font-weight: 500;
        }

        .bug-meta {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.5rem;
            pointer-events: none;
        }

        .priority-badge, .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            color: white;
            white-space: nowrap;
            font-weight: 500;
            pointer-events: none;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .bug-description {
            margin: 0.5rem 0;
            color: var(--text-secondary);
            font-size: 0.8rem;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            line-height: 1.4;
            flex-grow: 1;
            pointer-events: none;
        }

        .bug-footer {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--text-secondary);
            flex-shrink: 0;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--border-color);
            pointer-events: none;
        }

        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            background: var(--hover-color);
            border-radius: var(--border-radius);
            border: 2px dashed var(--border-color);
            color: var(--text-secondary);
            font-style: italic;
        }

        .empty-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        /* Enhanced Role-based Status Box Styling */
        .status-box[data-status="pending"] h3 { color: #6b7280; }
        .status-box[data-status="in_progress"] h3 { color: var(--primary-color); }
        .status-box[data-status="fixed"] h3 { color: var(--warning-color); }
        .status-box[data-status="approved"] h3 { color: var(--success-color); }
        .status-box[data-status="rejected"] h3 { color: var(--error-color); }

        /* Enhanced Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            backdrop-filter: blur(4px);
        }

        /* Dark mode modal overlay */
        [data-theme="dark"] .modal-overlay {
            background: rgba(0, 0, 0, 0.8);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: linear-gradient(145deg, var(--surface-color), var(--hover-color));
            border-radius: 16px;
            max-width: 900px;
            width: 95%;
            max-height: 95vh;
            overflow-y: auto;
            position: relative;
            transform: scale(0.9);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: var(--box-shadow-lg);
            border: 1px solid var(--border-color);
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
            box-shadow: 0 12px 32px var(--shadow-color);
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            background: linear-gradient(to bottom, var(--surface-color), var(--hover-color));
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-close {
            position: relative;
            background: var(--hover-color);
            border: 1px solid var(--border-color);
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--text-primary);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: var(--error-color);
            color: white;
            transform: rotate(90deg);
            border-color: var(--error-color);
        }

        .bug-detail-header {
            flex: 1;
            margin-right: 1rem;
        }

        .bug-detail-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            line-height: 1.2;
        }

        .bug-detail-id {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .bug-detail-badges {
            display: flex;
            gap: 0.75rem;
            flex-shrink: 0;
        }

        .modal-body {
            padding: 2rem;
            background: var(--surface-color);
        }

        .bug-detail-description {
            background: var(--hover-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-primary);
            box-shadow: inset 0 2px 4px var(--shadow-color);
        }

        .bug-detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--text-primary);
            font-weight: 500;
            background: var(--hover-color);
            padding: 0.5rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        .detail-value.project {
            color: var(--success-color);
            font-weight: 600;
        }

        .attachments-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
        }

        .attachment-item {
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--surface-color);
        }

        .attachment-item:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: var(--box-shadow);
        }

        .attachment-preview {
            width: 100%;
            height: 120px;
            object-fit: cover;
            background: var(--hover-color);
        }

        .attachment-info {
            padding: 0.75rem;
            background: var(--surface-color);
        }

        .attachment-name {
            font-size: 0.8rem;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            word-break: break-word;
        }

        .attachment-link {
            font-size: 0.75rem;
            color: var(--primary-color);
            text-decoration: none;
        }

        .attachment-link:hover {
            text-decoration: underline;
        }

        /* Bug Image Section */
        .bug-image-section {
            margin-top: 2rem;
        }

        .bug-image-container {
            position: relative;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            max-height: 400px;
            border: 1px solid var(--border-color);
        }

        .bug-image {
            width: 100%;
            height: auto;
            max-height: 400px;
            object-fit: contain;
            display: block;
        }

        .bug-image-placeholder {
            background: var(--hover-color);
            padding: 2rem;
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
            border-radius: var(--border-radius);
        }

        /* Enhanced Image Viewer Modal */
        .image-viewer-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .image-viewer-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .image-viewer-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }

        .image-viewer-content img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: var(--border-radius);
        }

        .image-viewer-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .image-viewer-close:hover {
            background: var(--error-color);
            transform: scale(1.1);
        }

        /* Enhanced Loading and Success States */
        .bug-card.updating {
            pointer-events: none;
            opacity: 0.7;
            border-color: var(--warning-color);
        }

        .bug-card.updating::after {
            content: '⏳';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            z-index: 10;
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .bug-card.success {
            animation: successPulse 0.5s ease-in-out;
            border-color: var(--success-color);
        }

        @keyframes successPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        /* Enhanced Scrollbars */
        .status-box::-webkit-scrollbar,
        .modal-content::-webkit-scrollbar {
            width: 8px;
        }

        .status-box::-webkit-scrollbar-track,
        .modal-content::-webkit-scrollbar-track {
            background: var(--hover-color);
            border-radius: 4px;
        }

        .status-box::-webkit-scrollbar-thumb,
        .modal-content::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }

        .status-box::-webkit-scrollbar-thumb:hover,
        .modal-content::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* Enhanced Responsive Design */
        @media (max-width: 1350px) {
            .status-columns {
                flex-wrap: wrap;
            }
            .status-box {
                flex: 1 1 200px;
                min-width: 200px;
                max-height: calc(50vh - 100px);
            }
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .status-columns {
                flex-direction: column;
                gap: 1rem;
            }
            
            .status-box {
                min-width: 100%;
                max-height: calc(100vh - 200px);
            }
            
            .bug-card {
                max-width: 100%;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .modal-header,
            .modal-body {
                padding: 1rem;
            }
            
            .bug-detail-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                padding: 1.5rem;
            }

            .dashboard-header h1 {
                font-size: 1.5rem;
            }
        }

        /* Focus states for accessibility */
        .filter-group select:focus,
        .filter-group input:focus,
        .clear-filters-btn:focus,
        .toggle-switch:focus-within {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        /* Enhanced notification animations */
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .notification {
            animation: slideIn 0.3s ease-out;
        }

        /* Dark mode specific adjustments */
        [data-theme="dark"] .status-box::-webkit-scrollbar-track,
        [data-theme="dark"] .modal-content::-webkit-scrollbar-track {
            background: var(--background-color);
        }

        [data-theme="dark"] .toggle-slider:before {
            background-color: white;
        }

        /* Print styles */
        @media print {
            .modal-overlay,
            .image-viewer-overlay {
                display: none !important;
            }
        }
</style>

</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <div class="app-container">
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Bug Status Management</h1>
                <p>Drag and drop bugs to change their status or click to view details</p>
                <div class="role-indicator">
                    Role: <?php echo ucfirst($userRole); ?>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="priority">Priority</label>
                            <select name="priority" id="priority" onchange="document.getElementById('filterForm').submit()">
                                <option value="">All Priorities</option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo htmlspecialchars($priority); ?>" 
                                            <?php echo $priorityFilter === $priority ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(htmlspecialchars($priority)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" onchange="document.getElementById('filterForm').submit()">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="fixed" <?php echo $statusFilter === 'fixed' ? 'selected' : ''; ?>>Fixed</option>
                                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="project">Project</label>
                            <select name="project" id="project" onchange="document.getElementById('filterForm').submit()">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $projectFilter == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="date">Date Range</label>
                            <select name="date" id="date" onchange="document.getElementById('filterForm').submit()">
                                <option value="">All Time</option>
                                <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo $dateFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="3months" <?php echo $dateFilter === '3months' ? 'selected' : ''; ?>>Last 3 Months</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" placeholder="Search bugs..." 
                                   value="<?php echo htmlspecialchars($searchFilter); ?>">
                        </div>

                        <!-- Title Prefix Toggle -->
                        <div class="toggle-group">
                            <label class="toggle-label">Title Filter</label>
                            <div class="toggle-container">
                                <span class="toggle-text <?php echo $titlePrefixToggle == '0' ? 'active' : ''; ?>">Bug</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="title_prefix" value="1" id="titlePrefixToggle" 
                                           <?php echo $titlePrefixToggle == '1' ? 'checked' : ''; ?> 
                                           onchange="document.getElementById('filterForm').submit()">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="toggle-text <?php echo $titlePrefixToggle == '1' ? 'active' : ''; ?>">Change</span>
                            </div>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="button" class="clear-filters-btn" onclick="clearAllFilters()">Clear All Filters</button>
                        <div class="filter-tags">
                            <?php if (!empty($priorityFilter)): ?>
                                <span class="filter-tag">
                                    Priority: <?php echo ucfirst(htmlspecialchars($priorityFilter)); ?>
                                    <button type="button" class="remove-filter" onclick="removeFilter('priority')">×</button>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($statusFilter)): ?>
                                <span class="filter-tag">
                                    Status: <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($statusFilter))); ?>
                                    <button type="button" class="remove-filter" onclick="removeFilter('status')">×</button>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($projectFilter)): ?>
                                <?php
                                $selectedProject = array_filter($projects, function($p) use ($projectFilter) {
                                    return $p['id'] == $projectFilter;
                                });
                                $selectedProject = reset($selectedProject);
                                ?>
                                <span class="filter-tag">
                                    Project: <?php echo htmlspecialchars($selectedProject['name'] ?? 'Unknown'); ?>
                                    <button type="button" class="remove-filter" onclick="removeFilter('project')">×</button>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($dateFilter)): ?>
                                <span class="filter-tag">
                                    Date: <?php echo ucfirst(htmlspecialchars($dateFilter)); ?>
                                    <button type="button" class="remove-filter" onclick="removeFilter('date')">×</button>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($searchFilter)): ?>
                                <span class="filter-tag">
                                    Search: "<?php echo htmlspecialchars($searchFilter); ?>"
                                    <button type="button" class="remove-filter" onclick="removeFilter('search')">×</button>
                                </span>
                            <?php endif; ?>
                            <?php if ($titlePrefixToggle == '1'): ?>
                                <span class="filter-tag">
                                    Title: Change
                                    <button type="button" class="remove-filter" onclick="removeFilter('title_prefix')">×</button>
                                </span>
                            <?php else: ?>
                                <span class="filter-tag">
                                    Title: Bug
                                    <button type="button" class="remove-filter" onclick="removeFilter('title_prefix')">×</button>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="results-summary">
                        Showing <?php echo count($bugs); ?> bug(s) 
                        <?php if ($titlePrefixToggle == '1'): ?>
                            starting with "Change"
                        <?php else: ?>
                            starting with "Bug"
                        <?php endif; ?>
                        <?php if (!empty($priorityFilter) || !empty($statusFilter) || !empty($projectFilter) || !empty($dateFilter) || !empty($searchFilter)): ?>
                            matching your additional filters
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="status-columns">
                <?php
                $statusLabels = [
                    'pending' => 'Pending',
                    'in_progress' => 'In Progress',
                    'fixed' => 'Fixed',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected'
                ];
                
                $bugsByStatus = [];
                foreach ($bugs as $bug) {
                    $bugsByStatus[$bug['status']][] = $bug;
                }

                foreach ($statusLabels as $status => $label) {
                    $bugCount = isset($bugsByStatus[$status]) ? count($bugsByStatus[$status]) : 0;
                    echo '<div class="status-box" data-status="' . $status . '">';
                    echo '<h3>' . htmlspecialchars($label) . ' <span class="count">(' . $bugCount . ')</span></h3>';
                    
                    if (isset($bugsByStatus[$status]) && count($bugsByStatus[$status]) > 0) {
                        foreach ($bugsByStatus[$status] as $bug) {
                            echo '<div class="bug-card" data-bug-id="' . $bug['id'] . '" data-current-status="' . $bug['status'] . '" draggable="true">';
                            echo '<div class="bug-header">';
                            echo '<div class="bug-id">#' . $bug['id'] . '</div>';
                            echo '<div class="bug-title">' . htmlspecialchars($bug['title']) . '</div>';
                            echo '<div class="bug-meta">';
                            echo '<span class="priority-badge" style="background-color: ' . getPriorityColor($bug['priority']) . '">';
                            echo $bug['priority'] . '</span>';
                            if (!empty($bug['project_name'])) {
                                echo '<span class="status-badge" style="background-color: #6b7280;">';
                                echo htmlspecialchars($bug['project_name']) . '</span>';
                            }
                            echo '</div>';
                            echo '</div>';
                            echo '<p class="bug-description">' . htmlspecialchars(substr($bug['description'], 0, 120));
                            if (strlen($bug['description']) > 120) echo '...';
                            echo '</p>';
                            echo '<div class="bug-footer">';
                            echo '<span class="bug-date">' . formatDate($bug['created_at']) . '</span>';
                            if ($userRole == 'tester' && isset($bug['developer_name'])) {
                                echo '<span class="bug-assignee">Dev: ' . htmlspecialchars($bug['developer_name']) . '</span>';
                            } elseif ($userRole == 'developer' && isset($bug['tester_name'])) {
                                echo '<span class="bug-assignee">By: ' . htmlspecialchars($bug['tester_name']) . '</span>';
                            }
                            echo '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="empty-state">';
                        echo '<div class="empty-icon">🐛</div>';
                        echo '<p>No bugs in this state</p>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </main>
    </div>

    <!-- Bug Detail Modal -->
    <div id="bugDetailModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div class="bug-detail-header">
                    <div>
                        <div class="bug-detail-id" id="modalBugId"></div>
                        <h2 class="bug-detail-title" id="modalBugTitle"></h2>
                    </div>
                    <div class="bug-detail-badges" id="modalBugBadges"></div>
                </div>
                <button class="modal-close" onclick="closeBugDetailModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="bug-detail-description" id="modalBugDescription"></div>
                
                <div class="bug-detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Module</div>
                        <div class="detail-value" id="modalBugModule"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Submodule</div>
                        <div class="detail-value" id="modalBugSubmodule"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Project</div>
                        <div class="detail-value project" id="modalBugProject"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Visible Impact</div>
                        <div class="detail-value" id="modalBugImpact"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Reported by</div>
                        <div class="detail-value" id="modalBugReporter"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Assigned to</div>
                        <div class="detail-value" id="modalBugAssignee"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created</div>
                        <div class="detail-value" id="modalBugCreated"></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Updated</div>
                        <div class="detail-value" id="modalBugUpdated"></div>
                    </div>
                </div>

                <div class="attachments-section" id="attachmentsSection" style="display: none;">
                    <h3 class="section-title">📎 Screenshots attached</h3>
                    <div class="attachments-grid" id="attachmentsGrid"></div>
                </div>

                <div class="bug-image-section" id="bugImageSection" style="display: block;">
                    <h3 class="section-title">📷 Bug Screenshot</h3>
                    <div class="bug-image-container" id="bugImageContainer"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Viewer Modal -->
    <div id="imageViewerModal" class="image-viewer-overlay">
        <div class="image-viewer-content">
            <button class="image-viewer-close" onclick="closeImageViewer()">×</button>
            <img id="imageViewerImg" src="" alt="Full size image">
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const bugCards = document.querySelectorAll('.bug-card');
            const statusBoxes = document.querySelectorAll('.status-box');
            const userRole = '<?php echo $userRole; ?>';
            
            let draggedElement = null;
            let draggedBugId = null;
            let isDragging = false;
            let dragStartTime = 0;

            console.log('Initializing drag and drop for', bugCards.length, 'bug cards');
            console.log('User role:', userRole);

            // Initialize drag and click functionality for bug cards
            bugCards.forEach(card => {
                card.addEventListener('mousedown', (e) => {
                    dragStartTime = Date.now();
                    isDragging = false;
                });

                card.addEventListener('click', (e) => {
                    const clickDuration = Date.now() - dragStartTime;
                    if (clickDuration < 200 && !isDragging) {
                        e.preventDefault();
                        e.stopPropagation();
                        const bugId = card.getAttribute('data-bug-id');
                        openBugDetailModal(bugId);
                    }
                });

                card.addEventListener('dragstart', (e) => {
                    isDragging = true;
                    draggedElement = card;
                    draggedBugId = card.getAttribute('data-bug-id');
                    e.dataTransfer.setData('text/plain', draggedBugId);
                    e.dataTransfer.effectAllowed = 'move';
                    card.classList.add('dragging');
                    console.log('Drag started for bug ID:', draggedBugId);
                });

                card.addEventListener('dragend', (e) => {
                    card.classList.remove('dragging');
                    draggedElement = null;
                    draggedBugId = null;
                    setTimeout(() => {
                        isDragging = false;
                    }, 100);
                    console.log('Drag ended');
                });
            });

            statusBoxes.forEach(box => {
                box.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                });

                box.addEventListener('dragenter', (e) => {
                    e.preventDefault();
                    if (draggedElement) {
                        box.classList.add('dragover');
                    }
                });

                box.addEventListener('dragleave', (e) => {
                    if (!box.contains(e.relatedTarget)) {
                        box.classList.remove('dragover');
                    }
                });

                box.addEventListener('drop', (e) => {
                    e.preventDefault();
                    box.classList.remove('dragover');

                    const bugId = e.dataTransfer.getData('text/plain');
                    const newStatus = box.getAttribute('data-status');
                    const card = draggedElement;
                    
                    if (!card || !bugId || !newStatus) {
                        console.log('Invalid drop - missing data');
                        return;
                    }

                    const currentStatus = card.getAttribute('data-current-status');
                    
                    if (currentStatus === newStatus) {
                        console.log('Same status, ignoring drop');
                        return;
                    }

                    console.log('Attempting to move bug', bugId, 'from', currentStatus, 'to', newStatus);

                    if (!isValidTransition(userRole, currentStatus, newStatus)) {
                        alert('You are not authorized to move this bug from "' + formatStatus(currentStatus) + '" to "' + formatStatus(newStatus) + '"');
                        return;
                    }

                    card.classList.add('updating');

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax_update=1&bug_id=${encodeURIComponent(bugId)}&new_status=${encodeURIComponent(newStatus)}`
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Update response:', data);
                        card.classList.remove('updating');
                        
                        if (data.success) {
                            // Move the card to the new status box
                            box.appendChild(card);
                            card.setAttribute('data-current-status', newStatus);
                            
                            // Show success animation
                            card.classList.add('success');
                            setTimeout(() => card.classList.remove('success'), 500);
                            
                            // Update status counts
                            updateStatusCounts();
                            
                            console.log('Bug moved successfully to:', newStatus);
                            
                            // Optional: Show success message
                            showNotification('Bug status updated successfully!', 'success');
                        } else {
                            console.error('Failed to update:', data.message);
                            alert('Failed to update bug status: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        card.classList.remove('updating');
                        alert('An error occurred while updating the bug status. Please try again.');
                    });
                });
            });

            // Search functionality with debounce
            let searchTimeout;
            document.getElementById('search').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 500);
            });

            // Transition validation function
            function isValidTransition(userRole, currentStatus, newStatus) {
                const transitions = {
                    'tester': {
                        'fixed': ['approved', 'rejected']
                    },
                    'developer': {
                        'pending': ['in_progress'],
                        'in_progress': ['fixed'],
                        'rejected': ['in_progress']
                    },
                    'admin': {
                        'pending': ['in_progress', 'rejected'],
                        'in_progress': ['fixed', 'pending'],
                        'fixed': ['approved', 'rejected', 'in_progress'],
                        'approved': ['rejected'],
                        'rejected': ['in_progress', 'pending']
                    }
                };

                if (!transitions[userRole] || !transitions[userRole][currentStatus]) {
                    return false;
                }

                return transitions[userRole][currentStatus].includes(newStatus);
            }

            function formatStatus(status) {
                return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            }

            function updateStatusCounts() {
                statusBoxes.forEach(box => {
                    const count = box.querySelectorAll('.bug-card').length;
                    const header = box.querySelector('h3');
                    const countSpan = header.querySelector('.count');
                    if (countSpan) {
                        countSpan.textContent = `(${count})`;
                    }
                });
            }

            function showNotification(message, type = 'info') {
                // Create notification element
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;
                notification.textContent = message;
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 1rem 1.5rem;
                    border-radius: 8px;
                    color: white;
                    font-weight: 500;
                    z-index: 9999;
                    background: ${type === 'success' ? '#10b981' : '#3b82f6'};
                    animation: slideIn 0.3s ease-out;
                `;

                document.body.appendChild(notification);

                // Remove after 3 seconds
                setTimeout(() => {
                    notification.remove();
                }, 3000);
            }

            // Add CSS for notification animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(style);
        });

        function clearAllFilters() {
            window.location.href = window.location.pathname;
        }

        function removeFilter(filterName) {
            const url = new URL(window.location);
            url.searchParams.delete(filterName);
            window.location.href = url.toString();
        }

        function openBugDetailModal(bugId) {
            console.log('Opening modal for bug ID:', bugId);
            
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('ajax_bug_detail', '1');
            currentUrl.searchParams.set('bug_id', bugId);
            
            fetch(currentUrl.toString())
                .then(response => {
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        populateBugDetailModal(data);
                        document.getElementById('bugDetailModal').classList.add('active');
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('Error loading bug details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load bug details: ' + error.message);
                });
        }

        function populateBugDetailModal(data) {
            const bug = data.bug;
            console.log('Populating modal with bug data:', bug);
            console.log('Screenshot URL:', bug.screenshot);
            
            document.getElementById('modalBugId').textContent = `#${bug.id}`;
            document.getElementById('modalBugTitle').textContent = bug.title || 'No Title';
            document.getElementById('modalBugDescription').textContent = bug.description || 'No description available';
            
            const badgesContainer = document.getElementById('modalBugBadges');
            badgesContainer.innerHTML = `
                <span class="priority-badge" style="background-color: ${getPriorityColor(bug.priority)}">${bug.priority}</span>
                <span class="status-badge" style="background-color: ${getStatusColor(bug.status)}">${formatStatus(bug.status)}</span>
            `;
            
            document.getElementById('modalBugModule').textContent = bug.module || 'N/A';
            document.getElementById('modalBugSubmodule').textContent = bug.submodule || 'N/A';
            document.getElementById('modalBugProject').textContent = bug.project_name || 'N/A';
            document.getElementById('modalBugImpact').textContent = bug.visible_impact || 'N/A';
            document.getElementById('modalBugReporter').textContent = bug.reporter_name || 'N/A';
            document.getElementById('modalBugAssignee').textContent = bug.developer_name || 'Unassigned';
            document.getElementById('modalBugCreated').textContent = formatDateTime(bug.created_at);
            document.getElementById('modalBugUpdated').textContent = formatDateTime(bug.updated_at);
            
            const attachmentsSection = document.getElementById('attachmentsSection');
            const attachmentsGrid = document.getElementById('attachmentsGrid');
            
            if (data.attachments && data.attachments.length > 0) {
                attachmentsSection.style.display = 'block';
                attachmentsGrid.innerHTML = '';
                
                data.attachments.forEach(attachment => {
                    const attachmentDiv = document.createElement('div');
                    attachmentDiv.className = 'attachment-item';
                    attachmentDiv.onclick = () => openImageViewer(attachment.file_path);
                    
                    attachmentDiv.innerHTML = `
                        <img src="${attachment.file_path}" alt="${attachment.original_name}" class="attachment-preview" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTkgMTJMMTEgMTRMMTUgMTBNMjEgMTJDMjEgMTYuOTcwNiAxNi45NzA2IDIxIDEyIDIxQzcuMDI5NDQgMjEgMyAxNi45NzA2IDMgMTJDMyA3LjAyOTQ0IDcuMDI5NDQgMyAxMiAzQzE2Ljk3MDYgMyAyMSA3LjAyOTQ0IDIxIDEyWiIgc3Ryb2tlPSIjNjU2NTY1IiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIvPgo8L3N2Zz4K'">
                        <div class="attachment-info">
                            <div class="attachment-name">${attachment.original_name}</div>
                            <a href="#" class="attachment-link">Click to view full size</a>
                        </div>
                    `;
                    attachmentsGrid.appendChild(attachmentDiv);
                });
            } else {
                attachmentsSection.style.display = 'none';
            }
            
            const bugImageSection = document.getElementById('bugImageSection');
            const bugImageContainer = document.getElementById('bugImageContainer');
            
            if (bug.screenshot) {
                bugImageContainer.innerHTML = `
                    <img src="${bug.screenshot}" alt="Bug Screenshot" class="bug-image" onclick="openImageViewer('${bug.screenshot}')" 
                         onerror="this.parentElement.innerHTML='<div class=\\\"bug-image-placeholder\\\">No valid image available</div>'">
                `;
            } else {
                bugImageContainer.innerHTML = '<div class="bug-image-placeholder">No screenshot available</div>';
            }
        }

        function closeBugDetailModal() {
            document.getElementById('bugDetailModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function openImageViewer(imageSrc) {
            document.getElementById('imageViewerImg').src = imageSrc;
            document.getElementById('imageViewerModal').classList.add('active');
        }

        function closeImageViewer() {
            document.getElementById('imageViewerModal').classList.remove('active');
        }

        function getPriorityColor(priority) {
            const colors = {
                'low': '#10b981',
                'medium': '#f59e0b',
                'high': '#ef4444',
                'critical': '#7c2d12'
            };
            return colors[priority] || '#6b7280';
        }

        function getStatusColor(status) {
            const colors = {
                'pending': '#6b7280',
                'in_progress': '#3b82f6',
                'fixed': '#f59e0b',
                'approved': '#10b981',
                'rejected': '#ef4444'
            };
            return colors[status] || '#6b7280';
        }

        function formatStatus(status) {
            return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        function formatDateTime(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeBugDetailModal();
                closeImageViewer();
            }
        });

        // Modal click-to-close
        document.getElementById('bugDetailModal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                closeBugDetailModal();
            }
        });

        document.getElementById('imageViewerModal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                closeImageViewer();
            }
        });
    </script>
</body>
</html>