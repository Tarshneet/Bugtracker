<?php
require_once 'config.php';
requireAuth();

$bugId = intval($_GET['id'] ?? 0);
$userRole = getUserRole();

// Get bug details with appropriate access control
$bug = null;
try {
    if ($userRole == 'tester') {
        $stmt = $pdo->prepare("
            SELECT bt.*, u.name as developer_name, u.email as developer_email
            FROM bug_tickets bt 
            LEFT JOIN users u ON bt.assigned_dev_id = u.id 
            WHERE bt.id = ? AND bt.created_by = ?
        ");
        $stmt->execute([$bugId, $_SESSION['user_id']]);
    } elseif ($userRole == 'developer') {
        $stmt = $pdo->prepare("
            SELECT bt.*, u.name as tester_name, u.email as tester_email
            FROM bug_tickets bt 
            JOIN users u ON bt.created_by = u.id 
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

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message'])) {
    $message = sanitizeInput($_POST['message']);
    
    if (!empty($message)) {
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
            $stmt->execute([$bugId, $_SESSION['user_id'], $message, $image]);
            
            // Redirect to prevent form resubmission
            header("Location: chat_bug.php?id=$bugId&success=1");
            exit();
        } catch (PDOException $e) {
            $error = 'Failed to send message';
        }
    }
}

// Get chat messages
$messages = [];
try {
    $stmt = $pdo->prepare("
        SELECT br.*, u.name as user_name, u.role as user_role
        FROM bug_remarks br 
        JOIN users u ON br.user_id = u.id 
        WHERE br.bug_id = ? 
        ORDER BY br.timestamp ASC
    ");
    $stmt->execute([$bugId]);
    $messages = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error
}

$success = isset($_GET['success']) ? 'Message sent successfully!' : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Bug #<?php echo $bug['id']; ?> - <?php echo SITE_NAME; ?></title>
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
                <h1>💬 Chat - Bug #<?php echo $bug['id']; ?>: <?php echo htmlspecialchars($bug['title']); ?></h1>
                <p>
                    <?php if ($userRole == 'tester'): ?>
                        Chatting with <?php echo $bug['developer_name'] ? htmlspecialchars($bug['developer_name']) : 'Unassigned Developer'; ?>
                    <?php else: ?>
                        Chatting with <?php echo htmlspecialchars($bug['tester_name']); ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?> -->

            <div class="chat-container">
                <div class="bug-summary">
                    <div class="bug-info">
                        <div class="bug-badges">
                            <span class="status-badge" style="background-color: <?php echo getStatusColor($bug['status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $bug['status'])); ?>
                            </span>
                            <span class="priority-badge" style="background-color: <?php echo getPriorityColor($bug['priority']); ?>">
                                <?php echo $bug['priority']; ?>
                            </span>
                        </div>
                        <p class="bug-description"><?php echo htmlspecialchars(substr($bug['description'], 0, 150)) . '...'; ?></p>
                        <div class="bug-actions-quick">
                            <a href="view_bug.php?id=<?php echo $bug['id']; ?>" class="btn btn-outline btn-small">
                                <span>👁️</span> View Full Details
                            </a>
                            <?php if ($userRole == 'developer' && in_array($bug['status'], ['pending', 'in_progress', 'rejected'])): ?>
                                <a href="fix_bug.php?id=<?php echo $bug['id']; ?>" class="btn btn-primary btn-small">
                                    <span>🔧</span> Work on Bug
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($messages)): ?>
                        <div class="no-messages">
                            <div class="no-messages-icon">💬</div>
                            <h3>Start the conversation</h3>
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
                                            <img src="<?php echo UPLOAD_DIR . $message['image']; ?>" alt="Attached Image" onclick="openModal(this.src)">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-input">
                    <form method="POST" enctype="multipart/form-data" class="message-form">
                        <div class="input-group">
                            <textarea name="message" placeholder="Type your message..." rows="3" required></textarea>
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
            </div>

            <div class="chat-footer">
                <?php if ($userRole == 'tester'): ?>
                    <a href="my_bugs.php" class="btn btn-outline">
                        <span>←</span> Back to My Bugs
                    </a>
                <?php else: ?>
                    <a href="developer_bugs.php" class="btn btn-outline">
                        <span>←</span> Back to Assigned Bugs
                    </a>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <img id="modalImage" src="" alt="Image">
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

        // Auto-scroll to bottom of messages
        function scrollToBottom() {
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // File preview
        document.getElementById('image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('filePreview');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="preview-item">
                            <img src="${e.target.result}" alt="Preview">
                            <button type="button" onclick="clearFilePreview()" class="remove-preview">×</button>
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

        // Auto-refresh messages every 30 seconds
        setInterval(function() {
            // You can implement AJAX refresh here if needed
        }, 30000);

        // Scroll to bottom on page load
        window.addEventListener('load', scrollToBottom);
    </script>

    <style>
        .chat-container {
            display: grid;
            grid-template-rows: auto 1fr auto;
            height: calc(100vh - 200px);
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .bug-summary {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--background-color);
        }

        .bug-info {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .bug-badges {
            display: flex;
            gap: 0.5rem;
        }

        .bug-description {
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .bug-actions-quick {
            display: flex;
            gap: 1rem;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .no-messages {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-secondary);
            text-align: center;
        }

        .no-messages-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .message {
            display: flex;
            flex-direction: column;
            max-width: 70%;
            margin-bottom: 1rem;
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

        .author-name {
            font-weight: 500;
            color: var(--text-primary);
        }

        .author-role {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            background: var(--primary-color);
            color: white;
        }

        .message.tester .author-role {
            background: var(--success-color);
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
        }

        .message.own-message .message-content {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .message-content p {
            margin: 0;
            line-height: 1.5;
        }

        .message-image {
            margin-top: 1rem;
        }

        .message-image img {
            max-width: 200px;
            height: auto;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: transform 0.3s ease;
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
            min-height: 60px;
            font-family: inherit;
        }

        .input-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
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
        }

        .file-upload-btn:hover {
            background: var(--background-color);
        }

        .file-preview {
            margin-top: 1rem;
        }

        .preview-item {
            position: relative;
            display: inline-block;
        }

        .preview-item img {
            max-width: 100px;
            height: auto;
            border-radius: var(--border-radius);
        }

        .remove-preview {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--error-color);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .chat-footer {
            margin-top: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        @media (max-width: 768px) {
            .chat-container {
                height: calc(100vh - 150px);
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

            .bug-actions-quick {
                flex-direction: column;
            }
        }
    </style>
</body>
</html>