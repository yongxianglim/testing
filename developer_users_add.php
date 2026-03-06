<?php
require 'auth.php';
requireRole(['DEVELOPER']);
require 'db.php';

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = $_POST['role'] ?? '';
    $errors = [];

    if (!$username) $errors[] = "Username is required.";
    elseif (strlen($username) < 3) $errors[] = "Username must be at least 3 characters.";
    elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) $errors[] = "Username: letters, numbers, underscores only.";

    if (!$password) $errors[] = "Password is required.";
    elseif (strlen($password) < 4) $errors[] = "Password must be at least 4 characters.";

    if (!in_array($role, ['VIEWER', 'EDITOR'])) $errors[] = "Only VIEWER or EDITOR accounts can be created.";

    if (empty($errors)) {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $chk->bind_param("s", $username);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = "Username \"$username\" already exists.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?,?,?)");
        $stmt->bind_param("sss", $username, $password, $role);
        $stmt->execute();
        $newId = $conn->insert_id;
        $msg = "User \"$username\" created as $role (ID: $newId).";
        $msgType = 'success';
    } else {
        $msg = implode(" ", $errors);
        $msgType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'head.php'; ?>
    <title>Add User — Subjective Portal</title>
</head>

<body>
    <?php include 'orbs.php'; ?>
    <div class="app-layout">
        <?php include 'navbar.php'; ?>
        <?php include 'modal.php'; ?>

        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-user-plus" style="color:#6B8DB5;margin-right:8px;font-size:20px;"></i> Add New User</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / Add User</div>
            </div>

            <div class="main-body">

                <?php if ($msg): ?>
                    <div class="msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <div class="card">
                    <h2><i class="fas fa-id-card"></i> Create User Account</h2>
                    <form method="POST" id="formCreateUser">

                        <label><i class="fas fa-user"></i> Username:</label>
                        <p class="text-muted-sm" style="margin:2px 0 6px;">Minimum 3 characters. Letters, numbers, and underscores only.</p>
                        <input type="text" name="username" style="width:350px;" required placeholder="Enter username"><br><br>

                        <label><i class="fas fa-lock"></i> Password:</label>
                        <p class="text-muted-sm" style="margin:2px 0 6px;">Minimum 4 characters.</p>
                        <input type="text" name="password" style="width:350px;" required placeholder="Enter password"><br><br>

                        <label><i class="fas fa-shield-halved"></i> Role:</label><br>
                        <select name="role" required style="margin-top:6px;">
                            <option value="">-- Select Role --</option>
                            <option value="VIEWER">VIEWER — Can only view records</option>
                            <option value="EDITOR">EDITOR — Can view, insert, and edit records</option>
                        </select>
                        <p class="text-muted-sm" style="margin:6px 0 0;"><i class="fas fa-info-circle"></i> DEVELOPER accounts cannot be created from this page.</p>
                        <br><br>

                        <button type="button" class="btn btn-success" onclick="confirmSubmit('formCreateUser','confirm','Create User','Create this new user account? They will be able to log in immediately.')"><i class="fas fa-user-plus"></i> Create User</button>

                    </form>
                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
</body>

</html>
<?php $conn->close(); ?>