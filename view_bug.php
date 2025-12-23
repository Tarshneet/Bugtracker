
<?php
require_once 'config.php';
requireAuth();
date_default_timezone_set('Asia/Kolkata');
$bugId = intval($_GET['id'] ?? 0);
$userRole = getUserRole();

// Get bug details with appropriate access control
$bug = null;
try {
    if ($userRole == 'tester') {
        $stmt = $pdo->prepare("
            SELECT bt.*, u.name as developer_name, u.email as developer_email, p.name as project_name
            FROM bug_tickets bt 
            LEFT JOIN users u ON bt.assigned_dev_id = u.id 
            LEFT JOIN projects p ON bt.project_id = p.id 
            WHERE bt.id = ? AND bt.created_by = ?
        ");
        $stmt->execute([$bugId, $_SESSION['user_id']]);
    } elseif ($userRole == 'developer') {
        $stmt = $pdo->prepare("
            SELECT bt.*, u.name as tester_name, u.email as tester_email, p.name as project_name
            FROM bug_tickets bt 
            JOIN users u ON bt.created_by = u.id 
            LEFT JOIN projects p ON bt.project_id = p.id 
            WHERE bt.id = ? AND bt.assigned_dev_id = ?
        ");
        $stmt->execute([$bugId, $_SESSION['user_id']]);
    }
    
    $bug = $stmt->fetch();
    
    if (!$bug) {
        header('Location: dashboard.php');
        exit();
    }
} catch (PDOException $e) {
    $error = 'Failed to load bug details';
}

// Get remarks
$remarks = [];
try {
    $stmt = $pdo->prepare("
        SELECT br.*, u.name as user_name, u.role as user_role
        FROM bug_remarks br 
        JOIN users u ON br.user_id = u.id 
        WHERE br.bug_id = ? 
        ORDER BY br.timestamp ASC
    ");
    $stmt->execute([$bugId]);
    $remarks = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error
}

// Get status history
$statusHistory = [];
try {
    $stmt = $pdo->prepare("
        SELECT bsl.*, u.name as user_name
        FROM bug_status_logs bsl 
        JOIN users u ON bsl.updated_by = u.id 
        WHERE bsl.bug_id = ? 
        ORDER BY bsl.timestamp ASC
    ");
    $stmt->execute([$bugId]);
    $statusHistory = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bug #<?php echo $bug['id']; ?> - <?php echo SITE_NAME; ?></title>
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
    <div class="app-container">
        <?php include 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Bug #<?php echo $bug['id']; ?>: <?php echo htmlspecialchars($bug['title']); ?></h1>
                <p>
                    <?php if ($userRole == 'tester'): ?>
                        Assigned to <?php echo $bug['developer_name'] ? htmlspecialchars($bug['developer_name']) : 'Unassigned'; ?>
                    <?php else: ?>
                        Reported by <?php echo htmlspecialchars($bug['tester_name']); ?>
                    <?php endif; ?>
                    on <?php echo formatDate($bug['created_at']); ?>
                </p>
            </div>

            <div class="bug-details-view">
                <div class="bug-info-section">
                    <div class="bug-status-info">
                        <div class="status-badges">
                            <span class="status-badge" style="background-color: <?php echo getStatusColor($bug['status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $bug['status'])); ?>
                            </span>
                            <span class="priority-badge" style="background-color: <?php echo getPriorityColor($bug['priority']); ?>">
                                <?php echo $bug['priority']; ?>
                            </span>
                        </div>
                    </div>

                    <div class="bug-content">
                        <h3>Description</h3>
                        <div class="bug-module-section">
                            <h4>Module Information</h4>
                            <p><strong>Module:</strong> <?php echo htmlspecialchars($bug['module'] ?? 'N/A'); ?></p>
                            <p><strong>Submodule:</strong> <?php echo htmlspecialchars($bug['submodule'] ?? 'N/A'); ?></p>
                        </div>
                        
                        <?php if ($bug['project_name']): ?>
                            <div class="bug-project-info">
                                <strong>Project:</strong> <?php echo htmlspecialchars($bug['project_name']); ?>
                            </div>
                        <?php endif; ?>

                        <p><?php echo nl2br(htmlspecialchars($bug['description'])); ?></p>
                        
                        <?php if ($bug['visible_impact']): ?>
                            <div class="visible-impact">
                                <h4>Visible Impact</h4>
                                <p><?php echo nl2br(htmlspecialchars($bug['visible_impact'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($bug['screenshot']): ?>
                            <div class="bug-screenshot">
                                <h4>Screenshot</h4>
                                <img src="<?php echo UPLOAD_DIR . $bug['screenshot']; ?>" 
                                     alt="Bug Screenshot" 
                                     class="bug-screenshot-thumbnail"
                                     onclick="openModal('<?php echo UPLOAD_DIR . $bug['screenshot']; ?>')">
                                <div class="screenshot-info">
                                    <span class="screenshot-label">
                                        <span class="screenshot-icon">📷</span>Screenshot attached
                                    </span>
                                    <a href="javascript:void(0)" 
                                       onclick="openModal('<?php echo UPLOAD_DIR . $bug['screenshot']; ?>')" 
                                       class="screenshot-action">Click to view full size</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($statusHistory)): ?>
                    <div class="status-history">
                        <h3>Status History</h3>
                        <div class="timeline">
                            <?php foreach ($statusHistory as $history): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker" style="background-color: <?php echo getStatusColor($history['status']); ?>"></div>
                                    <div class="timeline-content">
                                        <strong><?php echo ucfirst(str_replace('_', ' ', $history['status'])); ?></strong>
                                        <p>by <?php echo htmlspecialchars($history['user_name']); ?></p>
                                        <small><?php echo formatDate($history['timestamp']); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($remarks)): ?>
                    <div class="remarks-history">
                        <h3>Communication History</h3>
                        <div class="remarks-list">
                            <?php foreach ($remarks as $remark): ?>
                                <div class="remark-item <?php echo $remark['user_role']; ?>">
                                    <div class="remark-header">
                                        <strong><?php echo htmlspecialchars($remark['user_name']); ?></strong>
                                        <span class="role-badge"><?php echo ucfirst($remark['user_role']); ?></span>
                                        <span class="remark-time"><?php echo formatDate($remark['timestamp']); ?></span>
                                    </div>
                                    <div class="remark-content">
                                        <p><?php echo nl2br(htmlspecialchars($remark['remark'])); ?></p>
                                        <?php if ($remark['image']): ?>
                                            <div class="remark-image">
                                                <img src="<?php echo UPLOAD_DIR . $remark['image']; ?>" 
                                                     alt="Remark Image" 
                                                     class="bug-screenshot-thumbnail"
                                                     onclick="openModal('<?php echo UPLOAD_DIR . $remark['image']; ?>')">
                                                <div class="screenshot-info">
                                                    <span class="screenshot-label">
                                                        <span class="screenshot-icon">📷</span>Image attached
                                                    </span>
                                                    <a href="javascript:void(0)" 
                                                       onclick="openModal('<?php echo UPLOAD_DIR . $remark['image']; ?>')" 
                                                       class="screenshot-action">Click to view full size</a>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="actions-footer">
                <?php if ($userRole == 'tester'): ?>
                    <a href="my_bugs.php" class="btn btn-outline">
                        <span>←</span> Back to My Bugs
                    </a>
                <?php else: ?>
                    <a href="developer_bugs.php" class="btn btn-outline">
                        <span>←</span> Back to Assigned Bugs
                    </a>
                    <?php if (in_array($bug['status'], ['pending', 'in_progress'])): ?>
                        <a href="fix_bug.php?id=<?php echo $bug['id']; ?>" class="btn btn-primary">
                            <span>🔧</span> Work on Bug
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">×</span>
            <img id="modalImage" src="" alt="Image" class="modal-image">
        </div>
    </div>

    <script>
        function openModal(src) {
            document.getElementById('imageModal').style.display = 'block';
            document.getElementById('modalImage').src = src;
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const imageModal = document.getElementById('imageModal');
            if (event.target === imageModal) {
                closeModal();
            }
        }
    </script>

    <style>
        .bug-details-view {
            display: grid;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .bug-info-section {
            background: var(--surface-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .bug-status-info {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .status-badges {
            display: flex;
            gap: 0.5rem;
        }

        .bug-content h3 {
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .bug-content p {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .visible-impact {
            background: var(--background-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1rem 0;
        }

        .visible-impact h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .bug-screenshot {
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .bug-screenshot h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .bug-screenshot-thumbnail {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .bug-screenshot-thumbnail:hover {
            border-color: #3b82f6;
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .screenshot-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .screenshot-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }

        .screenshot-action {
            font-size: 0.75rem;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }

        .screenshot-action:hover {
            text-decoration: underline;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            position: relative;
            margin: 2% auto;
            padding: 0;
            max-width: 90%;
            max-height: 90vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-image {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: #fff;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .close:hover {
            background: rgba(0, 0, 0, 0.8);
            transform: scale(1.1);
        }

        .screenshot-icon {
            display: inline-block;
            margin-right: 0.5rem;
            color: #6b7280;
        }

        .bug-project-info {
            margin-top: 0.5rem;
            font-size: 0.95rem;
            color: #059669;
            font-weight: 500;
        }

        .status-history, .remarks-history {
            background: var(--surface-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .status-history h3, .remarks-history h3 {
            margin-bottom: 1.5rem;
            color: var(--text-primary);
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-marker {
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
        }

        .timeline-content strong {
            color: var(--text-primary);
        }

        .timeline-content p {
            color: var(--text-secondary);
            margin: 0.25rem 0;
        }

        .timeline-content small {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .remarks-list {
            display: grid;
            gap: 1rem;
        }

        .remark-item {
            background: var(--background-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--border-color);
        }

        .remark-item.developer {
            border-left-color: var(--primary-color);
        }

        .remark-item.tester {
            border-left-color: var(--success-color);
        }

        .remark-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .remark-header strong {
            color: var(--text-primary);
        }

        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            background: var(--primary-color);
            color: white;
        }

        .remark-time {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-left: auto;
        }

        .remark-content p {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .remark-image {
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .remark-image .bug-screenshot-thumbnail {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .remark-image .bug-screenshot-thumbnail:hover {
            border-color: #3b82f6;
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .actions-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
        }

        @media (max-width: 768px) {
            .timeline {
                padding-left: 1.5rem;
            }

            .timeline-marker {
                left: -1rem;
            }

            .remark-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .remark-time {
                margin-left: 0;
            }
        }
    </style>
</body>
</html>
