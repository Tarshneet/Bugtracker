<?php
require_once 'config.php';
requireRole('tester');
date_default_timezone_set('Asia/Kolkata');
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query based on status filter and search
$whereClause = "WHERE bt.created_by = ?";
$params = [$_SESSION['user_id']];

if ($status !== 'all') {
    $whereClause .= " AND bt.status = ?";
    $params[] = $status;
}

// Add search functionality
if (!empty($search)) {
    $whereClause .= " AND (
        bt.title LIKE ? OR 
        bt.id LIKE ? OR
        bt.description LIKE ? OR 
        bt.priority LIKE ? OR 
        bt.module LIKE ? OR 
        bt.submodule LIKE ? OR 
        u.name LIKE ? OR 
        p.name LIKE ?
    )";
    $searchParam = "%$search%";
    $params = array_merge($params, array_fill(0, 8, $searchParam));
}

// Get bugs
$bugs = [];
$totalBugs = 0;

try {
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM bug_tickets bt 
                   LEFT JOIN users u ON bt.assigned_dev_id = u.id 
                   LEFT JOIN projects p ON bt.project_id = p.id
                   $whereClause";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalBugs = $stmt->fetch()['total'];

    // Get bugs with pagination - UPDATED to include project name
    $stmt = $pdo->prepare("
        SELECT bt.*, 
               u.name as developer_name, 
               u.email as developer_email,
               p.name as project_name
        FROM bug_tickets bt 
        LEFT JOIN users u ON bt.assigned_dev_id = u.id 
        LEFT JOIN projects p ON bt.project_id = p.id
        $whereClause 
        ORDER BY bt.created_at DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $bugs = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load bugs';
}

$totalPages = ceil($totalBugs / $limit);

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $bugId = intval($_POST['bug_id']);
    $action = $_POST['action'];
    
    // Verify bug belongs to current user
    $stmt = $pdo->prepare("SELECT id FROM bug_tickets WHERE id = ? AND created_by = ?");
    $stmt->execute([$bugId, $_SESSION['user_id']]);
    
    if ($stmt->fetch()) {
        try {
            if ($action == 'approve') {
                $stmt = $pdo->prepare("UPDATE bug_tickets SET status = 'approved' WHERE id = ?");
                $stmt->execute([$bugId]);
                
                // Log status change
                $stmt = $pdo->prepare("INSERT INTO bug_status_logs (bug_id, status, updated_by) VALUES (?, 'approved', ?)");
                $stmt->execute([$bugId, $_SESSION['user_id']]);
                
                $success = 'Bug approved successfully!';
            } elseif ($action == 'reject') {
                $remark = sanitizeInput($_POST['remark'] ?? '');
                
                $stmt = $pdo->prepare("UPDATE bug_tickets SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$bugId]);
                
                // Log status change
                $stmt = $pdo->prepare("INSERT INTO bug_status_logs (bug_id, status, updated_by) VALUES (?, 'rejected', ?)");
                $stmt->execute([$bugId, $_SESSION['user_id']]);
                
                // Add rejection remark
                if (!empty($remark)) {
                    $stmt = $pdo->prepare("INSERT INTO bug_remarks (bug_id, user_id, remark) VALUES (?, ?, ?)");
                    $stmt->execute([$bugId, $_SESSION['user_id'], "Rejection reason: " . $remark]);
                }
                
                $success = 'Bug rejected and sent back to developer!';
            }
            
            // Refresh the page to show updated status
            $redirect_url = "my_bugs.php?status=$status";
            if (!empty($search)) {
                $redirect_url .= "&search=" . urlencode($search);
            }
            $redirect_url .= "&success=" . urlencode($success);
            header("Location: $redirect_url");
            exit();
        } catch (PDOException $e) {
            $error = 'Failed to update bug status';
        }
    }
}

$success = $_GET['success'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bugs - <?php echo SITE_NAME; ?></title>
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
        /* Search Bar Styling */
        .search-container {
            margin-bottom: 2rem;
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            border: 1px solid var(--border-color);
        }
        
        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-input-group {
            flex: 1;
            min-width: 300px;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            padding-left: 2.5rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: var(--background-color);
            color: var(--text-primary);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--surface-color);
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
            font-size: 1.1rem;
        }
        
        .search-btn {
            padding: 0.75rem 1.5rem;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .search-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }
        
        .clear-search {
            padding: 0.75rem 1rem;
            background: var(--background-color);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .clear-search:hover {
            background: var(--border-color);
            color: var(--text-primary);
        }
        
        .search-info {
            margin-top: 1rem;
            padding: 0.75rem;
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid var(--primary-color);
            border-radius: 4px;
            font-size: 0.875rem;
            color: var(--primary-color);
        }
        
        /* Dark mode search info adjustment */
        [data-theme="dark"] .search-info {
            background: rgba(96, 165, 250, 0.2);
            color: var(--primary-color);
        }
        
        .search-results-info {
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: var(--background-color);
            border-radius: 8px;
            font-size: 0.875rem;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        
        .search-highlight {
            background: var(--warning-color);
            color: white;
            padding: 0.1rem 0.2rem;
            border-radius: 3px;
            font-weight: 500;
        }
        
        /* Dark mode search highlight */
        [data-theme="dark"] .search-highlight {
            background: rgba(251, 191, 36, 0.8);
            color: var(--text-primary);
        }
        
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
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--box-shadow);
        }
        
        .bug-screenshot-thumbnail:hover {
            border-color: var(--primary-color);
            transform: scale(1.05);
            box-shadow: var(--box-shadow-lg);
        }
        
        .screenshot-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .screenshot-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .screenshot-action {
            font-size: 0.75rem;
            color: var(--primary-color);
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
        
        /* Dark mode modal overlay */
        [data-theme="dark"] .modal {
            background-color: rgba(0, 0, 0, 0.9);
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
            color: var(--text-secondary);
        }
        
        /* Approval Modal Styling */
        .modal-content > div {
            background: var(--surface-color) !important;
            border: 1px solid var(--border-color);
            box-shadow: var(--box-shadow-lg);
        }
        
        .modal-content h3 {
            color: var(--text-primary);
            margin-bottom: 1rem;
        }
        
        .modal-content p {
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        
        .modal-content label {
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .modal-content textarea {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 4px;
            transition: border-color 0.3s ease;
        }
        
        .modal-content textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .modal-content textarea::placeholder {
            color: var(--text-secondary);
        }
        
        /* Bug card enhancements for dark mode */
        .bug-module-info,
        .bug-project-info {
            margin: 0.5rem 0;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .bug-module-info strong,
        .bug-project-info strong {
            color: var(--text-primary);
        }
        
        .visible-impact {
            background: var(--background-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 1rem;
            border: 1px solid var(--border-color);
        }
        
        .visible-impact strong {
            color: var(--text-primary);
        }
        
        .visible-impact p {
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
        
        /* Enhanced button styling */
        .btn {
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
            border: 1px solid var(--primary-color);
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
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
        
        /* Special styling for reject/delete buttons */
        .btn-outline[style*="color: #ef4444"] {
            color: var(--error-color) !important;
            border-color: var(--error-color) !important;
        }
        
        .btn-outline[style*="color: #ef4444"]:hover {
            background: rgba(239, 68, 68, 0.1) !important;
            border-color: var(--error-color) !important;
            color: var(--error-color) !important;
        }
        
        /* Alert styling */
        .alert-success {
            background: var(--success-color);
            color: white;
            border: 1px solid var(--success-color);
        }
        
        /* Dark mode alert adjustment */
        [data-theme="dark"] .alert-success {
            background: rgba(52, 211, 153, 0.2);
            color: #6ee7b7;
            border-color: rgba(52, 211, 153, 0.3);
        }
        
        /* Page header styling */
        .page-header h1 {
            color: var(--text-primary);
        }
        
        .page-header p {
            color: var(--text-secondary);
        }
        
        /* Filter tabs enhancement */
        .filter-tabs {
            border-bottom: 1px solid var(--border-color);
        }
        
        .filter-tab {
            color: var(--text-secondary);
            transition: all 0.3s ease;
        }
        
        .filter-tab:hover,
        .filter-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        /* Empty state styling */
        .empty-state {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }
        
        .empty-state h3 {
            color: var(--text-primary);
        }
        
        .empty-state a {
            color: var(--primary-color);
        }
        
        .empty-state a:hover {
            text-decoration: underline;
        }
        
        /* Pagination styling */
        .pagination-btn {
            background: var(--surface-color);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        
        .pagination-btn:hover,
        .pagination-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input-group {
                min-width: auto;
            }
            
            .search-btn,
            .clear-search {
                width: 100%;
                text-align: center;
            }
            
            .modal-content {
                margin: 5% auto;
                max-width: 95%;
            }
            
            .bug-screenshot {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .bug-screenshot-thumbnail {
                width: 80px;
                height: 80px;
            }
        }
        
        @media (max-width: 480px) {
            .search-container {
                padding: 1rem;
            }
            
            .bug-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .close {
                top: 10px;
                right: 15px;
                width: 40px;
                height: 40px;
                font-size: 30px;
            }
        }
        
        /* Focus states for accessibility */
        .search-input:focus,
        .search-btn:focus,
        .clear-search:focus,
        .btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
        
        /* Enhanced hover effects */
        .bug-card-detailed:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
            border-color: var(--primary-color);
        }
        
        /* Scrollbar styling for modal textarea */
        .modal-content textarea {
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) var(--surface-color);
        }
        
        .modal-content textarea::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-content textarea::-webkit-scrollbar-track {
            background: var(--surface-color);
        }
        
        .modal-content textarea::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }
        
        .modal-content textarea::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }
</style>

</head>
<body>
    <div class="app-container">
        <?php include 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>My Bug Reports</h1>
                <p>Track and manage your reported bugs</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Search Container -->
            <div class="search-container">
                <form method="GET" class="search-form">
                    <div class="search-input-group">
                        <span class="search-icon">🔍</span>
                        <input 
                            type="text" 
                            name="search" 
                            class="search-input" 
                            placeholder="Search by title, description, priority, module, developer, or project..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                    </div>
                    <button type="submit" class="search-btn">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="?status=<?php echo htmlspecialchars($status); ?>" class="clear-search">Clear</a>
                    <?php endif; ?>
                </form>
                
                <?php if (empty($search)): ?>
                    <div class="search-info">
                        💡 <strong>Search Tips:</strong> You can search by bug title, description, priority (high/medium/low), module name, developer name, or project name.
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($search)): ?>
                <div class="search-results-info">
                    Found <strong><?php echo $totalBugs; ?></strong> result(s) for "<strong><?php echo htmlspecialchars($search); ?></strong>"
                    <?php if ($status !== 'all'): ?>
                        with status "<strong><?php echo htmlspecialchars($status); ?></strong>"
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="filter-tabs">
                <a href="?status=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo ($status == 'all') ? 'active' : ''; ?>">
                    All Bugs (<?php echo $totalBugs; ?>)
                </a>
                <a href="?status=pending<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo ($status == 'pending') ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="?status=in_progress<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo ($status == 'in_progress') ? 'active' : ''; ?>">
                    In Progress
                </a>
                <a href="?status=fixed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo ($status == 'fixed') ? 'active' : ''; ?>">
                    Fixed (Ready for Review)
                </a>
                <a href="?status=approved<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo ($status == 'approved') ? 'active' : ''; ?>">
                    Approved
                </a>
                <a href="?status=rejected<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo ($status == 'rejected') ? 'active' : ''; ?>">
                    Rejected
                </a>
            </div>

            <div class="bugs-container">
                <?php if (empty($bugs)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🐛</div>
                        <h3>No bugs found</h3>
                        <p>
                            <?php if (!empty($search)): ?>
                                No bugs found matching your search criteria. Try adjusting your search terms.
                            <?php elseif ($status == 'all'): ?>
                                You haven't reported any bugs yet. <a href="create_bug.php">Report your first bug</a>
                            <?php else: ?>
                                No bugs with "<?php echo $status; ?>" status found.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="bugs-grid">
                        <?php foreach ($bugs as $bug): ?>
                            <div class="bug-card-detailed">
                                <div class="bug-card-header">
                                    <div class="bug-title">
                                        <h3><?php echo highlightSearchTerm(htmlspecialchars($bug['title']), $search); ?></h3>
                                        <div class="bug-id">#<?php echo $bug['id']; ?></div>
                                    </div>
                                    <div class="bug-badges">
                                        <span class="priority-badge" style="background-color: <?php echo getPriorityColor($bug['priority']); ?>">
                                            <?php echo highlightSearchTerm($bug['priority'], $search); ?>
                                        </span>
                                        <span class="status-badge" style="background-color: <?php echo getStatusColor($bug['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $bug['status'])); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="bug-description">
                                    <p><?php echo highlightSearchTerm(html_entity_decode(substr($bug['description'], 0, 200)), $search) . (strlen($bug['description']) > 200 ? '...' : ''); ?></p>
                                    
                                    <?php if ($bug['visible_impact']): ?>
                                        <div class="visible-impact">
                                            <strong>Visible Impact:</strong>
                                            <p><?php echo highlightSearchTerm(htmlspecialchars($bug['visible_impact']), $search); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bug-module-info">
                                        <strong>Module:</strong> <?php echo highlightSearchTerm(htmlspecialchars($bug['module'] ?? 'N/A'), $search); ?> → <?php echo highlightSearchTerm(htmlspecialchars($bug['submodule'] ?? 'N/A'), $search); ?>
                                    </div>

                                    <!-- Project name display -->
                                    <div class="bug-project-info">
                                        <strong>Project:</strong> <?php echo highlightSearchTerm(htmlspecialchars($bug['project_name'] ?? 'N/A'), $search); ?>
                                    </div>

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

                                <div class="bug-meta">
                                    <div class="bug-developer">
                                        <strong>Assigned to:</strong> 
                                        <?php echo $bug['developer_name'] ? highlightSearchTerm(htmlspecialchars($bug['developer_name']), $search) : 'Unassigned'; ?>
                                    </div>
                                    <div class="bug-date">
                                        <strong>Created:</strong> <?php echo formatDate($bug['created_at']); ?>
                                    </div>
                                </div>

                                <div class="bug-actions">
                                    <?php if ($bug['status'] == 'fixed'): ?>
                                        <!-- Approval buttons for fixed bugs -->
                                        <button onclick="showApprovalModal(<?php echo $bug['id']; ?>, 'approve')" class="btn btn-primary">
                                            <span>✅</span> Approve Fix
                                        </button>
                                        <button onclick="showApprovalModal(<?php echo $bug['id']; ?>, 'reject')" class="btn btn-outline" style="border-color: #ef4444; color: #ef4444;">
                                            <span>❌</span> Reject & Send Back
                                        </button>
                                    <?php elseif ($bug['status'] == 'pending'): ?>
                                        <!-- Edit/Delete options for pending bugs -->
                                        <a href="edit_bug.php?id=<?php echo $bug['id']; ?>" class="btn btn-outline">
                                            <span>✏️</span> Edit
                                        </a>
                                        <button onclick="deleteBug(<?php echo $bug['id']; ?>)" class="btn btn-outline" style="border-color: #ef4444; color: #ef4444;">
                                            <span>🗑️</span> Delete
                                        </button>
                                    <?php endif; ?>
                                    
                                    <a href="view_bug.php?id=<?php echo $bug['id']; ?>" class="btn btn-outline">
                                        <span>👁️</span> View Details
                                    </a>
                                    <a href="chat_bug.php?id=<?php echo $bug['id']; ?>" class="btn btn-outline">
                                        <span>💬</span> Chat
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?status=<?php echo $status; ?>&page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="pagination-btn">← Previous</a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="?status=<?php echo $status; ?>&page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                                   class="pagination-btn <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?status=<?php echo $status; ?>&page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="pagination-btn">Next →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Approval Modal -->
    <div id="approvalModal" class="modal">
        <div class="modal-content" style="max-width: 500px; margin: 10% auto;">
            <div style="background: white; padding: 2rem; border-radius: 8px;">
                <h3 id="modalTitle">Approve Bug Fix</h3>
                <p id="modalMessage">Are you sure you want to approve this bug fix?</p>
                
                <form id="approvalForm" method="POST">
                    <input type="hidden" name="bug_id" id="modalBugId">
                    <input type="hidden" name="action" id="modalAction">
                    
                    <div id="rejectReasonDiv" style="display: none; margin: 1rem 0;">
                        <label for="remark">Reason for rejection:</label>
                        <textarea name="remark" id="remark" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;" placeholder="Please explain why you're rejecting this fix..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" onclick="closeApprovalModal()" class="btn btn-outline">Cancel</button>
                        <button type="submit" id="confirmButton" class="btn btn-primary">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Image Modal -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <img id="modalImage" src="" alt="Bug Screenshot" class="modal-image">
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

        function showApprovalModal(bugId, action) {
            const modal = document.getElementById('approvalModal');
            const title = document.getElementById('modalTitle');
            const message = document.getElementById('modalMessage');
            const rejectDiv = document.getElementById('rejectReasonDiv');
            const confirmBtn = document.getElementById('confirmButton');
            
            document.getElementById('modalBugId').value = bugId;
            document.getElementById('modalAction').value = action;
            
            if (action === 'approve') {
                title.textContent = 'Approve Bug Fix';
                message.textContent = 'Are you sure you want to approve this bug fix?';
                rejectDiv.style.display = 'none';
                confirmBtn.textContent = 'Approve';
                confirmBtn.className = 'btn btn-primary';
            } else {
                title.textContent = 'Reject Bug Fix';
                message.textContent = 'Please provide a reason for rejecting this fix:';
                rejectDiv.style.display = 'block';
                confirmBtn.textContent = 'Reject';
                confirmBtn.className = 'btn btn-outline';
                confirmBtn.style.borderColor = '#ef4444';
                confirmBtn.style.color = '#ef4444';
            }
            
            modal.style.display = 'block';
        }

        function closeApprovalModal() {
            document.getElementById('approvalModal').style.display = 'none';
            document.getElementById('remark').value = '';
        }

        function deleteBug(bugId) {
            if (confirm('Are you sure you want to delete this bug report? This action cannot be undone.')) {
                window.location.href = 'delete_bug.php?id=' + bugId;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const approvalModal = document.getElementById('approvalModal');
            const imageModal = document.getElementById('imageModal');
            
            if (event.target === approvalModal) {
                closeApprovalModal();
            }
            if (event.target === imageModal) {
                closeModal();
            }
        }

        // Auto-submit search form on Enter key
        document.querySelector('.search-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('.search-form').submit();
            }
        });
    </script>
</body>
</html>

<?php
// Helper function to highlight search terms in text
function highlightSearchTerm($text, $search) {
    if (empty($search) || empty($text)) {
        return $text;
    }
    
    // Escape special regex characters in search term
    $searchEscaped = preg_quote($search, '/');
    
    // Perform case-insensitive highlighting
    return preg_replace('/(' . $searchEscaped . ')/i', '<span class="search-highlight">$1</span>', $text);
}
?>