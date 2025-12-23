<?php
// Set timezone to IST (Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';
requireRole('tester');

$bugId = intval($_GET['id'] ?? 0);
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

// Get bug details and verify ownership
$bug = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM bug_tickets WHERE id = ? AND created_by = ? AND status = 'pending'");
    $stmt->execute([$bugId, $_SESSION['user_id']]);
    $bug = $stmt->fetch();
    
    if (!$bug) {
        header('Location: my_bugs.php');
        exit();
    }
} catch (PDOException $e) {
    $error = 'Failed to load bug details';
}

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

// Handle bug update submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $type = trim(sanitizeInput($_POST['type']));
    $title = trim(sanitizeInput($_POST['title']));
    $project_id = trim(sanitizeInput($_POST['project_id']));
    $module = trim(sanitizeInput($_POST['module']));
    $submodule = trim(sanitizeInput($_POST['submodule']));
    $description = trim(sanitizeInput($_POST['description']));
    $priority = trim(sanitizeInput($_POST['priority']));
    $visible_impact = trim(sanitizeInput($_POST['visible_impact']));
    $assigned_dev_id = trim(sanitizeInput($_POST['assigned_dev_id']));
    
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
    
    // Handle file upload
    $screenshot = $bug['screenshot'];
    if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['screenshot']['name'];
        $filesize = $_FILES['screenshot']['size'];
        $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($filetype, $allowed) && $filesize < 5000000) {
            // Delete existing screenshot if it exists
            if ($screenshot && file_exists(UPLOAD_DIR . $screenshot)) {
                unlink(UPLOAD_DIR . $screenshot);
            }
            $screenshot = time() . '_' . $filename;
            move_uploaded_file($_FILES['screenshot']['tmp_name'], UPLOAD_DIR . $screenshot);
        } else {
            $error = 'Invalid file type or size too large (max 5MB)';
        }
    }
    
    if (empty($error)) {
        try {
            // Prepend type to title
            $full_title = $type . ':' . $title;
            $stmt = $pdo->prepare("
                UPDATE bug_tickets 
                SET title = ?, project_id = ?, module = ?, submodule = ?, description = ?, priority = ?, visible_impact = ?, screenshot = ?, assigned_dev_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND created_by = ?
            ");
            $stmt->execute([$full_title, $project_id, $module, $submodule, $description, $priority, $visible_impact, $screenshot, $assigned_dev_id, $bugId, $_SESSION['user_id']]);
            
            $success = 'Bug report updated successfully!';
            
            // Update bug object for display
            $bug['title'] = $full_title;
            $bug['project_id'] = $project_id;
            $bug['module'] = $module;
            $bug['submodule'] = $submodule;
            $bug['description'] = $description;
            $bug['priority'] = $priority;
            $bug['visible_impact'] = $visible_impact;
            $bug['screenshot'] = $screenshot;
            $bug['assigned_dev_id'] = $assigned_dev_id;
        } catch (PDOException $e) {
            $error = 'Failed to update bug report: ' . $e->getMessage();
        }
    }
}

// Extract type and title
$bug_type = explode(':', $bug['title'], 2)[0];
$bug_title = explode(':', $bug['title'], 2)[1] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Bug #<?php echo $bug['id']; ?> - <?php echo SITE_NAME; ?></title>
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
        .modal-content {
            background: var(--surface-color);
            margin: 15% auto;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 400px;
        }
        .modal-content h3 {
            margin-bottom: 1.5rem;
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
            color: red;
            font-size: 0.9rem;
            margin-top: 0.25rem;
            display: block;
        }
        .form-group.invalid input, .form-group.invalid select, .form-group.invalid textarea {
            border-color: red;
        }
        #imagePreview {
            max-width: 200px;
            max-height: 200px;
            margin-top: 0.5rem;
            display: none;
        }
        .current-screenshot img {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Edit Bug or Change #<?php echo $bug['id']; ?></h1>
                <p>Update details for pending bug or change request</p>
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

            <div class="form-container">
                <form method="POST" enctype="multipart/form-data" class="bug-form" id="bugForm">
                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['type']) && empty($_POST['type'])) ? 'invalid' : ''; ?>">
                            <label for="type">Type *</label>
                            <select id="type" name="type" required>
                                <option value="">Select Type</option>
                                <option value="Bug" <?php echo ($bug_type == 'Bug') ? 'selected' : ''; ?>>Bug</option>
                                <option value="Change" <?php echo ($bug_type == 'Change') ? 'selected' : ''; ?>>Change</option>
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
                                   value="<?php echo htmlspecialchars($bug_title); ?>"
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
                                    <option value="<?php echo $project['id']; ?>" <?php echo ($bug['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
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
                                <option value="TA/DA" <?php echo ($bug['module'] == 'TA/DA') ? 'selected' : ''; ?>>TA/DA</option>
                                <option value="Report" <?php echo ($bug['module'] == 'Report') ? 'selected' : ''; ?>>Report</option>
                                <option value="Reimburse" <?php echo ($bug['module'] == 'Reimburse') ? 'selected' : ''; ?>>Reimburse</option>
                                <option value="Approval" <?php echo ($bug['module'] == 'Approval') ? 'selected' : ''; ?>>Approval</option>
                                <?php foreach ($custom_modules as $custom_module): ?>
                                    <option value="<?php echo htmlspecialchars($custom_module); ?>" <?php echo ($bug['module'] == $custom_module) ? 'selected' : ''; ?>>
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
                                if ($bug['module'] && isset($custom_submodules[$bug['module']])) {
                                    foreach ($custom_submodules[$bug['module']] as $custom_submodule) {
                                        echo '<option value="' . htmlspecialchars($custom_submodule) . '" ' . ($bug['submodule'] == $custom_submodule ? 'selected' : '') . '>' . htmlspecialchars($custom_submodule) . '</option>';
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
                                      placeholder="Provide detailed steps to reproduce the bug or describe the change request"><?php echo htmlspecialchars($bug['description']); ?></textarea>
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
                                <option value="P1" <?php echo ($bug['priority'] == 'P1') ? 'selected' : ''; ?>>P1 - Critical (System crash, data loss)</option>
                                <option value="P2" <?php echo ($bug['priority'] == 'P2') ? 'selected' : ''; ?>>P2 - High (Major feature broken)</option>
                                <option value="P3" <?php echo ($bug['priority'] == 'P3') ? 'selected' : ''; ?>>P3 - Medium (Minor feature issues)</option>
                                <option value="P4" <?php echo ($bug['priority'] == 'P4') ? 'selected' : ''; ?>>P4 - Low (Cosmetic issues)</option>
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
                                    <option value="<?php echo $dev['id']; ?>" <?php echo ($bug['assigned_dev_id'] == $dev['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dev['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($_POST['assigned_dev_id']) && empty($_POST['assigned_dev_id'])): ?>
                                <div class="error">Assigned Developer is required</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] == 0 && !in_array(strtolower(pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'])) ? 'invalid' : ''; ?>">
                            <label for="screenshot">Screenshot/Evidence</label>
                            <input type="file" id="screenshot" name="screenshot" accept="image/*" onchange="previewImage()">
                            <small>Upload a new screenshot to replace the existing one (max 5MB)</small>
                            <?php if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] == 0 && !in_array(strtolower(pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                <div class="error">Invalid file type (allowed: jpg, jpeg, png, gif)</div>
                            <?php elseif (isset($_FILES['screenshot']) && $_FILES['screenshot']['size'] > 5000000): ?>
                                <div class="error">File size too large (max 5MB)</div>
                            <?php endif; ?>
                            <img id="imagePreview" src="" alt="Preview of uploaded image">
                            <?php if ($bug['screenshot']): ?>
                                <div class="current-screenshot" style="margin-top: 1rem;">
                                    <p><strong>Current Screenshot:</strong></p>
                                    <img src="<?php echo UPLOAD_DIR . $bug['screenshot']; ?>" alt="Current Screenshot" 
                                         style="max-width: 200px; height: auto; border-radius: 4px;"
                                         onclick="openImageModal(this.src)">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group <?php echo (isset($_POST['visible_impact']) && !empty($_POST['visible_impact']) && strlen($_POST['visible_impact']) > 500) ? 'invalid' : ''; ?>">
                            <label for="visible_impact">Visible Impact</label>
                            <textarea id="visible_impact" name="visible_impact" rows="3" 
                                      placeholder="Describe the visible impact on users or functionality"><?php echo htmlspecialchars($bug['visible_impact']); ?></textarea>
                            <?php if (isset($_POST['visible_impact']) && !empty($_POST['visible_impact']) && strlen($_POST['visible_impact']) > 500): ?>
                                <div class="error">Visible Impact must not exceed 500 characters</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <span>💾</span> Update Report
                        </button>
                        <a href="my_bugs.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>

            <!-- Modal for adding new module/submodule/project -->
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

            <!-- Image Modal -->
            <div id="imageModal" class="modal" onclick="closeImageModal()">
                <div class="modal-content">
                    <span class="close" onclick="closeImageModal()" style="position: absolute; right: 1rem; top: 1rem; font-size: 1.5rem; cursor: pointer;">×</span>
                    <img id="modalImage" src="" alt="Screenshot">
                </div>
            </div>
        </main>
    </div>

    <script>
        const submoduleOptions = {
            'TA/DA': ['Local', 'Outstation', 'Tour Plan', 'Claim', 'Acceptance', 'Misc Expense', 'M.Expense'],
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

            // Clear existing options
            submoduleSelect.innerHTML = '<option value="">Select Submodule</option>';

            // Populate submodules
            if (selectedModule && submoduleOptions[selectedModule]) {
                submoduleOptions[selectedModule].forEach(submodule => {
                    const option = document.createElement('option');
                    option.value = submodule;
                    option.textContent = submodule;
                    if (submodule === '<?php echo addslashes($bug['submodule']); ?>') {
                        option.selected = true;
                    }
                    submoduleSelect.appendChild(option);
                });
            }
        }

        function showModal(type) {
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

        function openImageModal(src) {
            document.getElementById('imageModal').style.display = 'block';
            document.getElementById('modalImage').src = src;
        }

        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
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
                        updateSubmodules();
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

        function previewImage() {
            const fileInput = document.getElementById('screenshot');
            const preview = document.getElementById('imagePreview');
            const file = fileInput.files[0];

            preview.style.display = 'none';
            const errorDiv = fileInput.nextElementSibling.nextElementSibling;

            if (!file) {
                errorDiv.textContent = '';
                return;
            }

            const allowed = ['image/jpeg', 'image/png', 'image/gif'];
            if (!allowed.includes(file.type)) {
                errorDiv.textContent = 'Invalid file type (allowed: jpg, jpeg, png, gif)';
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                errorDiv.textContent = 'File size too large (max 5MB)';
                return;
            }

            errorDiv.textContent = '';

            const reader = new FileReader();
            reader.onload = e => {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        // Initialize submodules on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSubmodules();
        });
    </script>
</body>
</html>