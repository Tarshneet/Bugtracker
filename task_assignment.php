<?php
require_once 'config.php';
requireAuth();

$userRole = getUserRole();

// Only testers and admins can assign tasks
if ($userRole !== 'tester' && $userRole !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Handle task assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_task'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $assigned_to = $_POST['assigned_to'];
    $deadline = $_POST['deadline'];
    $priority = $_POST['priority'];
    $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
    $estimated_hours = !empty($_POST['estimated_hours']) ? $_POST['estimated_hours'] : null;
    
    if (!empty($title) && !empty($description) && !empty($assigned_to) && !empty($deadline)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO developer_tasks (title, description, assigned_to, assigned_by, deadline, priority, project_id, estimated_hours) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([$title, $description, $assigned_to, $_SESSION['user_id'], $deadline, $priority, $project_id, $estimated_hours]);
            
            if ($result) {
                $taskId = $pdo->lastInsertId();
                
                // Log the task creation
                $stmt = $pdo->prepare("
                    INSERT INTO task_history (task_id, action, new_value, changed_by) 
                    VALUES (?, 'created', ?, ?)
                ");
                $stmt->execute([$taskId, "Task created and assigned to developer", $_SESSION['user_id']]);
                
                $success = "Task assigned successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error assigning task: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get developers for assignment
$developers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE role = 'developer' ORDER BY name");
    $stmt->execute();
    $developers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error loading developers: " . $e->getMessage();
}

// Get projects
$projects = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM projects ORDER BY name");
    $stmt->execute();
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error silently
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Assignment - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bug Tracker'; ?></title>
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
            --border-color: #d1d5db;
            --text-primary: #374151;
            --text-secondary: #6b7280;
            --background-color: #ffffff;
            --hover-color: #f9fafb;
            --primary-color: #3b82f6;
            --success-color: #10b981;
            --error-color: #ef4444;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        [data-theme="dark"] {
            --surface-color: #1e293b;
            --border-color: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --background-color: #0f172a;
            --hover-color: #374151;
            --primary-color: #60a5fa;
            --success-color: #34d399;
            --error-color: #f87171;
            --box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        /* Apply dark mode to existing styles */
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--surface-color);
            padding: 2rem;
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            border: 1px solid var(--border-color);
        }

        .form-group label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.2s;
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-secondary);
        }

        .form-group select option {
            background: var(--surface-color);
            color: var(--text-primary);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: 1px solid var(--error-color);
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

        /* Original styles with dark mode variables applied */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }

        .required {
            color: var(--error-color);
        }

        .priority-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .priority-low { background-color: #10b981; }
        .priority-medium { background-color: #f59e0b; }
        .priority-high { background-color: #ef4444; }
        .priority-critical { background-color: #7c2d12; }

        /* Smooth transitions for theme switching */
        * {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
</style>

</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="app-container">
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Assign Task to Developer</h1>
                <p>Create and assign new tasks to developers with deadlines and priorities</p>
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

            <div class="form-container">
                <form method="POST" action="">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="title">Task Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" required 
                                   placeholder="Enter a clear and descriptive task title"
                                   value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                        </div>

                        <div class="form-group full-width">
                            <label for="description">Task Description <span class="required">*</span></label>
                            <textarea id="description" name="description" required 
                                      placeholder="Provide detailed description of the task, requirements, and expected outcomes"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="assigned_to">Assign to Developer <span class="required">*</span></label>
                            <select id="assigned_to" name="assigned_to" required>
                                <option value="">Select a developer</option>
                                <?php foreach ($developers as $developer): ?>
                                    <option value="<?php echo $developer['id']; ?>"
                                            <?php echo (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $developer['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($developer['name']) . ' (' . htmlspecialchars($developer['email']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="deadline">Deadline <span class="required">*</span></label>
                            <input type="datetime-local" id="deadline" name="deadline" required
                                   min="<?php echo date('Y-m-d\TH:i'); ?>"
                                   value="<?php echo isset($_POST['deadline']) ? $_POST['deadline'] : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="priority">Priority <span class="required">*</span></label>
                            <select id="priority" name="priority" required>
                                <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : ''; ?>>
                                    <span class="priority-indicator priority-low"></span>Low
                                </option>
                                <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') ? 'selected' : ''; ?>>
                                    <span class="priority-indicator priority-medium"></span>Medium
                                </option>
                                <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : ''; ?>>
                                    <span class="priority-indicator priority-high"></span>High
                                </option>
                                <option value="critical" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'critical') ? 'selected' : ''; ?>>
                                    <span class="priority-indicator priority-critical"></span>Critical
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="project_id">Project (Optional)</label>
                            <select id="project_id" name="project_id">
                                <option value="">Select a project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>"
                                            <?php echo (isset($_POST['project_id']) && $_POST['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="estimated_hours">Estimated Hours (Optional)</label>
                            <input type="number" id="estimated_hours" name="estimated_hours" 
                                   min="0.5" max="999" step="0.5"
                                   placeholder="e.g., 8.5"
                                   value="<?php echo isset($_POST['estimated_hours']) ? htmlspecialchars($_POST['estimated_hours']) : ''; ?>">
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="task_dashboard.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" name="assign_task" class="btn btn-primary">
                            <span>📋</span> Assign Task
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Set minimum deadline to current time + 1 hour
        document.addEventListener('DOMContentLoaded', function() {
            const deadlineInput = document.getElementById('deadline');
            const now = new Date();
            now.setHours(now.getHours() + 1);
            const minDateTime = now.toISOString().slice(0, 16);
            deadlineInput.min = minDateTime;
            
            // Set default deadline to tomorrow at 5 PM if not set
            if (!deadlineInput.value) {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                tomorrow.setHours(17, 0, 0, 0);
                deadlineInput.value = tomorrow.toISOString().slice(0, 16);
            }
        });
    </script>
</body>
</html>