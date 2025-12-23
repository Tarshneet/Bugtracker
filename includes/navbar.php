<nav class="navbar">
    <div class="navbar-brand">
        <img src="logo.ico" alt="Logo" class="navbar-logo">
        <h1><?php echo SITE_NAME; ?></h1>
    </div>
    
    <div class="navbar-nav">
        <a href="dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>">
            <span>🏠</span> Dashboard
        </a>
        
        <?php if (getUserRole() == 'tester'): ?>
            <a href="create_bug.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'create_bug.php') ? 'active' : ''; ?>">
                <span>🐛</span> Report Bug
            </a>
            <a href="my_bugs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'my_bugs.php') ? 'active' : ''; ?>">
                <span>📋</span> My Bugs
            </a>
            <a href="chat_portal.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'chat_portal.php') ? 'active' : ''; ?>">
                <span>💬</span> View Chat
            </a>
            <a href="generate_report.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'generate_report.php') ? 'active' : ''; ?>">
                <span>📊</span> Reports
            </a>
            <a href="bug_shift.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'bug_shift.php') ? 'active' : ''; ?>">
                 <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle; margin-right: 8px;">
                    <path d="m8 2 1.88 1.88"/>
                    <path d="M14.12 3.88 16 2"/>
                    <path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/>
                    <path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/>
                    <path d="M12 20v-9"/>
                    <path d="M6.53 9C4.6 8.8 3 7.1 3 5"/>
                    <path d="M6 13H2"/>
                    <path d="M3 21c0-2.1 1.7-3.9 3.8-4"/>
                    <path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/>
                    <path d="M22 13h-4"/>
                    <path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/>
                    <circle cx="12" cy="13" r="2"/>
                    <path d="m17 17-1.5-1.5"/>
                    <path d="m6.5 6.5 1.5 1.5"/>
                </svg> Bug Shift
            </a>
        <?php elseif (getUserRole() == 'developer'): ?>
            <a href="developer_bugs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'developer_bugs.php') ? 'active' : ''; ?>">
                <span>🔧</span> Assigned Bugs
            </a>
            <a href="chat_portal.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'chat_portal.php') ? 'active' : ''; ?>">
                <span>💬</span> View Chat
            </a>
             <a href="bug_shift.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'bug_shift.php') ? 'active' : ''; ?>">
                 <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle; margin-right: 8px;">
                    <path d="m8 2 1.88 1.88"/>
                    <path d="M14.12 3.88 16 2"/>
                    <path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/>
                    <path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/>
                    <path d="M12 20v-9"/>
                    <path d="M6.53 9C4.6 8.8 3 7.1 3 5"/>
                    <path d="M6 13H2"/>
                    <path d="M3 21c0-2.1 1.7-3.9 3.8-4"/>
                    <path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/>
                    <path d="M22 13h-4"/>
                    <path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/>
                    <circle cx="12" cy="13" r="2"/>
                    <path d="m17 17-1.5-1.5"/>
                    <path d="m6.5 6.5 1.5 1.5"/>
                </svg> Bug Shift
            </a>
        <?php endif; ?>
    </div>
    
    <div class="navbar-user">
        <div class="user-menu">
            <button class="user-button" onclick="toggleUserMenu()">
                <span class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 2)); ?></span>
                <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <span class="user-role"><?php echo ucfirst(getUserRole()); ?></span>
            </button>
            <div class="user-dropdown" id="userDropdown">
                <a href="task_dashboard.php" class="dropdown-item">
                    <span>👤</span> Tasks
                </a>
                <a href="analysis.php" class="dropdown-item">
                    <span>⚙️</span> Analytics
                </a>
                <div class="dropdown-divider"></div>
                
                <!-- Theme Toggle -->
                <button class="theme-toggle" onclick="toggleTheme()">
                    <span style="display: flex; align-items: center; gap: 0.5rem;">
                        <span id="themeIcon">🌙</span>
                        <span id="themeText">Dark Mode</span>
                    </span>
                    <div class="theme-switch" id="themeSwitch"></div>
                </button>
                
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item">
                    <span>🚪</span> Logout
                </a>
            </div>
        </div>
    </div>
</nav>

<style>
/* Logo styling for navbar with dark mode support */
.navbar-brand {
    display: flex;
    align-items: center;
    gap: 0;
}

.navbar-logo {
    margin-left:25px;
    height: 32px;
    width: auto;
    margin-right: 12px;
    vertical-align: middle;
    border-radius: 4px;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.1));
    transition: all 0.3s ease;
}

.navbar-brand h1 {
    margin: 0;
    display: flex;
    align-items: center;
}

/* Dark mode logo adjustments */
[data-theme="dark"] .navbar-logo {
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3)) brightness(1.1);
}

/* Responsive logo sizing */
@media (max-width: 768px) {
    .navbar-logo {
        height: 28px;
        margin-right: 8px;
    }
}

@media (max-width: 480px) {
    .navbar-logo {
        height: 24px;
        margin-right: 6px;
    }
}
</style>

<script>
// Theme Management
function initializeTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    const themeSwitch = document.getElementById('themeSwitch');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');
    
    document.documentElement.setAttribute('data-theme', savedTheme);
    
    if (savedTheme === 'dark') {
        themeSwitch.classList.add('active');
        themeIcon.textContent = '☀️';
        themeText.textContent = 'Light Mode';
    } else {
        themeSwitch.classList.remove('active');
        themeIcon.textContent = '🌙';
        themeText.textContent = 'Dark Mode';
    }
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    const themeSwitch = document.getElementById('themeSwitch');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');
    
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    if (newTheme === 'dark') {
        themeSwitch.classList.add('active');
        themeIcon.textContent = '☀️';
        themeText.textContent = 'Light Mode';
    } else {
        themeSwitch.classList.remove('active');
        themeIcon.textContent = '🌙';
        themeText.textContent = 'Dark Mode';
    }
}

function toggleUserMenu() {
    const dropdown = document.getElementById('userDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const userMenu = document.querySelector('.user-menu');
    const dropdown = document.getElementById('userDropdown');
    
    if (!userMenu.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// Initialize theme on page load
document.addEventListener('DOMContentLoaded', initializeTheme);
</script>
