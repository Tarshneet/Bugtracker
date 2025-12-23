<?php
require_once 'config.php';
requireAuth();

$userRole = getUserRole();
$taskId = $_GET['task_id'] ?? '';

// Get tasks and history based on user role and filters
$tasks = [];
$history = [];

try {
    if (!empty($taskId)) {
        // Show history for specific task
        $stmt = $pdo->prepare("
            SELECT dt.title, dt.assigned_to, dt.assigned_by
            FROM developer_tasks dt
            WHERE dt.id = ?
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        
        if (!$task) {
            header('Location: task_dashboard.php');
            exit;
        }
        
        // Check permissions
        $hasPermission = false;
        if ($userRole === 'admin') {
            $hasPermission = true;
        } elseif ($userRole === 'developer' && $task['assigned_to'] == $_SESSION['user_id']) {
            $hasPermission = true;
        } elseif ($userRole === 'tester' && $task['assigned_by'] == $_SESSION['user_id']) {
            $hasPermission = true;
        }
        
        if (!$hasPermission) {
            header('Location: task_dashboard.php');
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT th.*, u.name as changed_by_name, dt.title as task_title
            FROM task_history th
            LEFT JOIN users u ON th.changed_by = u.id
            LEFT JOIN developer_tasks dt ON th.task_id = dt.id
            WHERE th.task_id = ?
            ORDER BY th.created_at DESC
        ");
        $stmt->execute([$taskId]);
        $history = $stmt->fetchAll();
    } else {
        // Show all completed tasks and their history
        $whereClause = '';
        $params = [];
        
        if ($userRole === 'developer') {
            $whereClause = 'WHERE dt.assigned_to = ?';
            $params[] = $_SESSION['user_id'];
        } elseif ($userRole === 'tester') {
            $whereClause = 'WHERE dt.assigned_by = ?';
            $params[] = $_SESSION['user_id'];
        }
        
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u1.name as developer_name,
                   u2.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u1 ON dt.assigned_to = u1.id
            LEFT JOIN users u2 ON dt.assigned_by = u2.id
            LEFT JOIN projects p ON dt.project_id = p.id
            $whereClause
            ORDER BY dt.updated_at DESC
        ");
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = "Error loading data: " . $e->getMessage();
}

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
        'completed' => '#10b981',
        'overdue' => '#ef4444'
    ];
    return $colors[$status] ?? '#6b7280';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task History - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
   <style>
        /* Dark Mode CSS Variables - Added for theme compatibility */
        :root {
            --surface-color: #ffffff;
            --border-color: #e5e7eb;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-tertiary: #9ca3af;
            --background-color: #ffffff;
            --accent-color: #f8fafc;
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            --box-shadow-lg: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        [data-theme="dark"] {
            --surface-color: #1e293b;
            --border-color: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-tertiary: #94a3b8;
            --background-color: #0f172a;
            --accent-color: #374151;
            --primary-color: #60a5fa;
            --primary-hover: #3b82f6;
            --success-color: #34d399;
            --warning-color: #fbbf24;
            --error-color: #f87171;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            --box-shadow-lg: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        /* Apply dark mode to existing styles */
        .history-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .task-summary-card {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .task-card {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid var(--border-color);
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .task-card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .task-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .task-card-meta {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .task-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }

        .task-card-body {
            padding: 1.5rem;
        }

        .task-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .task-stat {
            text-align: center;
            padding: 0.75rem;
            background: var(--accent-color);
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        .task-stat-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .task-stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .history-timeline {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .history-timeline h3 {
            color: var(--text-primary);
        }

        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .timeline-item:last-child {
            border-bottom: none;
        }

        .timeline-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 20px;
            top: 60px;
            bottom: -1rem;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-action {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .timeline-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .timeline-time {
            color: var(--text-tertiary);
            font-size: 0.75rem;
        }

        .filter-section {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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

        .filter-group select {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.875rem;
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .filter-group select option {
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            border: 1px solid var(--border-color);
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        /* Enhanced Button Styles with dark mode */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--box-shadow);
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-outline {
            background-color: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-outline:hover {
            background-color: var(--accent-color);
            border-color: var(--text-secondary);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .btn span {
            font-size: 1rem;
        }

        /* Alert styles */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-color: var(--error-color);
        }

        [data-theme="dark"] .alert-error {
            background: rgba(248, 113, 113, 0.2);
            color: #fca5a5;
            border-color: rgba(248, 113, 113, 0.3);
        }

        /* Dashboard header */
        .dashboard-header {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
        }

        .dashboard-header h1 {
            color: var(--text-primary);
        }

        .dashboard-header p {
            color: var(--text-secondary);
        }

        /* Main layout */
        .main-content {
            background: var(--background-color);
        }

        .app-container {
            background: var(--background-color);
        }

        /* Task card developer info */
        .task-card-header div[style*="color: #6b7280"] {
            color: var(--text-secondary) !important;
        }

        /* Smooth transitions for theme switching */
        *, *::before, *::after {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        @media (max-width: 768px) {
            .task-grid {
                grid-template-columns: 1fr;
            }
            
            .task-stats {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
</style>

</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="app-container">
        <main class="main-content">
            <div class="history-container">
                <div class="dashboard-header">
                    <h1>
                        <?php if (!empty($taskId)): ?>
                            Task History - <?php echo htmlspecialchars($task['title']); ?>
                        <?php else: ?>
                            Task History & Completed Tasks
                        <?php endif; ?>
                    </h1>
                    <p>
                        <?php if (!empty($taskId)): ?>
                            Complete activity log for this task
                        <?php else: ?>
                            View all task history and completed tasks
                        <?php endif; ?>
                    </p>
                </div>

                <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
                    <a href="task_dashboard.php" class="btn btn-outline">
                        <span>←</span> Back to Dashboard
                    </a>
                    <?php if (!empty($taskId)): ?>
                        <a href="task_details.php?id=<?php echo $taskId; ?>" class="btn btn-outline">
                            <span>👁️</span> View Task Details
                        </a>
                        <a href="task_history.php" class="btn btn-outline">
                            <span>📋</span> All Tasks History
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($taskId) && !empty($history)): ?>
                    <!-- Single Task History Timeline -->
                    <div class="history-timeline">
                        <h3 style="margin-bottom: 1.5rem;">📋 Complete Activity Timeline</h3>
                        <?php foreach ($history as $item): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <?php
                                    switch ($item['action']) {
                                        case 'created':
                                            echo '➕';
                                            break;
                                        case 'status_changed':
                                            echo '🔄';
                                            break;
                                        case 'updated':
                                            echo '✏️';
                                            break;
                                        default:
                                            echo '📝';
                                    }
                                    ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-action">
                                        <?php
                                        switch ($item['action']) {
                                            case 'created':
                                                echo 'Task Created';
                                                break;
                                            case 'status_changed':
                                                echo 'Status Changed';
                                                break;
                                            case 'updated':
                                                echo 'Task Updated';
                                                break;
                                            default:
                                                echo ucfirst(str_replace('_', ' ', $item['action']));
                                        }
                                        ?>
                                    </div>
                                    <?php if ($item['old_value'] && $item['new_value']): ?>
                                        <div class="timeline-details">
                                            From: <?php echo htmlspecialchars($item['old_value']); ?><br>
                                            To: <?php echo htmlspecialchars($item['new_value']); ?>
                                        </div>
                                    <?php elseif ($item['new_value']): ?>
                                        <div class="timeline-details">
                                            <?php echo htmlspecialchars($item['new_value']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($item['change_reason']): ?>
                                        <div class="timeline-details">
                                            <strong>Reason:</strong> <?php echo htmlspecialchars($item['change_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="timeline-time">
                                        <?php echo date('M j, Y g:i A', strtotime($item['created_at'])); ?> 
                                        by <?php echo htmlspecialchars($item['changed_by_name']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php elseif (empty($taskId)): ?>
                    <!-- All Tasks Overview -->
                    <?php if (empty($tasks)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <h3>No task history found</h3>
                            <p>No tasks have been created yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="task-grid">
                            <?php foreach ($tasks as $task): ?>
                                <div class="task-card">
                                    <div class="task-card-header">
                                        <h3 class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></h3>
                                        <div class="task-card-meta">
                                            <span class="task-badge" style="background-color: <?php echo getPriorityColor($task['priority']); ?>">
                                                <?php echo ucfirst($task['priority']); ?>
                                            </span>
                                            <span class="task-badge" style="background-color: <?php echo getStatusColor($task['status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                            </span>
                                        </div>
                                        <div style="margin-top: 0.5rem; color: #6b7280; font-size: 0.875rem;">
                                            Developer: <?php echo htmlspecialchars($task['developer_name']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="task-card-body">
                                        <div class="task-stats">
                                            <div class="task-stat">
                                                <div class="task-stat-value"><?php echo date('M j', strtotime($task['created_at'])); ?></div>
                                                <div class="task-stat-label">Created</div>
                                            </div>
                                            <div class="task-stat">
                                                <div class="task-stat-value"><?php echo date('M j', strtotime($task['deadline'])); ?></div>
                                                <div class="task-stat-label">Deadline</div>
                                            </div>
                                            <?php if ($task['estimated_hours']): ?>
                                                <div class="task-stat">
                                                    <div class="task-stat-value"><?php echo $task['estimated_hours']; ?>h</div>
                                                    <div class="task-stat-label">Estimated</div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($task['actual_hours']): ?>
                                                <div class="task-stat">
                                                    <div class="task-stat-value"><?php echo $task['actual_hours']; ?>h</div>
                                                    <div class="task-stat-label">Actual</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                            <a href="task_details.php?id=<?php echo $task['id']; ?>" class="btn btn-outline btn-small">
                                                <span>👁️</span> Details
                                            </a>
                                            <a href="task_history.php?task_id=<?php echo $task['id']; ?>" class="btn btn-primary btn-small">
                                                <span>📋</span> History
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <h3>No history found</h3>
                        <p>No activity history found for this task.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>