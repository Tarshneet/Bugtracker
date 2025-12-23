<?php
require_once 'config.php';
requireAuth();

$userRole = getUserRole();
$taskId = $_GET['id'] ?? '';

if (empty($taskId)) {
    header('Location: task_dashboard.php');
    exit;
}

// Get task details
$task = null;
try {
    $stmt = $pdo->prepare("
        SELECT dt.*, 
               u1.name as developer_name, u1.email as developer_email,
               u2.name as assigned_by_name, u2.email as assigned_by_email,
               p.name as project_name
        FROM developer_tasks dt
        LEFT JOIN users u1 ON dt.assigned_to = u1.id
        LEFT JOIN users u2 ON dt.assigned_by = u2.id
        LEFT JOIN projects p ON dt.project_id = p.id
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
    
} catch (PDOException $e) {
    $error = "Error loading task: " . $e->getMessage();
}

// Get task history
$history = [];
try {
    $stmt = $pdo->prepare("
        SELECT th.*, u.name as changed_by_name
        FROM task_history th
        LEFT JOIN users u ON th.changed_by = u.id
        WHERE th.task_id = ?
        ORDER BY th.created_at DESC
    ");
    $stmt->execute([$taskId]);
    $history = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error silently
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
    <title>Task Details - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
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
            --border-color: #f3f4f6;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-tertiary: #9ca3af;
            --background-color: #ffffff;
            --accent-color: #f8fafc;
            --primary-color: #3b82f6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
            --success-color: #34d399;
            --warning-color: #fbbf24;
            --error-color: #f87171;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        /* Apply dark mode to existing styles */
        .task-detail-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .task-header-card {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .task-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .task-badges {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .task-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            color: white;
        }

        .time-status {
            padding: 1rem;
            border-radius: 8px;
            font-weight: 500;
            text-align: center;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }

        .time-good {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border-color: var(--success-color);
        }

        .time-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
            border-color: var(--warning-color);
        }

        .time-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-color: var(--error-color);
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

        .task-description {
            background: var(--accent-color);
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            margin-bottom: 2rem;
            line-height: 1.6;
            color: var(--text-primary);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .detail-card {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .detail-card h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        .history-card {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .history-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-icon {
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
        }

        .history-content {
            flex: 1;
        }

        .history-action {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .history-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .history-time {
            color: var(--text-tertiary);
            font-size: 0.75rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
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

        /* Main layout */
        .main-content {
            background: var(--background-color);
        }

        .app-container {
            background: var(--background-color);
        }

        /* Completion notes styling */
        .detail-card div[style*="background: #f8fafc"] {
            background: var(--accent-color) !important;
            color: var(--text-primary);
        }

        /* Smooth transitions for theme switching */
        *, *::before, *::after {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
</style>

</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="app-container">
        <main class="main-content">
            <div class="task-detail-container">
                <div class="action-buttons">
                    <a href="task_dashboard.php" class="btn btn-outline">
                        <span>←</span> Back to Dashboard
                    </a>
                    <?php if ($userRole === 'tester' || $userRole === 'admin'): ?>
                        <a href="task_history.php?task_id=<?php echo $task['id']; ?>" class="btn btn-outline">
                            <span>📋</span> Full History
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($task): ?>
                    <div class="task-header-card">
                        <h1 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h1>
                        
                        <div class="task-badges">
                            <span class="task-badge" style="background-color: <?php echo getPriorityColor($task['priority']); ?>">
                                <?php echo ucfirst($task['priority']); ?> Priority
                            </span>
                            <span class="task-badge" style="background-color: <?php echo getStatusColor($task['status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                            </span>
                        </div>

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
                        
                        <div class="time-status <?php echo $timeClass; ?>">
                            <?php if ($isOverdue): ?>
                                ⚠️ <?php echo $timeRemaining['text']; ?>
                            <?php else: ?>
                                ⏰ <?php echo $timeRemaining['text']; ?>
                            <?php endif; ?>
                        </div>

                        <div class="task-description">
                            <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                        </div>
                    </div>

                    <div class="detail-grid">
                        <div class="detail-card">
                            <h3>📋 Task Information</h3>
                            <div class="detail-item">
                                <span class="detail-label">Task ID</span>
                                <span class="detail-value">#<?php echo $task['id']; ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Priority</span>
                                <span class="detail-value"><?php echo ucfirst($task['priority']); ?></span>
                            </div>
                            <?php if ($task['project_name']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Project</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($task['project_name']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="detail-card">
                            <h3>👥 People</h3>
                            <div class="detail-item">
                                <span class="detail-label">Assigned to</span>
                                <span class="detail-value"><?php echo htmlspecialchars($task['developer_name']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Developer Email</span>
                                <span class="detail-value"><?php echo htmlspecialchars($task['developer_email']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Assigned by</span>
                                <span class="detail-value"><?php echo htmlspecialchars($task['assigned_by_name']); ?></span>
                            </div>
                        </div>

                        <div class="detail-card">
                            <h3>⏰ Timeline</h3>
                            <div class="detail-item">
                                <span class="detail-label">Created</span>
                                <span class="detail-value"><?php echo date('M j, Y g:i A', strtotime($task['created_at'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Deadline</span>
                                <span class="detail-value"><?php echo date('M j, Y g:i A', strtotime($task['deadline'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Last Updated</span>
                                <span class="detail-value"><?php echo date('M j, Y g:i A', strtotime($task['updated_at'])); ?></span>
                            </div>
                            <?php if ($task['completed_at']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Completed</span>
                                    <span class="detail-value"><?php echo date('M j, Y g:i A', strtotime($task['completed_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="detail-card">
                            <h3>📊 Effort Tracking</h3>
                            <?php if ($task['estimated_hours']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Estimated Hours</span>
                                    <span class="detail-value"><?php echo $task['estimated_hours']; ?> hours</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($task['actual_hours']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Actual Hours</span>
                                    <span class="detail-value"><?php echo $task['actual_hours']; ?> hours</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($task['estimated_hours'] && $task['actual_hours']): ?>
                                <?php 
                                $variance = $task['actual_hours'] - $task['estimated_hours'];
                                $variancePercent = round(($variance / $task['estimated_hours']) * 100);
                                ?>
                                <div class="detail-item">
                                    <span class="detail-label">Variance</span>
                                    <span class="detail-value" style="color: <?php echo $variance > 0 ? '#ef4444' : '#10b981'; ?>">
                                        <?php echo ($variance > 0 ? '+' : '') . $variance; ?> hours (<?php echo ($variancePercent > 0 ? '+' : '') . $variancePercent; ?>%)
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($task['completion_notes']): ?>
                        <div class="detail-card" style="margin-bottom: 2rem;">
                            <h3>📝 Completion Notes</h3>
                            <div style="padding: 1rem; background: #f8fafc; border-radius: 6px; margin-top: 1rem;">
                                <?php echo nl2br(htmlspecialchars($task['completion_notes'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($history)): ?>
                        <div class="history-card">
                            <h3>📋 Recent Activity</h3>
                            <?php foreach (array_slice($history, 0, 5) as $item): ?>
                                <div class="history-item">
                                    <div class="history-icon">
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
                                    <div class="history-content">
                                        <div class="history-action">
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
                                        <?php if ($item['new_value']): ?>
                                            <div class="history-details">
                                                <?php echo htmlspecialchars($item['new_value']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="history-time">
                                            <?php echo date('M j, Y g:i A', strtotime($item['created_at'])); ?> 
                                            by <?php echo htmlspecialchars($item['changed_by_name']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($history) > 5): ?>
                                <div style="text-align: center; margin-top: 1rem;">
                                    <a href="task_history.php?task_id=<?php echo $task['id']; ?>" class="btn btn-outline">
                                        View Full History (<?php echo count($history); ?> items)
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>