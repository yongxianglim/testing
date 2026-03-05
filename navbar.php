<?php
$role = currentRole();
$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$initials = strtoupper(substr($user, 0, 2));
?>
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
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <?php if (in_array($role, ['VIEWER', 'EDITOR', 'DEVELOPER'])): ?>
            <a href="viewer.php" class="<?= $currentPage === 'viewer.php' ? 'active' : '' ?>"><i class="fas fa-eye"></i><span>View Records</span></a>
            <a href="media_compare.php" class="<?= $currentPage === 'media_compare.php' ? 'active' : '' ?>"><i class="fas fa-images"></i><span>Media Compare</span></a>
        <?php endif; ?>
        <?php if (in_array($role, ['EDITOR', 'DEVELOPER'])): ?>
            <a href="editor.php" class="<?= $currentPage === 'editor.php' ? 'active' : '' ?>"><i class="fas fa-pen-to-square"></i><span>Insert / Edit</span></a>
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