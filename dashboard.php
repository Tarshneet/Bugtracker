<?php
require_once 'config.php';
requireAuth();

$userRole = getUserRole();

// Get user stats
$totalBugs = 0;
$pendingBugs = 0;
$resolvedBugs = 0;

try {
    if ($userRole == 'tester') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bug_tickets WHERE created_by = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $totalBugs = $stmt->fetch()['total'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM bug_tickets WHERE created_by = ? AND status IN ('pending', 'in_progress', 'fixed')");
        $stmt->execute([$_SESSION['user_id']]);
        $pendingBugs = $stmt->fetch()['pending'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as resolved FROM bug_tickets WHERE created_by = ? AND status = 'approved'");
        $stmt->execute([$_SESSION['user_id']]);
        $resolvedBugs = $stmt->fetch()['resolved'];
    } elseif ($userRole == 'developer') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bug_tickets WHERE assigned_dev_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $totalBugs = $stmt->fetch()['total'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM bug_tickets WHERE assigned_dev_id = ? AND status IN ('pending', 'in_progress')");
        $stmt->execute([$_SESSION['user_id']]);
        $pendingBugs = $stmt->fetch()['pending'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as resolved FROM bug_tickets WHERE assigned_dev_id = ? AND status = 'approved'");
        $stmt->execute([$_SESSION['user_id']]);
        $resolvedBugs = $stmt->fetch()['resolved'];
    } elseif ($userRole == 'admin') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bug_tickets");
        $stmt->execute();
        $totalBugs = $stmt->fetch()['total'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM bug_tickets WHERE status IN ('pending', 'in_progress', 'fixed')");
        $stmt->execute();
        $pendingBugs = $stmt->fetch()['pending'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as resolved FROM bug_tickets WHERE status = 'approved'");
        $stmt->execute();
        $resolvedBugs = $stmt->fetch()['resolved'];
    }
} catch (PDOException $e) {
    $error = "Error loading bug stats: " . $e->getMessage();
}

// Get recent bugs
$recentBugs = [];
try {
    if ($userRole == 'tester') {
        $stmt = $pdo->prepare("
            SELECT bt.*, u.name as developer_name 
            FROM bug_tickets bt 
            LEFT JOIN users u ON bt.assigned_dev_id = u.id 
            WHERE bt.created_by = ? 
            ORDER BY bt.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($userRole == 'developer') {
        $stmt = $pdo->prepare("
            SELECT bt.*, u.name as tester_name 
            FROM bug_tickets bt 
            LEFT JOIN users u ON bt.created_by = u.id 
            WHERE bt.assigned_dev_id = ? 
            ORDER BY bt.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($userRole == 'admin') {
        $stmt = $pdo->prepare("
            SELECT bt.*, 
                   u1.name as tester_name, 
                   u2.name as developer_name 
            FROM bug_tickets bt 
            LEFT JOIN users u1 ON bt.created_by = u1.id 
            LEFT JOIN users u2 ON bt.assigned_dev_id = u2.id 
            ORDER BY bt.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute();
    }
    $recentBugs = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error loading recent bugs: " . $e->getMessage();
}

// Get most recent task (for developers only)
$recentTask = null;
if ($userRole == 'developer') {
    try {
        $stmt = $pdo->prepare("
            SELECT dt.*, 
                   u.name as assigned_by_name,
                   p.name as project_name
            FROM developer_tasks dt
            LEFT JOIN users u ON dt.assigned_by = u.id
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.assigned_to = ?
            ORDER BY dt.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $recentTask = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error loading recent task: " . $e->getMessage();
    }
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
        'overdue' => '#ef4444',
        'fixed' => '#3b82f6',
        'approved' => '#10b981',
        'rejected' => '#ef4444'
    ];
    return $colors[$status] ?? '#6b7280';
}

function formatDate($date) {
    return date('M j, Y g:i A', strtotime($date));
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
    <link rel="icon" href="logo.ico" type="image/x-icon">
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
        .status-columns {
            display: flex;
            flex-direction: row;
            gap: 1.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        .status-box {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            flex: 1;
            min-width: 300px;
            border: 1px solid var(--border-color);
        }
        .status-box h3 {
            margin-bottom: 1rem;
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
        }
        .bug-card {
            background: var(--surface-color);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            width: auto;
            height: 200px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.2s;
            min-width: 280px;
            border: 1px solid var(--border-color);
        }
        .bug-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }
        .bug-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        .bug-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            margin: 0;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            margin-right: 0.5rem;
            color: var(--text-primary);
        }
        .bug-meta {
            display: flex;
            gap: 0.3rem;
            flex-wrap: nowrap;
            flex-shrink: 0;
        }
        .priority-badge, .status-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.65rem;
            color: white;
            white-space: nowrap;
            font-weight: 500;
        }
        .bug-description {
            margin: 0.5rem 0;
            color: var(--text-secondary);
            font-size: 0.75rem;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            flex-grow: 1;
            line-height: 1.3;
        }
        .bug-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.65rem;
            color: var(--text-secondary);
            flex-shrink: 0;
            flex-wrap: nowrap;
            gap: 0.5rem;
        }
        .bug-date {
            flex-shrink: 0;
            white-space: nowrap;
            font-weight: 500;
        }
        .bug-assignee {
            flex-shrink: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 120px;
        }
        .bug-assignees {
            display: flex;
            gap: 0.3rem;
            align-items: center;
            flex-shrink: 0;
            overflow: hidden;
        }
        .task-card {
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 2rem;
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
            margin-bottom: 0.5rem;
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
            background: #d1fae5;
            color: #065f46;
        }
        .time-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .time-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        /* Dark mode time indicators */
        [data-theme="dark"] .time-good {
            background: rgba(52, 211, 153, 0.2);
            color: #6ee7b7;
        }
        [data-theme="dark"] .time-warning {
            background: rgba(251, 191, 36, 0.2);
            color: #fde047;
        }
        [data-theme="dark"] .time-danger {
            background: rgba(248, 113, 113, 0.2);
            color: #fca5a5;
        }
        .task-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        /* Enhanced Button Styles */
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
            background-color: var(--background-color);
            border-color: var(--primary-color);
        }
        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }
        .btn span {
            font-size: 1rem;
        }
        .empty-state {
            text-align: center;
            padding: 2rem;
            background: var(--surface-color);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            margin-top: 2rem;
            border: 1px solid var(--border-color);
        }
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 1rem;
        }
        .empty-state p {
            color: var(--text-secondary);
        }
        @media (max-width: 768px) {
            .status-columns {
                flex-direction: column;
                gap: 1rem;
            }
            .status-box {
                min-width: 100%;
            }
            .bug-card {
                min-width: 100%;
                height: auto;
                min-height: 180px;
            }
            .task-card {
                width: 100%;
            }
            .task-details {
                grid-template-columns: 1fr;
            }
            .bug-meta {
                flex-wrap: wrap;
                gap: 0.2rem;
            }
            .priority-badge, .status-badge {
                font-size: 0.6rem;
                padding: 0.15rem 0.3rem;
            }
            .bug-assignee {
                max-width: 100px;
            }
        }
        @media (max-width: 480px) {
            .bug-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.2rem;
            }
            .bug-assignee {
                max-width: none;
            }
            .bug-assignees {
                width: 100%;
            }
        }
</style>


</head>
<body>
    <div class="app-container">
        <?php include 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="dashboard-header">
                <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h1>
                <p>Here's your system-wide bug tracking and task overview</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3><?php echo $totalBugs; ?></h3>
                        <p>Total Bugs</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-content">
                        <h3><?php echo $pendingBugs; ?></h3>
                        <p>Pending Resolution</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <h3><?php echo $resolvedBugs; ?></h3>
                        <p>Resolved Bugs</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <h3><?php echo $totalBugs > 0 ? round(($resolvedBugs / $totalBugs) * 100) : 0; ?>%</h3>
                        <p>Resolution Rate</p>
                    </div>
                </div>
            </div>

            <div class="dashboard-actions">
                <?php if ($userRole == 'admin'): ?>
                    <a href="bug_shift.php" class="btn btn-primary">
                        <span>🌐</span> Bug Shift
                    </a>
                    <a href="admin_report.php" class="btn btn-outline">
                        <span>📊</span> Generate System Report
                    </a>
                    <a href="task_assignment.php" class="btn btn-outline">
                        <span>➕</span> Assign New Task
                    </a>
                <?php elseif ($userRole == 'tester'): ?>
                    <a href="create_bug.php" class="btn btn-primary">
                        <span>🐛</span> Report New Bug
                    </a>
                    <a href="my_bugs.php" class="btn btn-outline">
                        <span>📋</span> My Bug Reports
                    </a>
                    <a href="generate_report.php" class="btn btn-outline">
                        <span>📊</span> Generate Report
                    </a>
                    <a href="task_assignment.php" class="btn btn-outline">
                        <span>➕</span> Assign New Task
                    </a>
                <?php elseif ($userRole == 'developer'): ?>
                    <a href="developer_bugs.php" class="btn btn-primary">
                        <span>🔧</span> View Assigned Bugs
                    </a>
                    <a href="developer_bugs.php?status=pending" class="btn btn-outline">
                        <span>⏳</span> Pending Bugs
                    </a>
                    <a href="developer_bugs.php?status=fixed" class="btn btn-outline">
                        <span>✅</span> Fixed Bugs
                    </a>
                    <a href="task_dashboard.php" class="btn btn-outline">
                        <span>📋</span> View My Tasks
                    </a>
                <?php endif; ?>
            </div>

            <!-- Most Recent Task Section (Developer Only) -->
            <?php if ($userRole == 'developer'): ?>
                <div class="recent-task">
                    <h2>Most Recent Task</h2>
                    <?php if ($recentTask): ?>
                        <?php 
                        $timeRemaining = getTimeRemaining($recentTask['deadline']);
                        $isOverdue = $timeRemaining['overdue'];
                        $timeClass = 'time-good';
                        if ($isOverdue) {
                            $timeClass = 'time-danger';
                        } else {
                            $deadline = new DateTime($recentTask['deadline']);
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
                                <h3 class="task-title"><?php echo htmlspecialchars($recentTask['title']); ?></h3>
                                <div class="task-meta">
                                    <span class="task-badge" style="background-color: <?php echo getPriorityColor($recentTask['priority']); ?>">
                                        <?php echo ucfirst($recentTask['priority']); ?> Priority
                                    </span>
                                    <span class="task-badge" style="background-color: <?php echo getStatusColor($recentTask['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $recentTask['status'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="task-body">
                                <div class="time-indicator <?php echo $timeClass; ?>">
                                    <?php if ($isOverdue): ?>
                                        ⚠️ <?php echo $timeRemaining['text']; ?>
                                    <?php else: ?>
                                        ⏰ <?php echo $timeRemaining['text']; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="task-details">
                                    <div class="task-detail">
                                        <div class="task-detail-label">Created</div>
                                        <div class="task-detail-value">
                                            <?php echo date('M j, Y', strtotime($recentTask['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="task-detail">
                                        <div class="task-detail-label">Deadline</div>
                                        <div class="task-detail-value">
                                            <?php echo date('M j, Y g:i A', strtotime($recentTask['deadline'])); ?>
                                        </div>
                                    </div>
                                    <div class="task-detail">
                                        <div class="task-detail-label">Assigned by</div>
                                        <div class="task-detail-value">
                                            <?php echo htmlspecialchars($recentTask['assigned_by_name'] ?? 'Unknown'); ?>
                                        </div>
                                    </div>
                                    <?php if ($recentTask['project_name']): ?>
                                        <div class="task-detail">
                                            <div class="task-detail-label">Project</div>
                                            <div class="task-detail-value">
                                                <?php echo htmlspecialchars($recentTask['project_name']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="task-actions">
                                    <a href="task_details.php?id=<?php echo $recentTask['id']; ?>" class="btn btn-outline btn-small">
                                        <span>👁️</span> View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <h3>No recent tasks</h3>
                            <p>You don't have any tasks assigned to you.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="recent-bugs">
                <h2>Recent Bug Activity</h2>
                <div class="status-columns">
                    <?php
                    // Group bugs by status
                    $bugsByStatus = [
                        'pending' => [],
                        'in_progress' => [],
                        'fixed' => [],
                        'approved' => [],
                        'rejected' => []
                    ];
                    foreach ($recentBugs as $bug) {
                        $bugsByStatus[$bug['status']][] = $bug;
                    }

                    // Display each status box
                    $statusLabels = [
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'fixed' => 'Fixed',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected'
                    ];

                    foreach ($statusLabels as $status => $label) {
                        if (!empty($bugsByStatus[$status])) {
                            echo '<div class="status-box">';
                            echo '<h3>' . htmlspecialchars($label) . ' (' . count($bugsByStatus[$status]) . ')</h3>';
                            foreach ($bugsByStatus[$status] as $bug) {
                                echo '<a href="#' . htmlspecialchars($bug['id']) . '" style="text-decoration: none; color: inherit;">';
                                echo '<div class="bug-card">';
                                echo '<div class="bug-header">';
                                echo '<h3>' . htmlspecialchars($bug['title']) . '</h3>';
                                echo '<div class="bug-meta">';
                                echo '<span class="priority-badge" style="background-color: ' . getPriorityColor($bug['priority']) . '">';
                                echo ucfirst($bug['priority']) . '</span>';
                                echo '<span class="status-badge" style="background-color: ' . getStatusColor($bug['status']) . '">';
                                echo ucfirst($bug['status']) . '</span>';
                                echo '</div>';
                                echo '</div>';
                                echo '<p class="bug-description">' . html_entity_decode(substr($bug['description'], 0, 120)) . '...</p>';
                                echo '<div class="bug-footer">';
                                echo '<span class="bug-date">' . formatDate($bug['created_at']) . '</span>';
                                if (isset($bug['tester_name'])) {
                                    echo '<span class="bug-assignee">Reported by: ' . htmlspecialchars($bug['tester_name']) . '</span>';
                                }
                                if (isset($bug['developer_name'])) {
                                    echo '<span class="bug-assignee">Assigned to: ' . htmlspecialchars($bug['developer_name']) . '</span>';
                                }
                                echo '</div>';
                                echo '</div>';
                                echo '</a>';
                            }
                            echo '</div>';
                        }
                    }

                    // Display empty state if no bugs in any status
                    if (empty($recentBugs)) {
                        echo '<div class="status-box empty-state">';
                        echo '<div class="empty-state-icon">🐛</div>';
                        echo '<h3>No bugs yet</h3>';
                        echo '<p>No bugs in the system yet. Check back later or assign new bugs.</p>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>