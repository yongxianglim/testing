<?php
require 'auth.php';
requireRole(['DEVELOPER']);
require 'db.php';
$msg = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_master_field') {
        $fn = trim($_POST['field_name'] ?? '');
        $vt = $_POST['value_type'] ?? 'text';
        $at = $_POST['apply_to'] ?? [];
        if (!$fn) {
            $msg = "Field name required.";
            $msgType = 'error';
        } elseif (!in_array($vt, ['text', 'non_negative_decimal'])) {
            $msg = "Invalid type.";
            $msgType = 'error';
        } else {
            $c = $conn->prepare("SELECT master_field_id FROM master_fields WHERE field_name=?");
            $c->bind_param("s", $fn);
            $c->execute();
            if ($c->get_result()->num_rows > 0) {
                $msg = "\"$fn\" exists.";
                $msgType = 'error';
            } else {
                $s = $conn->prepare("INSERT INTO master_fields (field_name,value_type) VALUES (?,?)");
                $s->bind_param("ss", $fn, $vt);
                $s->execute();
                $nid = $conn->insert_id;
                $ac = 0;
                foreach ($at as $tid) {
                    $tid = (int)$tid;
                    $e = $conn->query("SELECT field_def_id FROM field_definitions WHERE testing_id=$tid AND field_key=$nid")->num_rows;
                    if (!$e) {
                        $mo = $conn->query("SELECT IFNULL(MAX(display_order),0)+1 AS n FROM field_definitions WHERE testing_id=$tid")->fetch_assoc()['n'];
                        $i = $conn->prepare("INSERT INTO field_definitions (testing_id,field_key,field_name,value_type,display_order) VALUES (?,?,?,?,?)");
                        $i->bind_param("iissi", $tid, $nid, $fn, $vt, $mo);
                        $i->execute();
                        $ac++;
                    }
                }
                $msg = "\"$fn\" created (ID:$nid), applied to $ac record(s).";
                $msgType = 'success';
            }
        }
    } elseif ($action === 'rename_field') {
        $mid = (int)($_POST['master_field_id'] ?? 0);
        $nn = trim($_POST['new_field_name'] ?? '');
        if (!$mid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } elseif (!$nn) {
            $msg = "Name required.";
            $msgType = 'error';
        } else {
            $c = $conn->prepare("SELECT master_field_id FROM master_fields WHERE field_name=? AND master_field_id!=?");
            $c->bind_param("si", $nn, $mid);
            $c->execute();
            if ($c->get_result()->num_rows > 0) {
                $msg = "\"$nn\" exists.";
                $msgType = 'error';
            } else {
                $o = $conn->prepare("SELECT field_name FROM master_fields WHERE master_field_id=?");
                $o->bind_param("i", $mid);
                $o->execute();
                $on = $o->get_result()->fetch_assoc()['field_name'] ?? '';
                $s = $conn->prepare("UPDATE master_fields SET field_name=? WHERE master_field_id=?");
                $s->bind_param("si", $nn, $mid);
                $s->execute();
                $s2 = $conn->prepare("UPDATE field_definitions SET field_name=? WHERE field_key=?");
                $s2->bind_param("si", $nn, $mid);
                $s2->execute();
                $msg = "Renamed \"$on\" to \"$nn\".";
                $msgType = 'success';
            }
        }
    } elseif ($action === 'bulk_remove_field') {
        $mid = (int)($_POST['master_field_id'] ?? 0);
        $rf = $_POST['remove_from'] ?? [];
        if (!$mid) {
            $msg = "Select field.";
            $msgType = 'error';
        } elseif (empty($rf)) {
            $msg = "Select records.";
            $msgType = 'error';
        } else {
            $rc = 0;
            foreach ($rf as $tid) {
                $tid = (int)$tid;
                $conn->query("DELETE FROM testing_record WHERE testing_id=$tid AND field_key=$mid");
                $conn->query("DELETE FROM field_definitions WHERE testing_id=$tid AND field_key=$mid");
                if ($conn->affected_rows > 0) $rc++;
            }
            $msg = $rc > 0 ? "Removed from $rc record(s)." : "Not found.";
            $msgType = $rc > 0 ? 'success' : 'warning';
        }
    } elseif ($action === 'delete_master_field') {
        $mid = (int)($_POST['master_field_id'] ?? 0);
        if (!$mid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            $n = $conn->prepare("SELECT field_name FROM master_fields WHERE master_field_id=?");
            $n->bind_param("i", $mid);
            $n->execute();
            $nr = $n->get_result()->fetch_assoc();
            if (!$nr) {
                $msg = "Not found.";
                $msgType = 'error';
            } else {
                $conn->query("DELETE FROM testing_record WHERE field_key=$mid");
                $conn->query("DELETE FROM field_definitions WHERE field_key=$mid");
                $conn->query("DELETE FROM master_fields WHERE master_field_id=$mid");
                $msg = "\"{$nr['field_name']}\" deleted.";
                $msgType = 'success';
            }
        }
    }
}

$mfRes = $conn->query("SELECT * FROM master_fields ORDER BY master_field_id");
$mfArr = [];
while ($m = $mfRes->fetch_assoc()) $mfArr[] = $m;
$atRes = $conn->query("SELECT testing_id,project_name,project_title,testing_name,testing_method FROM testing ORDER BY testing_id");
$atArr = [];
while ($t = $atRes->fetch_assoc()) $atArr[] = $t;
$fu = [];
foreach ($mfArr as $mf) {
    $id = (int)$mf['master_field_id'];
    $r = $conn->query("SELECT testing_id FROM field_definitions WHERE field_key=$id");
    $fu[$id] = [];
    while ($x = $r->fetch_assoc()) $fu[$id][] = (int)$x['testing_id'];
}
$totalF = count($mfArr);
$totalT = count($atArr);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'head.php'; ?>
    <title>Manage Fields — Subjective Portal</title>
</head>

<body>
    <?php include 'orbs.php'; ?>
    <div class="app-layout"><?php include 'navbar.php'; ?><?php include 'modal.php'; ?>
        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-table-columns"></i> Manage Fields</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / Fields</div>
            </div>
            <div class="main-body">

                <div class="stats-bar">
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-table-columns"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $totalF ?>">0</div>
                            <div class="stat-label">Master Fields</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-flask"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $totalT ?>">0</div>
                            <div class="stat-label">Testing Records</div>
                        </div>
                    </div>
                </div>

                <?php if ($msg): ?><div class="msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

                <!-- CURRENT FIELDS -->
                <div class="card">
                    <h2><i class="fas fa-list"></i> Current Master Fields</h2>
                    <?php if (empty($mfArr)): ?><div class="empty-state"><i class="fas fa-table-columns"></i>
                            <p>No master fields defined.</p>
                        </div>
                    <?php else: ?><div class="overflow-x">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Field Name</th>
                                        <th>Type</th>
                                        <th>Used In</th>
                                        <th>Rename</th>
                                        <th>Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mfArr as $i => $mf): ?>
                                        <tr>
                                            <td style="font-weight:700;color:#6B8DB5;"><?= (int)$mf['master_field_id'] ?></td>
                                            <td><strong><?= htmlspecialchars($mf['field_name']) ?></strong></td>
                                            <td><span class="badge <?= $mf['value_type'] === 'text' ? 'badge-text' : 'badge-decimal' ?>"><?= htmlspecialchars($mf['value_type']) ?></span></td>
                                            <td><span class="text-muted-sm"><?php $u = $fu[(int)$mf['master_field_id']] ?? [];
                                                                            echo $u ? implode(', ', array_map(function ($t) {
                                                                                return "#$t";
                                                                            }, $u)) : '<span style="color:#CBD5E0">None</span>'; ?></span></td>
                                            <td>
                                                <form method="POST" id="fR<?= $i ?>" class="inline-form"><input type="hidden" name="action" value="rename_field"><input type="hidden" name="master_field_id" value="<?= (int)$mf['master_field_id'] ?>">
                                                    <input type="text" name="new_field_name" placeholder="New name" style="width:130px;" required>
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="confirmSubmit('fR<?= $i ?>','confirm','Rename','Rename &quot;<?= htmlspecialchars($mf['field_name'], ENT_QUOTES) ?>&quot;?')"><i class="fas fa-pen"></i></button>
                                                </form>
                                            </td>
                                            <td>
                                                <form method="POST" id="fDelM<?= $i ?>"><input type="hidden" name="action" value="delete_master_field"><input type="hidden" name="master_field_id" value="<?= (int)$mf['master_field_id'] ?>">
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmSubmit('fDelM<?= $i ?>','danger','Delete','Permanently delete &quot;<?= htmlspecialchars($mf['field_name'], ENT_QUOTES) ?>&quot;?')"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div><?php endif; ?>
                </div>

                <!-- ADD FIELD -->
                <div class="card">
                    <h2><i class="fas fa-plus-circle"></i> Add New Master Field</h2>
                    <form method="POST" id="fAMF"><input type="hidden" name="action" value="add_master_field">
                        <label>Field Name:</label><br><input type="text" name="field_name" style="width:350px;" required placeholder="New field name"><br><br>
                        <label>Value Type:</label><br><select name="value_type">
                            <option value="text">text</option>
                            <option value="non_negative_decimal">non_negative_decimal</option>
                        </select><br><br>
                        <label>Apply to Testing Records:</label>
                        <p class="text-muted-sm" style="margin:4px 0 8px;"><i class="fas fa-info-circle"></i> Select records to apply this field to.</p>
                        <div class="scrollable-list">
                            <?php if (empty($atArr)): ?><p class="text-muted">No records.</p>
                                <?php else: foreach ($atArr as $t): ?>
                                    <label><input type="checkbox" name="apply_to[]" value="<?= (int)$t['testing_id'] ?>"> #<?= (int)$t['testing_id'] ?> — <?= htmlspecialchars($t['project_name']) ?> / <?= htmlspecialchars($t['project_title']) ?> / <?= htmlspecialchars($t['testing_name']) ?> / <?= htmlspecialchars($t['testing_method']) ?></label>
                            <?php endforeach;
                            endif; ?>
                        </div><br>
                        <button type="button" class="btn btn-success" onclick="confirmSubmit('fAMF','confirm','Add Field','Create this field and apply to selected records?')"><i class="fas fa-plus"></i> Add Master Field</button>
                    </form>
                </div>

                <!-- BULK REMOVE -->
                <div class="card">
                    <h2><i class="fas fa-minus-circle"></i> Remove Field from Records</h2>
                    <form method="POST" id="fBR"><input type="hidden" name="action" value="bulk_remove_field">
                        <label>Select Field:</label><br><select name="master_field_id" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($mfArr as $mf): ?><option value="<?= (int)$mf['master_field_id'] ?>"><?= htmlspecialchars($mf['field_name']) ?> (<?= htmlspecialchars($mf['value_type']) ?>)</option><?php endforeach; ?>
                        </select><br><br>
                        <label>Remove from:</label>
                        <p class="text-muted-sm" style="margin:4px 0 8px;"><i class="fas fa-info-circle"></i> Select records to remove this field from.</p>
                        <div class="scrollable-list">
                            <?php if (empty($atArr)): ?><p class="text-muted">No records.</p>
                                <?php else: foreach ($atArr as $t): ?>
                                    <label><input type="checkbox" name="remove_from[]" value="<?= (int)$t['testing_id'] ?>"> #<?= (int)$t['testing_id'] ?> — <?= htmlspecialchars($t['project_name']) ?> / <?= htmlspecialchars($t['project_title']) ?> / <?= htmlspecialchars($t['testing_name']) ?> / <?= htmlspecialchars($t['testing_method']) ?></label>
                            <?php endforeach;
                            endif; ?>
                        </div><br>
                        <button type="button" class="btn btn-danger" onclick="confirmSubmit('fBR','danger','Remove Field','Permanently remove this field and data from selected records?')"><i class="fas fa-trash"></i> Remove from Selected</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
    <script>
        VanillaTilt.init(document.querySelectorAll('.stat-card'), {
            max: 8,
            speed: 400,
            glare: true,
            'max-glare': 0.08,
            scale: 1.02
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>