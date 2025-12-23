<?php
// Set timezone to IST (Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';
requireRole('tester');

$error = '';
$success = '';

// Path to JSON file
define('MODULES_FILE', 'data/custom_modules.json');

// Load custom modules and submodules from JSON file
function loadModulesFromFile() {
    if (file_exists(MODULES_FILE)) {
        $json = file_get_contents(MODULES_FILE);
        return json_decode($json, true) ?: ['modules' => [], 'submodules' => []];
    }
    return ['modules' => [], 'submodules' => []];
}

// Save custom modules and submodules to JSON file
function saveModulesToFile($data) {
    file_put_contents(MODULES_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Initialize modules and submodules
$modules_data = loadModulesFromFile();
$custom_modules = $modules_data['modules'];
$custom_submodules = $modules_data['submodules'];

// Get developers for assignment
$developers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE role = 'developer' ORDER BY name");
    $stmt->execute();
    $developers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load developers';
}

// Get projects
$projects = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM projects ORDER BY name");
    $stmt->execute();
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load projects';
}

// Handle new module, submodule, or project submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['message' => '', 'success' => false];

    if ($_POST['action'] == 'add_module') {
        $new_module = trim($_POST['new_module']);
        if (!empty($new_module)) {
            if (!in_array($new_module, $custom_modules)) {
                $custom_modules[] = $new_module;
                $modules_data['modules'] = $custom_modules;
                saveModulesToFile($modules_data);
                $response = ['message' => 'Module added successfully!', 'success' => true, 'module' => $new_module];
            } else {
                $response['message'] = 'Module already exists';
            }
        } else {
            $response['message'] = 'Module name cannot be empty';
        }
        echo json_encode($response);
        exit();
    } elseif ($_POST['action'] == 'add_submodule') {
        $new_submodule = trim($_POST['new_submodule']);
        $module = trim($_POST['module_for_submodule']);
        if (!empty($new_submodule) && !empty($module)) {
            $custom_submodules[$module] = $custom_submodules[$module] ?? [];
            if (!in_array($new_submodule, $custom_submodules[$module])) {
                $custom_submodules[$module][] = $new_submodule;
                $modules_data['submodules'] = $custom_submodules;
                saveModulesToFile($modules_data);
                $response = ['message' => 'Submodule added successfully!', 'success' => true, 'submodule' => $new_submodule, 'module' => $module];
            } else {
                $response['message'] = 'Submodule already exists';
            }
        } else {
            $response['message'] = 'Submodule name and module cannot be empty';
        }
        echo json_encode($response);
        exit();
    } elseif ($_POST['action'] == 'add_project') {
        $new_project = trim($_POST['new_project']);
        if (!empty($new_project)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO projects (name) VALUES (?)");
                $stmt->execute([$new_project]);
                $new_project_id = $pdo->lastInsertId();
                $response = ['message' => 'Project added successfully!', 'success' => true, 'id' => $new_project_id, 'name' => $new_project];
            } catch (PDOException $e) {
                $response['message'] = 'Failed to add project';
            }
        } else {
            $response['message'] = 'Project name cannot be empty';
        }
        echo json_encode($response);
        exit();
    }
}

// Handle bug report submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $type = trim($_POST['type']);
    $title = trim($_POST['title']);
    $project_id = trim($_POST['project_id']);
    $module = trim($_POST['module']);
    $submodule = trim($_POST['submodule']);
    $description = trim($_POST['description']);
    $priority = trim($_POST['priority']);
    $visible_impact = trim($_POST['visible_impact']);
    $assigned_dev_id = trim($_POST['assigned_dev_id']);
    
    // Validation for all fields
    if (empty($type)) {
        $error = 'Type is required';
    } elseif (!in_array($type, ['Bug', 'Change'])) {
        $error = 'Invalid Type selected';
    }
    if (empty($title)) {
        $error = 'Title is required';
    } elseif (strlen($title) > 94) {
        $error = 'Title must not exceed 94 characters (including type prefix)';
    }
    if (empty($project_id)) {
        $error = 'Project is required';
    }
    if (empty($module)) {
        $error = 'Module is required';
    }
    if (empty($submodule)) {
        $error = 'Submodule is required';
    }
    if (empty($description)) {
        $error = 'Detailed Description is required';
    } elseif (strlen($description) > 1000) {
        $error = 'Detailed Description must not exceed 1000 characters';
    }
    if (empty($priority)) {
        $error = 'Priority Level is required';
    }
    if (empty($assigned_dev_id)) {
        $error = 'Assigned Developer is required';
    }
    if (!empty($visible_impact) && strlen($visible_impact) > 500) {
        $error = 'Visible Impact must not exceed 500 characters';
    }
    
    // Handle file upload and enforce image requirement
    $screenshots = [];
    if (isset($_FILES['screenshot']) && !empty($_FILES['screenshot']['name'][0])) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        foreach ($_FILES['screenshot']['name'] as $key => $name) {
            if ($_FILES['screenshot']['error'][$key] == 0) {
                $filetype = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $filesize = $_FILES['screenshot']['size'][$key];
                
                if (in_array($filetype, $allowed) && $filesize < 5000000) {
                    $filename = time() . '_' . $key . '_' . $name;
                    move_uploaded_file($_FILES['screenshot']['tmp_name'][$key], UPLOAD_DIR . $filename);
                    $screenshots[] = $filename;
                } else {
                    $error = 'Invalid file type or size too large (max 5MB)';
                }
            }
        }
    } else {
        $error = 'At least one Screenshot/Evidence is required';
    }
    
    if (empty($error)) {
        try {
            $full_title = $type . ':' . $title;
            $current_timestamp = date('Y-m-d H:i:s');
            $screenshot_string = implode(',', $screenshots);
            
            $stmt = $pdo->prepare("
                INSERT INTO bug_tickets (title, project_id, module, submodule, description, priority, visible_impact, screenshot, assigned_dev_id, created_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$full_title, $project_id, $module, $submodule, $description, $priority, $visible_impact, $screenshot_string, $assigned_dev_id, $_SESSION['user_id'], $current_timestamp, $current_timestamp]);
            
            $bugId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO bug_status_logs (bug_id, status, updated_by) VALUES (?, 'pending', ?)");
            $stmt->execute([$bugId, $_SESSION['user_id']]);
            
            $success = 'Report created successfully!';
            $_POST = [];
        } catch (PDOException $e) {
            $error = 'Failed to create report: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Bug or Change - <?php echo SITE_NAME; ?></title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <link rel="icon" href="logo.ico" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        [data-theme="dark"] .modal {
            background-color: rgba(0,0,0,0.8);
        }
        
        .modal-content {
            background: var(--surface-color);
            margin: 15% auto;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 400px;
            border: 1px solid var(--border-color);
            box-shadow: var(--box-shadow-lg);
        }
        
        .modal-content h3 {
            margin-bottom: 1.5rem;
            color: var(--text-primary);
        }
        
        .modal-content .form-group {
            margin-bottom: 1rem;
        }
        
        .modal-content .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        .add-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            margin-left: 0.5rem;
            padding: 0;
            font-size: 1.2rem;
            line-height: 1;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
        }
        
        .add-btn:hover {
            background: var(--primary-hover);
            transform: scale(1.1);
        }
        
        .form-row {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        
        .form-group {
            flex: 1;
        }
        
        .error {
            color: var(--error-color);
            font-size: 0.9rem;
            margin-top: 0.25rem;
            display: block;
        }
        
        .form-group.invalid input, 
        .form-group.invalid select, 
        .form-group.invalid textarea {
            border-color: var(--error-color);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.1);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            background-color: var(--surface-color);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-secondary);
        }
        
        .form-group label {
            color: var(--text-primary);
        }
        
        .form-group small {
            color: var(--text-secondary);
        }
        
        #imagePreviewContainer {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 0.5rem;
        }
        
        #paste-zone {
            border: 2px dashed var(--primary-color);
            padding: 2rem;
            margin-top: 0.5rem;
            cursor: pointer;
            color: var(--primary-color);
            border-radius: var(--border-radius);
            text-align: center;
            transition: all 0.3s ease;
            background: var(--background-color);
            font-size: 1.1rem;
        }
        
        #paste-zone:hover {
            background: var(--border-color);
            border-color: var(--primary-hover);
            color: var(--primary-hover);
        }
        
        #paste-zone:focus {
            outline: none;
            border-color: var(--primary-hover);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }
        
        #paste-zone.dragover {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--primary-hover);
            color: var(--primary-hover);
        }
        
        [data-theme="dark"] #paste-zone {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: var(--background-color);
        }
        
        [data-theme="dark"] #paste-zone:hover {
            background: var(--surface-color);
            border-color: var(--primary-hover);
        }
        
        [data-theme="dark"] #paste-zone.dragover {
            background: rgba(96, 165, 250, 0.2);
            border-color: var(--primary-color);
        }
        
        .hidden-file-input {
            display: none;
        }
        
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border: 1px solid;
        }
        
        .alert-error {
            background: var(--error-color);
            color: white;
            border-color: var(--error-color);
        }
        
        .alert-success {
            background: var(--success-color);
            color: white;
            border-color: var(--success-color);
        }
        
        [data-theme="dark"] .alert-error {
            background: rgba(248, 113, 113, 0.2);
            color: #fca5a5;
            border-color: rgba(248, 113, 113, 0.3);
        }
        
        [data-theme="dark"] .alert-success {
            background: rgba(52, 211, 153, 0.2);
            color: #6ee7b7;
            border-color: rgba(52, 211, 153, 0.3);
        }
        
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
        
        .form-container {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
        }
        
        .page-header h1 {
            color: var(--text-primary);
        }
        
        .page-header p {
            color: var(--text-secondary);
        }
        
        .modal-content input {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .modal-content input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .modal-content input::placeholder {
            color: var(--text-secondary);
        }
        
        .modal-content label {
            color: var(--text-primary);
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .modal-content {
                margin: 10% auto;
                width: 95%;
                padding: 1.5rem;
            }
            
            #paste-zone {
                padding: 1.5rem;
                font-size: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .form-actions {
                flex-direction: column;
            }
            
            .modal-content .form-actions {
                flex-direction: row;
            }
        }
        
        .form-group textarea {
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) var(--surface-color);
        }
        
        .form-group textarea::-webkit-scrollbar {
            width: 8px;
        }
        
        .form-group textarea::-webkit-scrollbar-track {
            background: var(--surface-color);
        }
        
        .form-group textarea::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }
        
        .form-group textarea::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }
        
        .add-btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
        
        .form-group.valid input,
        .form-group.valid select,
        .form-group.valid textarea {
            border-color: var(--success-color);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .modal {
            backdrop-filter: blur(4px);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Report New Bug or Change</h1>
                <p>Provide detailed information about the bug or change request</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success && !is_array(json_decode($success, true))): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST" enctype="multipart/form-data" class="bug-form" id="bugForm">
                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['type']) && empty($_POST['type'])) ? 'invalid' : ''; ?>">
                            <label for="type">Type *</label>
                            <select id="type" name="type" required>
                                <option value="">Select Type</option>
                                <option value="Bug" <?php echo (isset($_POST['type']) && $_POST['type'] == 'Bug') ? 'selected' : ''; ?>>Bug</option>
                                <option value="Change" <?php echo (isset($_POST['type']) && $_POST['type'] == 'Change') ? 'selected' : ''; ?>>Change</option>
                            </select>
                            <?php if (isset($_POST['type']) && empty($_POST['type'])): ?>
                                <div class="error">Type is required</div>
                            <?php elseif (isset($_POST['type']) && !in_array($_POST['type'], ['Bug', 'Change'])): ?>
                                <div class="error">Invalid Type selected</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['title']) && empty($_POST['title'])) ? 'invalid' : ''; ?>">
                            <label for="title">Title *</label>
                            <input type="text" id="title" name="title" required 
                                   value="<?php echo isset($_POST['title']) ? $_POST['title'] : ''; ?>"
                                   placeholder="Brief description (type will be prepended, e.g., Bug:test)">
                            <?php if (isset($_POST['title']) && empty($_POST['title'])): ?>
                                <div class="error">Title is required</div>
                            <?php elseif (isset($_POST['title']) && strlen($_POST['title']) > 94): ?>
                                <div class="error">Title must not exceed 94 characters (including type prefix)</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['project_id']) && empty($_POST['project_id'])) ? 'invalid' : ''; ?>">
                            <label for="project_id">Project *</label>
                            <select id="project_id" name="project_id" required>
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" <?php echo (isset($_POST['project_id']) && $_POST['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo $project['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="add-btn" onclick="showModal('project')" title="Add Project">+</button>
                            <?php if (isset($_POST['project_id']) && empty($_POST['project_id'])): ?>
                                <div class="error">Project is required</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['module']) && empty($_POST['module'])) ? 'invalid' : ''; ?>">
                            <label for="module">Module *</label>
                            <select id="module" name="module" required onchange="updateSubmodules()">
                                <option value="">Select Module</option>
                                <option value="TA/DA" <?php echo (isset($_POST['module']) && $_POST['module'] == 'TA/DA') ? 'selected' : ''; ?>>TA/DA</option>
                                <option value="Report" <?php echo (isset($_POST['module']) && $_POST['module'] == 'Report') ? 'selected' : ''; ?>>Report</option>
                                <option value="Reimburse" <?php echo (isset($_POST['module']) && $_POST['module'] == 'Reimburse') ? 'selected' : ''; ?>>Reimburse</option>
                                <option value="Approval" <?php echo (isset($_POST['module']) && $_POST['module'] == 'Approval') ? 'selected' : ''; ?>>Approval</option>
                                <?php foreach ($custom_modules as $custom_module): ?>
                                    <option value="<?php echo htmlspecialchars($custom_module); ?>" <?php echo (isset($_POST['module']) && $_POST['module'] == $custom_module) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($custom_module); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="add-btn" onclick="showModal('module')" title="Add Module">+</button>
                            <?php if (isset($_POST['module']) && empty($_POST['module'])): ?>
                                <div class="error">Module is required</div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group <?php echo (isset($_POST['submodule']) && empty($_POST['submodule'])) ? 'invalid' : ''; ?>">
                            <label for="submodule">Submodule *</label>
                            <select id="submodule" name="submodule" required>
                                <option value="">Select Submodule</option>
                                <?php
                                if (isset($_POST['module']) && isset($custom_submodules[$_POST['module']])) {
                                    foreach ($custom_submodules[$_POST['module']] as $custom_submodule) {
                                        echo '<option value="' . htmlspecialchars($custom_submodule) . '" ' . (isset($_POST['submodule']) && $_POST['submodule'] == $custom_submodule ? 'selected' : '') . '>' . htmlspecialchars($custom_submodule) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <button type="button" class="add-btn" onclick="showModal('submodule')" title="Add Submodule">+</button>
                            <?php if (isset($_POST['submodule']) && empty($_POST['submodule'])): ?>
                                <div class="error">Submodule is required</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['description']) && empty($_POST['description'])) ? 'invalid' : ''; ?>">
                            <label for="description">Detailed Description *</label>
                            <textarea id="description" name="description" required rows="5" 
                                      placeholder="Provide detailed steps to reproduce the bug or describe the change request"><?php echo isset($_POST['description']) ? $_POST['description'] : ''; ?></textarea>
                            <?php if (isset($_POST['description']) && empty($_POST['description'])): ?>
                                <div class="error">Detailed Description is required</div>
                            <?php elseif (isset($_POST['description']) && strlen($_POST['description']) > 1000): ?>
                                <div class="error">Detailed Description must not exceed 1000 characters</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['priority']) && empty($_POST['priority'])) ? 'invalid' : ''; ?>">
                            <label for="priority">Priority Level *</label>
                            <select id="priority" name="priority" required>
                                <option value="">Select Priority</option>
                                <option value="P1" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'P1') ? 'selected' : ''; ?>>P1 - Critical (System crash, data loss)</option>
                                <option value="P2" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'P2') ? 'selected' : ''; ?>>P2 - High (Major feature broken)</option>
                                <option value="P3" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'P3') ? 'selected' : ''; ?>>P3 - Medium (Minor feature issues)</option>
                                <option value="P4" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'P4') ? 'selected' : ''; ?>>P4 - Low (Cosmetic issues)</option>
                            </select>
                            <?php if (isset($_POST['priority']) && empty($_POST['priority'])): ?>
                                <div class="error">Priority Level is required</div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group <?php echo (isset($_POST['assigned_dev_id']) && empty($_POST['assigned_dev_id'])) ? 'invalid' : ''; ?>">
                            <label for="assigned_dev_id">Assign Developer *</label>
                            <select id="assigned_dev_id" name="assigned_dev_id" required>
                                <option value="">Select Developer</option>
                                <?php foreach ($developers as $dev): ?>
                                    <option value="<?php echo $dev['id']; ?>" <?php echo (isset($_POST['assigned_dev_id']) && $_POST['assigned_dev_id'] == $dev['id']) ? 'selected' : ''; ?>>
                                        <?php echo $dev['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($_POST['assigned_dev_id']) && empty($_POST['assigned_dev_id'])): ?>
                                <div class="error">Assigned Developer is required</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_FILES['screenshot']) && empty($_FILES['screenshot']['name'][0])) ? 'invalid' : ''; ?>">
                            <label for="screenshot">Screenshots/Evidence *</label>
                            <input type="file" id="screenshot" name="screenshot[]" accept="image/*" multiple required class="hidden-file-input">
                            <div id="paste-zone" tabindex="0">
                                📋 Click here to browse or paste images (Ctrl+V)
                                <br><small>Drag & drop also supported (max 5MB each)</small>
                            </div>
                            <div id="imagePreviewContainer" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 0.5rem;"></div>
                            <small>Upload or paste screenshots/images here (max 5MB each)</small>
                            <?php if (isset($_FILES['screenshot']) && empty($_FILES['screenshot']['name'][0])): ?>
                                <div class="error">At least one Screenshot/Evidence is required</div>
                            <?php elseif (isset($_FILES['screenshot']) && !empty($_FILES['screenshot']['name'][0]) && !in_array(strtolower(pathinfo($_FILES['screenshot']['name'][0], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                <div class="error">Invalid file type (allowed: jpg, jpeg, png, gif)</div>
                            <?php elseif (isset($_FILES['screenshot']) && !empty($_FILES['screenshot']['name'][0]) && $_FILES['screenshot']['size'][0] > 5000000): ?>
                                <div class="error">File size too large (max 5MB)</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['visible_impact']) && !empty($_POST['visible_impact']) && strlen($_POST['visible_impact']) > 500) ? 'invalid' : ''; ?>">
                            <label for="visible_impact">Visible Impact</label>
                            <textarea id="visible_impact" name="visible_impact" rows="3" 
                                      placeholder="Describe the visible impact on users or functionality"><?php echo isset($_POST['visible_impact']) ? $_POST['visible_impact'] : ''; ?></textarea>
                            <?php if (isset($_POST['visible_impact']) && !empty($_POST['visible_impact']) && strlen($_POST['visible_impact']) > 500): ?>
                                <div class="error">Visible Impact must not exceed 500 characters</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <span>🐛</span> Create Report
                        </button>
                        <a href="dashboard.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>

            <div id="addModal" class="modal">
                <div class="modal-content">
                    <h3 id="modalTitle"></h3>
                    <form id="modalForm" method="POST">
                        <input type="hidden" name="action" id="modalAction">
                        <input type="hidden" name="module_for_submodule" id="moduleForSubmodule">
                        <div class="form-group">
                            <label id="modalLabel"></label>
                            <input type="text" id="modalInput" name="" placeholder="">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Add</button>
                            <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        const submoduleOptions = {
            'TA/DA': ['Local', 'Outstation', 'Tour Plan', 'Claim', 'Acceptance', 'Misc Expense', 'M.Expense','Search Filter','Report'],
            'Report': ['Reimburse', 'Settlement', 'TA/DA Report'],
            'Reimburse': ['TA-DA Booking-SAP', 'BANK Payment Booking - SAP', 'Expense Sheet', 'Payment Sheet'],
            'Approval': ['Travel Approval', 'Advance Approval', 'Claim Approval', 'M.Expense'],
            <?php
            foreach ($custom_modules as $custom_module) {
                $submodules = isset($custom_submodules[$custom_module]) ? json_encode($custom_submodules[$custom_module]) : '[]';
                echo "'" . addslashes($custom_module) . "': $submodules,";
            }
            ?>
        };

        function updateSubmodules() {
            const moduleSelect = document.getElementById('module');
            const submoduleSelect = document.getElementById('submodule');
            const selectedModule = moduleSelect.value;

            submoduleSelect.innerHTML = '<option value="">Select Submodule</option>';

            if (selectedModule && submoduleOptions[selectedModule]) {
                submoduleOptions[selectedModule].forEach(submodule => {
                    const option = document.createElement('option');
                    option.value = submodule;
                    option.textContent = submodule;
                    if (submodule === '<?php echo isset($_POST['submodule']) ? addslashes($_POST['submodule']) : ''; ?>') {
                        option.selected = true;
                    }
                    submoduleSelect.appendChild(option);
                });
            }
        }

        function showModal(type, selectElement = null) {
            const modal = document.getElementById('addModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalLabel = document.getElementById('modalLabel');
            const modalInput = document.getElementById('modalInput');
            const modalAction = document.getElementById('modalAction');
            const moduleForSubmodule = document.getElementById('moduleForSubmodule');

            if (type === 'module') {
                modalTitle.textContent = 'Add New Module';
                modalLabel.textContent = 'Module Name';
                modalInput.name = 'new_module';
                modalInput.placeholder = 'Enter module name';
                modalAction.value = 'add_module';
                moduleForSubmodule.style.display = 'none';
            } else if (type === 'submodule') {
                const moduleSelect = document.getElementById('module');
                if (!moduleSelect.value) {
                    alert('Please select a module first');
                    return;
                }
                modalTitle.textContent = 'Add New Submodule';
                modalLabel.textContent = 'Submodule Name';
                modalInput.name = 'new_submodule';
                modalInput.placeholder = 'Enter submodule name';
                modalAction.value = 'add_submodule';
                moduleForSubmodule.value = moduleSelect.value;
                moduleForSubmodule.style.display = 'block';
            } else if (type === 'project') {
                modalTitle.textContent = 'Add New Project';
                modalLabel.textContent = 'Project Name';
                modalInput.name = 'new_project';
                modalInput.placeholder = 'Enter project name';
                modalAction.value = 'add_project';
                moduleForSubmodule.style.display = 'none';
            }

            modal.style.display = 'block';
            modalInput.value = '';
        }

        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('modalInput').value = '';
            document.getElementById('moduleForSubmodule').value = '';
        }

        document.getElementById('modalForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const action = document.getElementById('modalAction').value;
            const formData = new FormData(this);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (action === 'add_module') {
                            const moduleSelect = document.getElementById('module');
                            const newOption = document.createElement('option');
                            newOption.value = data.module;
                            newOption.textContent = data.module;
                            newOption.selected = true;
                            moduleSelect.appendChild(newOption);
                            submoduleOptions[data.module] = [];
                            updateSubmodules();
                        } else if (action === 'add_submodule') {
                            const submoduleSelect = document.getElementById('submodule');
                            const newOption = document.createElement('option');
                            newOption.value = data.submodule;
                            newOption.textContent = data.submodule;
                            newOption.selected = true;
                            submoduleSelect.appendChild(newOption);
                            submoduleOptions[data.module] = submoduleOptions[data.module] || [];
                            submoduleOptions[data.module].push(data.submodule);
                        } else if (action === 'add_project') {
                            const projectSelect = document.getElementById('project_id');
                            const newOption = document.createElement('option');
                            newOption.value = data.id;
                            newOption.textContent = data.name;
                            newOption.selected = true;
                            projectSelect.appendChild(newOption);
                        }
                        closeModal();
                        alert(data.message);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred');
                });
        });

        // Image upload functionality with paste, drag & drop, and click
        const fileInput = document.getElementById('screenshot');
        const pasteZone = document.getElementById('paste-zone');
        const imagePreviewContainer = document.getElementById('imagePreviewContainer');
        let selectedFiles = [];

        // Click to browse files
        pasteZone.addEventListener('click', () => {
            fileInput.click();
        });

        // Focus styling
        pasteZone.addEventListener('focus', () => {
            pasteZone.style.borderColor = "#0056b3";
        });

        pasteZone.addEventListener('blur', () => {
            pasteZone.style.borderColor = "#007bff";
        });

        // Enable paste on hover
        pasteZone.addEventListener('mouseenter', () => {
            pasteZone.focus();
        });

        pasteZone.addEventListener('mouseleave', () => {
            pasteZone.blur();
        });

        // Paste functionality
        pasteZone.addEventListener('paste', function (event) {
            let items = (event.clipboardData || event.originalEvent.clipboardData).items;
            let foundImage = false;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf("image") === 0) {
                    let file = items[i].getAsFile();
                    if (validateFile(file)) {
                        selectedFiles.push(file);
                        updateFileInput();
                        updatePreview();
                        foundImage = true;
                    }
                }
            }
            if (!foundImage) {
                alert("Clipboard does not contain an image.");
            }
            event.preventDefault();
        });

        // Drag and drop functionality
        pasteZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            pasteZone.classList.add('dragover');
        });

        pasteZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            pasteZone.classList.remove('dragover');
        });

        pasteZone.addEventListener('drop', function(e) {
            e.preventDefault();
            pasteZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            for (let file of files) {
                if (file.type.startsWith('image/') && validateFile(file)) {
                    selectedFiles.push(file);
                }
            }
            updateFileInput();
            updatePreview();
        });

        // File input change
        fileInput.addEventListener('change', function() {
            const newFiles = Array.from(fileInput.files);
            for (let file of newFiles) {
                if (validateFile(file)) {
                    selectedFiles.push(file);
                }
            }
            updateFileInput();
            updatePreview();
        });

        function validateFile(file) {
            const allowed = ['image/jpeg', 'image/png', 'image/gif'];
            if (!allowed.includes(file.type)) {
                alert('Invalid file type (allowed: jpg, jpeg, png, gif)');
                return false;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert('File size too large (max 5MB)');
                return false;
            }
            return true;
        }

        function updateFileInput() {
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            fileInput.files = dt.files;
        }

        function updatePreview() {
            imagePreviewContainer.innerHTML = '';
            if (selectedFiles.length === 0) {
                pasteZone.innerHTML = '📋 Click here to browse or paste images (Ctrl+V)<br><small>Drag & drop also supported</small>';
                return;
            }

            pasteZone.innerHTML = `✅ ${selectedFiles.length} image${selectedFiles.length > 1 ? 's' : ''} selected<br><small>Click to add more</small>`;

            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = e => {
                    const previewWrapper = document.createElement('div');
                    previewWrapper.style.position = 'relative';
                    previewWrapper.style.display = 'inline-block';

                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.maxWidth = '200px';
                    img.style.maxHeight = '200px';
                    img.style.borderRadius = 'var(--border-radius)';
                    img.style.border = '1px solid var(--border-color)';
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.innerHTML = '×';
                    removeBtn.style.position = 'absolute';
                    removeBtn.style.top = '5px';
                    removeBtn.style.right = '5px';
                    removeBtn.style.background = 'var(--error-color)';
                    removeBtn.style.color = 'white';
                    removeBtn.style.border = 'none';
                    removeBtn.style.borderRadius = '50%';
                    removeBtn.style.width = '24px';
                    removeBtn.style.height = '24px';
                    removeBtn.style.cursor = 'pointer';
                    removeBtn.style.fontSize = '16px';
                    removeBtn.style.display = 'flex';
                    removeBtn.style.alignItems = 'center';
                    removeBtn.style.justifyContent = 'center';
                    removeBtn.title = 'Remove image';
                    
                    removeBtn.addEventListener('click', () => {
                        selectedFiles.splice(index, 1);
                        updateFileInput();
                        updatePreview();
                    });

                    previewWrapper.appendChild(img);
                    previewWrapper.appendChild(removeBtn);
                    imagePreviewContainer.appendChild(previewWrapper);
                };
                reader.readAsDataURL(file);
            });
        }

        // Initialize submodules on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSubmodules();
        });
    </script>
</body>
</html>