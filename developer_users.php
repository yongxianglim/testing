<?php
require 'auth.php';
requireRole(['DEVELOPER']);
require 'db.php';

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_role') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';
        if (!$uid) {
            $msg = "Invalid user ID.";
            $msgType = 'error';
        } elseif (!in_array($newRole, ['VIEWER', 'EDITOR', 'DEVELOPER'])) {
            $msg = "Invalid role.";
            $msgType = 'error';
        } elseif ($uid == $_SESSION['user_id']) {
            $msg = "You cannot change your own role.";
            $msgType = 'error';
        } else {
            $nr = $conn->prepare("SELECT username, role FROM users WHERE user_id = ?");
            $nr->bind_param("i", $uid);
            $nr->execute();
            $nRow = $nr->get_result()->fetch_assoc();
            if (!$nRow) {
                $msg = "User not found.";
                $msgType = 'error';
            } else {
                $oldRole = $nRow['role'];
                $s = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
                $s->bind_param("si", $newRole, $uid);
                $s->execute();
                $msg = "User \"{$nRow['username']}\" role changed from $oldRole to $newRole.";
                $msgType = 'success';
            }
        }
    } elseif ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if (!$uid) {
            $msg = "Invalid user ID.";
            $msgType = 'error';
        } elseif ($uid == $_SESSION['user_id']) {
            $msg = "You cannot delete your own account.";
            $msgType = 'error';
        } else {
            $nr = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
            $nr->bind_param("i", $uid);
            $nr->execute();
            $nRow = $nr->get_result()->fetch_assoc();
            if (!$nRow) {
                $msg = "User not found.";
                $msgType = 'error';
            } else {
                $s = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $s->bind_param("i", $uid);
                $s->execute();
                $msg = "User \"{$nRow['username']}\" permanently deleted.";
                $msgType = 'success';
            }
        }
    }
}

$users = $conn->query("SELECT user_id, username, role, created_at FROM users ORDER BY user_id");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'head.php'; ?>
    <title>Manage Users — Subjective Portal</title>
</head>

<body>
    <?php include 'orbs.php'; ?>
    <div class="app-layout">
        <?php include 'navbar.php'; ?>
        <?php include 'modal.php'; ?>

        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-users-gear" style="color:#6B8DB5;margin-right:8px;font-size:20px;"></i> Manage Users</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / Manage Users</div>
            </div>

            <div class="main-body">

                <?php if ($msg): ?>
                    <div class="msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <div class="card">
                    <h2><i class="fas fa-users"></i> All Users</h2>
                    <div class="overflow-x">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Change Role</th>
                                    <th>Delete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIdx = 0;
                                while ($u = $users->fetch_assoc()): $rowIdx++; ?>
                                    <tr>
                                        <td style="font-weight:600;color:#6B8DB5;"><?= (int)$u['user_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                        <td>
                                            <?php
                                            $badgeClass = 'badge-viewer';
                                            if ($u['role'] === 'EDITOR') $badgeClass = 'badge-editor';
                                            elseif ($u['role'] === 'DEVELOPER') $badgeClass = 'badge-developer';
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($u['role']) ?></span>
                                        </td>
                                        <td class="text-muted-sm"><?= htmlspecialchars($u['created_at']) ?></td>
                                        <td>
                                            <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                                <form method="POST" id="formUpdateRole<?= $rowIdx ?>" class="inline-form">
                                                    <input type="hidden" name="action" value="update_role">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                    <select name="new_role">
                                                        <option value="VIEWER" <?= $u['role'] === 'VIEWER' ? 'selected' : '' ?>>VIEWER</option>
                                                        <option value="EDITOR" <?= $u['role'] === 'EDITOR' ? 'selected' : '' ?>>EDITOR</option>
                                                        <option value="DEVELOPER" <?= $u['role'] === 'DEVELOPER' ? 'selected' : '' ?>>DEVELOPER</option>
                                                    </select>
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="confirmSubmit('formUpdateRole<?= $rowIdx ?>','warning','Change Role','Change role for &quot;<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>&quot;? This affects their access immediately.')"><i class="fas fa-save"></i> Update</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted-sm" style="font-style:italic;"><i class="fas fa-user-check"></i> Current user</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                                <form method="POST" id="formDeleteUser<?= $rowIdx ?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmSubmit('formDeleteUser<?= $rowIdx ?>','danger','Delete User','Permanently delete &quot;<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>&quot;? This cannot be undone.')"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted-sm">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
</body>

</html>
<?php $conn->close(); ?>