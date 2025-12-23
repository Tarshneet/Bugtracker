<?php
require_once 'config.php';
requireAuth();

$userRole = getUserRole();

// Handle task completion (for developers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_task'])) {
    $taskId = $_POST['task_id'];
    $completionNotes = trim($_POST['completion_notes']);
    $actualHours = !empty($_POST['actual_hours']) ? $_POST['actual_hours'] : null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE developer_tasks 
            SET status = 'completed', completed_at = NOW(), completion_notes = ?, actual_hours = ?, updated_at = NOW()
            WHERE id = ? AND assigned_to = ?
        ");
        $result = $stmt->execute([$completionNotes, $actualHours, $taskId, $_SESSION['user_id']]);
        
        if ($result) {
            // Log the completion
            $stmt = $pdo->prepare("
                INSERT INTO task_history (task_id, action, old_value, new_value, changed_by) 
                VALUES (?, 'status_changed', 'pending/in_progress', 'completed', ?)
            ");
            $stmt->execute([$taskId, $_SESSION['user_id']]);
            
            $success = "Task marked as completed successfully!";
        }
    } catch (PDOException $e) {
        $error = "Error completing task: " . $e->getMessage();
    }
}

// Handle CSV download (for testers)
if (isset($_GET['download_csv']) && ($userRole === 'tester' || $userRole === 'admin')) {
    try {
        $whereClause = '';
        $params = [];
        
        if ($userRole === 'tester') {
            $whereClause = 'WHERE dt.assigned_by = ?';
            $params[] = $_SESSION['user_id'];
        }
        
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u1.name as developer_name, u1.email as developer_email,
                   u2.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u1 ON dt.assigned_to = u1.id
            LEFT JOIN users u2 ON dt.assigned_by = u2.id
            LEFT JOIN projects p ON dt.project_id = p.id
            $whereClause
            ORDER BY dt.created_at DESC
        ");
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="developer_tasks_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Task ID', 'Title', 'Description', 'Developer', 'Developer Email', 'Assigned By',
            'Project', 'Priority', 'Status', 'Deadline', 'Estimated Hours', 'Actual Hours',
            'Created Date', 'Completed Date', 'Completion Notes'
        ]);
        
        // CSV data
        foreach ($tasks as $task) {
            fputcsv($output, [
                $task['id'],
                $task['title'],
                $task['description'],
                $task['developer_name'],
                $task['developer_email'],
                $task['assigned_by_name'],
                $task['project_name'],
                ucfirst($task['priority']),
                ucfirst(str_replace('_', ' ', $task['status'])),
                $task['deadline'],
                $task['estimated_hours'],
                $task['actual_hours'],
                $task['created_at'],
                $task['completed_at'],
                $task['completion_notes']
            ]);
        }
        
        fclose($output);
        exit;
    } catch (PDOException $e) {
        $error = "Error generating CSV: " . $e->getMessage();
    }
}

// Get active tasks based on user role
$activeTasks = [];
try {
    if ($userRole === 'developer') {
        // Show only current (non-completed) tasks for developer
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u ON dt.assigned_by = u.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.assigned_to = ? AND dt.status != 'completed'
            ORDER BY dt.deadline ASC, dt.priority DESC
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($userRole === 'tester') {
        // Show tasks assigned by this tester
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u1.name as developer_name,
                   u2.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u1 ON dt.assigned_to = u1.id
            LEFT JOIN users u2 ON dt.assigned_by = u2.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.assigned_by = ? AND dt.status != 'completed'
            ORDER BY dt.deadline ASC, dt.priority DESC
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($userRole === 'admin') {
        // Show all current tasks for admin
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u1.name as developer_name,
                   u2.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u1 ON dt.assigned_to = u1.id
            LEFT JOIN users u2 ON dt.assigned_by = u2.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.status != 'completed'
            ORDER BY dt.deadline ASC, dt.priority DESC
        ");
        $stmt->execute();
    }
    $activeTasks = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error loading tasks: " . $e->getMessage();
}

// Get recently completed tasks
$completedTasks = [];
try {
    if ($userRole === 'developer') {
        // Show recently completed tasks for developer
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u ON dt.assigned_by = u.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.assigned_to = ? AND dt.status = 'completed'
            ORDER BY dt.completed_at DESC
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($userRole === 'tester') {
        // Show recently completed tasks assigned by this tester
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u1.name as developer_name,
                   u2.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u1 ON dt.assigned_to = u1.id
            LEFT JOIN users u2 ON dt.assigned_by = u2.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.assigned_by = ? AND dt.status = 'completed'
            ORDER BY dt.completed_at DESC
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($userRole === 'admin') {
        // Show all recently completed tasks for admin
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u1.name as developer_name,
                   u2.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u1 ON dt.assigned_to = u1.id
            LEFT JOIN users u2 ON dt.assigned_by = u2.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.status = 'completed'
            ORDER BY dt.completed_at DESC
            LIMIT 5
        ");
        $stmt->execute();
    }
    $completedTasks = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error loading completed tasks: " . $e->getMessage();
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

function getTimeRemaining($deadline) {
    $now = new DateTime();
    $deadlineDate = new DateTime($deadline);
    $diff = $now->diff($deadlineDate);
    
    if ($deadlineDate < $now) {
        return ['overdue' => true, 'text' => 'Overdue by ' . $diff->format('%a days, %h hours')];
    } else {
        return ['overdue' => false, 'text' => $diff->format('%a days, %h hours remaining')];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Dashboard - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
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
            --background-color: #ffffff;
            --hover-color: #f3f4f6;
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --success-color: #10b981;
            --error-color: #ef4444;
            --warning-color: #f59e0b;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            --box-shadow-lg: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        [data-theme="dark"] {
            --surface-color: #1e293b;
            --border-color: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --background-color: #0f172a;
            --hover-color: #374151;
            --primary-color: #60a5fa;
            --primary-hover: #3b82f6;
            --success-color: #34d399;
            --error-color: #f87171;
            --warning-color: #fbbf24;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            --box-shadow-lg: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        /* Apply dark mode to existing styles */
        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
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

        .task-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .task-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .task-meta {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .task-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }

        .task-body {
            padding: 1.5rem;
        }

        .task-description {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .task-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .task-detail {
            font-size: 0.8rem;
        }

        .task-detail-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .task-detail-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        .time-indicator {
            padding: 0.5rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            text-align: center;
            margin-bottom: 1rem;
        }

        .time-good {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .time-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }

        .time-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: 1px solid var(--error-color);
        }

        /* Dark mode time indicators */
        [data-theme="dark"] .time-good {
            background: rgba(52, 211, 153, 0.2);
            color: #6ee7b7;
            border-color: rgba(52, 211, 153, 0.3);
        }

        [data-theme="dark"] .time-warning {
            background: rgba(251, 191, 36, 0.2);
            color: #fcd34d;
            border-color: rgba(251, 191, 36, 0.3);
        }

        [data-theme="dark"] .time-danger {
            background: rgba(248, 113, 113, 0.2);
            color: #fca5a5;
            border-color: rgba(248, 113, 113, 0.3);
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
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
            background-color: var(--hover-color);
            border-color: var(--text-secondary);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .btn span {
            font-size: 1rem;
        }

        .completion-form {
            background: var(--hover-color);
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .completion-form textarea {
            width: 100%;
            min-height: 80px;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 0.5rem;
            resize: vertical;
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .completion-form textarea::placeholder {
            color: var(--text-secondary);
        }

        .completion-form input[type="number"] {
            width: 100px;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-right: 0.5rem;
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .completion-form label {
            color: var(--text-secondary);
        }

        .dashboard-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .section-header {
            margin-top: 3rem;
            margin-bottom: 1.5rem;
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .section-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
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

        /* Alert styles */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border-color: var(--success-color);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-color: var(--error-color);
        }

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

        /* Main content */
        .main-content {
            background: var(--background-color);
        }

        .app-container {
            background: var(--background-color);
        }

        /* Smooth transitions for theme switching */
        *, *::before, *::after {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        @media (max-width: 768px) {
            .task-grid {
                grid-template-columns: 1fr;
            }
            
            .task-details {
                grid-template-columns: 1fr;
            }
            
            .dashboard-actions {
                flex-direction: column;
            }
        }
</style>

</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="app-container">
        <main class="main-content">
            <div class="dashboard-header">
                <h1>
                    <?php if ($userRole === 'developer'): ?>
                        My Tasks
                    <?php elseif ($userRole === 'tester'): ?>
                        Assigned Tasks Dashboard
                    <?php else: ?>
                        All Tasks Dashboard
                    <?php endif; ?>
                </h1>
                <p>
                    <?php if ($userRole === 'developer'): ?>
                        View and manage your assigned tasks with deadlines
                    <?php else: ?>
                        Monitor and manage developer task assignments
                    <?php endif; ?>
                </p>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="dashboard-actions">
                <?php if ($userRole === 'tester' || $userRole === 'admin'): ?>
                    <a href="task_assignment.php" class="btn btn-primary">
                        <span>➕</span> Assign New Task
                    </a>
                    <a href="?download_csv=1" class="btn btn-outline">
                        <span>📊</span> Download CSV Report
                    </a>
                    <a href="task_history.php" class="btn btn-outline">
                        <span>📋</span> View Task History
                    </a>
                <?php endif; ?>
                <?php if ($userRole === 'developer'): ?>
                    <a href="task_history.php" class="btn btn-outline">
                        <span>📋</span> View My Task History
                    </a>
                <?php endif; ?>
            </div>

            <!-- Active Tasks Section -->
            <div class="section-header">
                <h2>Active Tasks</h2>
                <p>
                    <?php if ($userRole === 'developer'): ?>
                        Your current assigned tasks
                    <?php else: ?>
                        Current tasks assigned to developers
                    <?php endif; ?>
                </p>
            </div>

            <?php if (empty($activeTasks)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>No active tasks</h3>
                    <p>
                        <?php if ($userRole === 'developer'): ?>
                            You don't have any active tasks assigned to you.
                        <?php else: ?>
                            No active tasks found. Assign new tasks to developers to get started.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="task-grid">
                    <?php foreach ($activeTasks as $task): ?>
                        <?php 
                        $timeRemaining = getTimeRemaining($task['deadline']);
                        $isOverdue = $timeRemaining['overdue'];
                        
                        // Determine time indicator class
                        $timeClass = 'time-good';
                        if ($isOverdue) {
                            $timeClass = 'time-danger';
                        } else {
                            $deadline = new DateTime($task['deadline']);
                            $now = new DateTime();
                            $hoursRemaining = ($deadline->getTimestamp() - $now->getTimestamp()) / 3600;
                            
                            if ($hoursRemaining <= 24) {
                                $timeClass = 'time-danger';
                            } elseif ($hoursRemaining <= 72) {
                                $timeClass = 'time-warning';
                            }
                        }
                        ?>
                        <div class="task-card">
                            <div class="task-header">
                                <h3 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h3>
                                <div class="task-meta">
                                    <span class="task-badge" style="background-color: <?php echo getPriorityColor($task['priority']); ?>">
                                        <?php echo ucfirst($task['priority']); ?> Priority
                                    </span>
                                    <span class="task-badge" style="background-color: <?php echo getStatusColor($task['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                </div>
                                <?php if ($userRole !== 'developer'): ?>
                                    <div style="margin-top: 0.5rem; color: #6b7280; font-size: 0.875rem;">
                                        Developer: <?php echo htmlspecialchars($task['developer_name'] ?? 'Unknown'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="task-body">
                                <div class="time-indicator <?php echo $timeClass; ?>">
                                    <?php if ($isOverdue): ?>
                                        ⚠️ <?php echo $timeRemaining['text']; ?>
                                    <?php else: ?>
                                        ⏰ <?php echo $timeRemaining['text']; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="task-description">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 150)); ?>
                                    <?php if (strlen($task['description']) > 150): ?>...<?php endif; ?>
                                </p>
                                
                                <div class="task-details">
                                    <div class="task-detail">
                                        <div class="task-detail-label">Created</div>
                                        <div class="task-detail-value">
                                            <?php echo date('M j, Y', strtotime($task['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="task-detail">
                                        <div class="task-detail-label">Deadline</div>
                                        <div class="task-detail-value">
                                            <?php echo date('M j, Y g:i A', strtotime($task['deadline'])); ?>
                                        </div>
                                    </div>
                                    <?php if ($userRole === 'developer'): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Assigned by</div>
                                            <div class="task-detail-value">
                                                <?php echo htmlspecialchars($task['assigned_by_name'] ?? 'Unknown'); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($task['project_name']): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Project</div>
                                            <div class="task-detail-value">
                                                <?php echo htmlspecialchars($task['project_name']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($task['estimated_hours']): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Estimated Hours</div>
                                            <div class="task-detail-value">
                                                <?php echo $task['estimated_hours']; ?> hours
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="task-actions">
                                    <a href="task_details.php?id=<?php echo $task['id']; ?>" class="btn btn-outline btn-small">
                                        <span>👁️</span> View Details
                                    </a>
                                    
                                    <?php if ($userRole === 'developer' && $task['status'] !== 'completed'): ?>
                                        <button type="button" class="btn btn-primary btn-small" onclick="toggleCompletionForm(<?php echo $task['id']; ?>)">
                                            <span>✅</span> Complete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($userRole === 'developer' && $task['status'] !== 'completed'): ?>
                                <div id="completion-form-<?php echo $task['id']; ?>" class="completion-form" style="display: none;">
                                    <form method="POST" action="">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <textarea name="completion_notes" placeholder="Add completion notes (optional)..."></textarea>
                                        <div style="display: flex; align-items: center; justify-content: space-between;">
                                            <div>
                                                <label for="actual_hours_<?php echo $task['id']; ?>" style="font-size: 0.8rem; color: #6b7280;">Actual hours spent:</label>
                                                <input type="number" id="actual_hours_<?php echo $task['id']; ?>" name="actual_hours" 
                                                       min="0.5" max="999" step="0.5" placeholder="8.0">
                                            </div>
                                            <div>
                                                <button type="button" class="btn btn-outline btn-small" onclick="toggleCompletionForm(<?php echo $task['id']; ?>)">Cancel</button>
                                                <button type="submit" name="complete_task" class="btn btn-primary btn-small">Mark Complete</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Recently Completed Tasks Section -->
            <div class="section-header">
                <h2>Recently Completed Tasks</h2>
                <p>
                    <?php if ($userRole === 'developer'): ?>
                        Your recently completed tasks
                    <?php else: ?>
                        Recently completed tasks by developers
                    <?php endif; ?>
                </p>
            </div>

            <?php if (empty($completedTasks)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <h3>No recently completed tasks</h3>
                    <p>
                        <?php if ($userRole === 'developer'): ?>
                            You haven't completed any tasks recently.
                        <?php else: ?>
                            No tasks have been completed recently.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="task-grid">
                    <?php foreach ($completedTasks as $task): ?>
                        <div class="task-card">
                            <div class="task-header">
                                <h3 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h3>
                                <div class="task-meta">
                                    <span class="task-badge" style="background-color: <?php echo getPriorityColor($task['priority']); ?>">
                                        <?php echo ucfirst($task['priority']); ?> Priority
                                    </span>
                                    <span class="task-badge" style="background-color: <?php echo getStatusColor($task['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                </div>
                                <?php if ($userRole !== 'developer'): ?>
                                    <div style="margin-top: 0.5rem; color: #6b7280; font-size: 0.875rem;">
                                        Developer: <?php echo htmlspecialchars($task['developer_name'] ?? 'Unknown'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="task-body">
                                <p class="task-description">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 150)); ?>
                                    <?php if (strlen($task['description']) > 150): ?>...<?php endif; ?>
                                </p>
                                
                                <div class="task-details">
                                    <div class="task-detail">
                                        <div class="task-detail-label">Created</div>
                                        <div class="task-detail-value">
                                            <?php echo date('M j, Y', strtotime($task['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="task-detail">
                                        <div class="task-detail-label">Completed</div>
                                        <div class="task-detail-value">
                                            <?php echo date('M j, Y g:i A', strtotime($task['completed_at'])); ?>
                                        </div>
                                    </div>
                                    <?php if ($userRole === 'developer'): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Assigned by</div>
                                            <div class="task-detail-value">
                                                <?php echo htmlspecialchars($task['assigned_by_name'] ?? 'Unknown'); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($task['project_name']): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Project</div>
                                            <div class="task-detail-value">
                                                <?php echo htmlspecialchars($task['project_name']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($task['actual_hours']): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Actual Hours</div>
                                            <div class="task-detail-value">
                                                <?php echo $task['actual_hours']; ?> hours
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="task-actions">
                                    <a href="task_details.php?id=<?php echo $task['id']; ?>" class="btn btn-outline btn-small">
                                        <span>👁️</span> View Details
                                    </a>
                                    <a href="task_history.php?task_id=<?php echo $task['id']; ?>" class="btn btn-outline btn-small">
                                        <span>📋</span> History
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleCompletionForm(taskId) {
            const form = document.getElementById('completion-form-' + taskId);
            if (form.style.display === 'none') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }

        // Auto-update time indicators every minute
        setInterval(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>