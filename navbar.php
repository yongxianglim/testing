<?php
$role = currentRole();
$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$initials = strtoupper(substr($user, 0, 2));
?>
<style>
/* ── Sidebar Collapse Toggle (Desktop) ──────── */
.sidebar-header { position: relative; }
.sidebar-collapse-btn {
    position: absolute;
    top: 26px;
    right: 12px;
    width: 28px;
    height: 28px;
    border: 1px solid rgba(107, 141, 181, 0.12);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(8px);
    color: #A0AEC0;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    padding: 0;
}
.sidebar-collapse-btn:hover {
    background: rgba(107, 141, 181, 0.1);
    color: #6B8DB5;
}
.sidebar-expand-btn {
    display: none;
    position: fixed;
    top: 16px;
    left: 16px;
    z-index: 200;
    width: 40px;
    height: 40px;
    border: 1px solid rgba(107, 141, 181, 0.1);
    border-radius: 11px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(12px);
    color: #6B8DB5;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    padding: 0;
}
.sidebar-expand-btn:hover {
    background: rgba(107, 141, 181, 0.12);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}
.sidebar {
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                min-width 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                padding 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.main-content {
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.sidebar-collapsed .sidebar {
    width: 0 !important;
    min-width: 0 !important;
    overflow: hidden;
    opacity: 0;
    padding: 0 !important;
    border: none !important;
    pointer-events: none;
}
.sidebar-collapsed .sidebar-expand-btn {
    display: flex;
}
.sidebar-collapsed .main-content {
    margin-left: 0 !important;
}
.sidebar-collapsed .main-header {
    padding-left: 68px;
}
@media (max-width: 768px) {
    .sidebar-collapse-btn,
    .sidebar-expand-btn {
        display: none !important;
    }
}
</style>
<button class="sidebar-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
<div class="sidebar">
    <div class="sidebar-header">
        <a href="viewer.php" class="brand">
            <div class="brand-icon"><i class="fas fa-flask"></i></div>
            <div>
                <div class="brand-text">Subjective</div>
                <div class="brand-sub">Testing Portal</div>
            </div>
        </a>
        <button class="sidebar-collapse-btn" onclick="toggleSidebarCollapse()" title="Toggle sidebar">
            <i class="fas fa-angles-left"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <?php if (in_array($role, ['VIEWER', 'EDITOR', 'DEVELOPER'])): ?>
            <a href="viewer.php" class="<?= $currentPage === 'viewer.php' ? 'active' : '' ?>"><i class="fas fa-eye"></i><span>View Records</span></a>
            <a href="media_compare.php" class="<?= $currentPage === 'media_compare.php' ? 'active' : '' ?>"><i class="fas fa-images"></i><span>Media Compare</span></a>
        <?php endif; ?>
        <?php if (in_array($role, ['EDITOR', 'DEVELOPER'])): ?>
            <a href="editor.php" class="<?= ($currentPage === 'editor.php' || $currentPage === 'editor_media_descriptions.php') ? 'active' : '' ?>"><i class="fas fa-pen-to-square"></i><span>Insert / Edit</span></a>
        <?php endif; ?>
        <?php if ($role === 'DEVELOPER'): ?>
            <div class="nav-label" style="margin-top:10px;">Developer</div>
            <a href="developer_fields.php" class="<?= $currentPage === 'developer_fields.php' ? 'active' : '' ?>"><i class="fas fa-table-columns"></i><span>Manage Fields</span></a>
            <a href="developer_users.php" class="<?= $currentPage === 'developer_users.php' ? 'active' : '' ?>"><i class="fas fa-users-gear"></i><span>Manage Users</span></a>
            <a href="developer_users_add.php" class="<?= $currentPage === 'developer_users_add.php' ? 'active' : '' ?>"><i class="fas fa-user-plus"></i><span>Add User</span></a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($user) ?></div>
                <div class="user-role"><?= htmlspecialchars($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
    </div>
</div>
<button class="sidebar-expand-btn" onclick="toggleSidebarCollapse()" title="Expand sidebar">
    <i class="fas fa-angles-right"></i>
</button>
<script>
function toggleSidebarCollapse() {
    var layout = document.querySelector('.app-layout');
    if (!layout) return;
    layout.classList.toggle('sidebar-collapsed');
    // Persist preference
    try {
        localStorage.setItem('sidebarCollapsed', layout.classList.contains('sidebar-collapsed') ? '1' : '0');
    } catch(e) {}
}
// Restore sidebar state on load
document.addEventListener('DOMContentLoaded', function() {
    try {
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            var layout = document.querySelector('.app-layout');
            if (layout) layout.classList.add('sidebar-collapsed');
        }
    } catch(e) {}
});
</script>