
<?php
require_once 'config.php';
requireRole('developer');

$bugId = intval($_GET['id'] ?? 0);
$error = '';
$success = '';

// Get bug details
$bug = null;
try {
    $stmt = $pdo->prepare("
        SELECT bt.*, u.name as tester_name, u.email as tester_email, p.name as project_name
        FROM bug_tickets bt 
        JOIN users u ON bt.created_by = u.id 
        LEFT JOIN projects p ON bt.project_id = p.id 
        WHERE bt.id = ? AND bt.assigned_dev_id = ?
    ");
    $stmt->execute([$bugId, $_SESSION['user_id']]);
    $bug = $stmt->fetch();
    
    if (!$bug) {
        header('Location: developer_bugs.php');
        exit();
    }
} catch (PDOException $e) {
    $error = 'Failed to load bug details';
}

// Get existing remarks
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action == 'start_working' || $action == 'restart_work') {
            // Mark as in progress
            try {
                $stmt = $pdo->prepare("UPDATE bug_tickets SET status = 'in_progress' WHERE id = ?");
                $stmt->execute([$bugId]);
                
                // Log status change
                $stmt = $pdo->prepare("INSERT INTO bug_status_logs (bug_id, status, updated_by) VALUES (?, 'in_progress', ?)");
                $stmt->execute([$bugId, $_SESSION['user_id']]);
                
                $success = $action == 'restart_work' ? 'Restarted work on bug after rejection' : 'Bug status updated to In Progress';
                $bug['status'] = 'in_progress';
            } catch (PDOException $e) {
                $error = 'Failed to update bug status';
            }
        } elseif ($action == 'mark_fixed') {
            // Mark as fixed
            try {
                $stmt = $pdo->prepare("UPDATE bug_tickets SET status = 'fixed' WHERE id = ?");
                $stmt->execute([$bugId]);
                
                // Log status change
                $stmt = $pdo->prepare("INSERT INTO bug_status_logs (bug_id, status, updated_by) VALUES (?, 'fixed', ?)");
                $stmt->execute([$bugId, $_SESSION['user_id']]);
                
                $success = 'Bug marked as fixed! Waiting for tester approval.';
                $bug['status'] = 'fixed';
            } catch (PDOException $e) {
                $error = 'Failed to update bug status';
            }
        }
    }
    
    // Handle remark submission
    if (isset($_POST['remark']) && !empty(trim($_POST['remark']))) {
        $remark = sanitizeInput($_POST['remark']);
        
        // Handle file upload
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['image']['name'];
            $filesize = $_FILES['image']['size'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($filetype, $allowed) && $filesize < 5000000) {
                $image = time() . '_' . $filename;
                move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . $image);
            }
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO bug_remarks (bug_id, user_id, remark, image) VALUES (?, ?, ?, ?)");
            $stmt->execute([$bugId, $_SESSION['user_id'], $remark, $image]);
            
            $success = 'Remark added successfully';
            
            // Reload remarks
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
            $error = 'Failed to add remark';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Bug #<?php echo $bug['id']; ?> - <?php echo SITE_NAME; ?></title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Professional image styling */
        .bug-screenshot {
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
        
        /* Enhanced modal styling */
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
        
        /* Image icon for visual enhancement */
        .screenshot-icon {
            display: inline-block;
            margin-right: 0.5rem;
            color: #6b7280;
        }
        
        /* Developer-specific styling */
        .bug-reporter {
            color: #059669;
            font-weight: 500;
        }
        
        .bug-actions .btn-primary {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            border: none;
        }
        
        .bug-actions .btn-primary:hover {
            background: linear-gradient(135deg, #047857 0%, #065f46 100%);
            transform: translateY(-2px);
        }

        /* Existing styles updated for consistency */
        .bug-details {
            display: grid;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .bug-info-card {
            background: var(--surface-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .bug-status-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .current-status h3 {
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .status-display {
            display: flex;
            gap: 0.5rem;
        }

        .bug-description-section h3 {
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .bug-description-section p {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .visible-impact {
            background: var(--background-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 1rem;
        }

        .visible-impact h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .remarks-section {
            background: var(--surface-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .remarks-section h3 {
            margin-bottom: 1.5rem;
            color: var(--text-primary);
        }

        .remarks-list {
            margin-bottom: 2rem;
        }

        .no-remarks {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .remark-item {
            background: var(--background-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
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
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .remark-image img {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .remark-image img:hover {
            border-color: #3b82f6;
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .add-remark {
            border-top: 1px solid var(--border-color);
            padding-top: 1.5rem;
        }

        .add-remark h4 {
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .remark-form {
            display: grid;
            gap: 1rem;
        }

        .actions-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
        }

        /* Project name styling */
        .bug-project-info {
            margin-top: 0.5rem;
            font-size: 0.95rem;
            color: #059669;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .bug-status-section {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
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
</head>
<body>
    <div class="app-container">
        <?php include 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Bug #<?php echo $bug['id']; ?>: <?php echo htmlspecialchars($bug['title']); ?></h1>
                <p>Reported by <?php echo htmlspecialchars($bug['tester_name']); ?> on <?php echo formatDate($bug['created_at']); ?></p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <div class="bug-details">
                <div class="bug-info-card">
                    <div class="bug-status-section">
                        <div class="current-status">
                            <h3>Current Status</h3>
                            <div class="status-display">
                                <span class="status-badge" style="background-color: <?php echo getStatusColor($bug['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $bug['status'])); ?>
                                </span>
                                <span class="priority-badge" style="background-color: <?php echo getPriorityColor($bug['priority']); ?>">
                                    <?php echo $bug['priority']; ?>
                                </span>
                            </div>
                        </div>

                        <div class="status-actions">
                            <?php if ($bug['status'] == 'pending' || $bug['status'] == 'rejected'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="<?php echo $bug['status'] == 'rejected' ? 'restart_work' : 'start_working'; ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <span>🔧</span> <?php echo $bug['status'] == 'rejected' ? 'Restart Work' : 'Start Working'; ?>
                                    </button>
                                </form>
                            <?php elseif ($bug['status'] == 'in_progress'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="mark_fixed">
                                    <button type="submit" class="btn btn-primary">
                                        <span>✅</span> Mark as Fixed
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bug-description-section">
                        <h3>Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($bug['description'])); ?></p>
                        
                        <?php if ($bug['visible_impact']): ?>
                            <div class="visible-impact">
                                <h4>Visible Impact</h4>
                                <p><?php echo nl2br(htmlspecialchars($bug['visible_impact'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($bug['module'] || $bug['submodule']): ?>
                            <div class="bug-module-info">
                                <strong>Module:</strong> <?php echo htmlspecialchars($bug['module'] ?? 'N/A'); ?> → <?php echo htmlspecialchars($bug['submodule'] ?? 'N/A'); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($bug['project_name']): ?>
                            <div class="bug-project-info">
                                <strong>Project:</strong> <?php echo htmlspecialchars($bug['project_name']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($bug['screenshot']): ?>
                            <div class="bug-screenshot">
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

                <div class="remarks-section">
                    <h3>Communication</h3>
                    
                    <div class="remarks-list">
                        <?php if (empty($remarks)): ?>
                            <div class="no-remarks">
                                <p>No remarks yet. Start the conversation by adding your first comment.</p>
                            </div>
                        <?php else: ?>
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
                        <?php endif; ?>
                    </div>

                    <div class="add-remark">
                        <h4>Add Comment</h4>
                        <form method="POST" enctype="multipart/form-data" class="remark-form">
                            <div class="form-group">
                                <textarea name="remark" placeholder="Add your comment or update..." rows="4" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="image">Attach Image (optional)</label>
                                <input type="file" id="image" name="image" accept="image/*">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <span>💬</span> Add Comment
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="actions-footer">
                <a href="developer_bugs.php" class="btn btn-outline">
                    <span>←</span> Back to Bug List
                </a>
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
</body>
</html>
