<?php
require 'auth.php';
requireRole(['EDITOR', 'DEVELOPER']);
require 'db.php';

$msg     = '';
$msgType = '';
$errors  = [];
$userRole = $_SESSION['role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create Testing ────────────────────────────────────────
    if ($action === 'create_testing') {
        $pn = trim($_POST['new_project_name'] ?? '');
        if ($pn === '' && !empty($_POST['existing_project_name'])) $pn = $_POST['existing_project_name'];
        // Date-prefix: prepend YYYYMMDD_ to the user-entered project title
        $ptUser = trim($_POST['project_title_suffix'] ?? '');
        $ptDate = date('Ymd');
        $pt     = $ptDate . '_' . $ptUser;
        $tn     = trim($_POST['testing_name'] ?? '');
        $tm     = trim($_POST['testing_method'] ?? '');
        if (!$pn)     $errors[] = "Project Name is required.";
        if (!$ptUser) $errors[] = "Project Title is required.";
        if (!$tn)     $errors[] = "Testing Name is required.";
        if (!$tm)     $errors[] = "Testing Method is required.";
        if (empty($errors)) {
            $c = $conn->prepare("SELECT testing_id FROM testing WHERE project_name=? AND project_title=? AND testing_name=? AND testing_method=?");
            $c->bind_param("ssss", $pn, $pt, $tn, $tm);
            $c->execute();
            if ($c->get_result()->num_rows > 0) $errors[] = "Duplicate testing record exists.";
        }
        if (empty($errors)) {
            $createdBy = $_SESSION['username'] ?? null;
            $s = $conn->prepare("INSERT INTO testing (project_name,project_title,testing_name,testing_method,created_by) VALUES (?,?,?,?,?)");
            $s->bind_param("sssss", $pn, $pt, $tn, $tm, $createdBy);
            $s->execute();
            $nid = $conn->insert_id;
            $mf  = $conn->query("SELECT master_field_id,field_name,value_type FROM master_fields ORDER BY master_field_id");
            $o   = 1;
            while ($f = $mf->fetch_assoc()) {
                $sf = $conn->prepare("INSERT INTO field_definitions (testing_id,field_key,field_name,value_type,display_order) VALUES (?,?,?,?,?)");
                $fk = (int)$f['master_field_id'];
                $sf->bind_param("iissi", $nid, $fk, $f['field_name'], $f['value_type'], $o);
                $sf->execute();
                $o++;
            }
            $msg     = "Testing #$nid created with " . ($o - 1) . " fields.";
            $msgType = 'success';
        } else {
            $msg     = implode(" ", $errors);
            $msgType = 'error';
        }

        // ── Update Meta ───────────────────────────────────────────
    } elseif ($action === 'update_meta') {
        $tid    = (int)($_POST['testing_id'] ?? 0);
        $pn     = trim($_POST['project_name'] ?? '');
        // Rebuild project title from stored prefix + editable suffix
        $ptPfx  = trim($_POST['project_title_prefix'] ?? '');
        $ptSfx  = trim($_POST['project_title_suffix'] ?? '');
        $pt     = ($ptPfx !== '') ? $ptPfx . '_' . $ptSfx : $ptSfx;
        $tn     = trim($_POST['testing_name'] ?? '');
        $tm     = trim($_POST['testing_method'] ?? '');
        if (!$tid) $errors[] = "Invalid ID.";
        if (!$pn)  $errors[] = "Project Name required.";
        if (!$pt)  $errors[] = "Project Title required.";
        if (!$tn)  $errors[] = "Testing Name required.";
        if (!$tm)  $errors[] = "Testing Method required.";
        if (empty($errors)) {
            $s = $conn->prepare("UPDATE testing SET project_name=?,project_title=?,testing_name=?,testing_method=? WHERE testing_id=?");
            $s->bind_param("ssssi", $pn, $pt, $tn, $tm, $tid);
            $s->execute();
            $msg     = "Testing #$tid meta updated.";
            $msgType = 'success';
        } else {
            $msg     = implode(" ", $errors);
            $msgType = 'error';
        }

        // ── Save Records ──────────────────────────────────────────
    } elseif ($action === 'save_records') {
        $tid  = (int)($_POST['testing_id'] ?? 0);
        $recs = $_POST['records'] ?? [];
        $nr   = (int)($_POST['num_rows'] ?? 0);
        if (!$tid)    $errors[] = "Invalid ID.";
        if ($nr <= 0) $errors[] = "No rows.";
        if (empty($errors)) {
            $fd  = $conn->query("SELECT field_key,value_type,field_name FROM field_definitions WHERE testing_id=$tid");
            $fds = [];
            while ($f = $fd->fetch_assoc()) $fds[(int)$f['field_key']] = $f;
            for ($rn = 1; $rn <= $nr; $rn++) {
                foreach ($fds as $fk => $df) {
                    $v = trim($recs[$rn][$fk] ?? '');
                    if ($v !== '' && $df['value_type'] === 'non_negative_decimal') {
                        if (!is_numeric($v))     $errors[] = "Row $rn, \"{$df['field_name']}\": not a number.";
                        elseif ((float)$v < 0)   $errors[] = "Row $rn, \"{$df['field_name']}\": must be non-negative.";
                    }
                }
            }
            if (empty($errors)) {
                $cnt = 0;
                $ownerRowSR = $conn->query("SELECT created_by FROM testing WHERE testing_id=$tid")->fetch_assoc();
                $tableNum = ($ownerRowSR && $ownerRowSR['created_by'] === ($_SESSION['username'] ?? '')) ? 1 : 2;
                $editedBy = $_SESSION['username'] ?? null;
                for ($rn = 1; $rn <= $nr; $rn++) {
                    foreach ($fds as $fk => $df) {
                        $v  = trim($recs[$rn][$fk] ?? '');
                        $sv = ($v === '') ? null : $v;
                        $s  = $conn->prepare("INSERT INTO testing_record (testing_id,field_key,row_number,table_number,record_value,edited_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE record_value=VALUES(record_value),edited_by=VALUES(edited_by)");
                        $s->bind_param("iiiiss", $tid, $fk, $rn, $tableNum, $sv, $editedBy);
                        $s->execute();
                        $cnt++;
                    }
                }
                $msg     = "Saved $nr row(s), $cnt cell(s) for Testing #$tid.";
                $msgType = 'success';
            } else {
                $msg     = "Errors: " . implode(" | ", $errors);
                $msgType = 'error';
            }
        } elseif (!empty($errors)) {
            $msg     = implode(" ", $errors);
            $msgType = 'error';
        }

        // ── Delete Row ────────────────────────────────────────────
    } elseif ($action === 'delete_row') {
        $tid = (int)($_POST['testing_id'] ?? 0);
        $rn  = (int)($_POST['row_number'] ?? 0);
        if (!$tid || !$rn) {
            $msg = "Invalid parameters.";
            $msgType = 'error';
        } else {
            $ownerRow = $conn->query("SELECT created_by FROM testing WHERE testing_id=$tid")->fetch_assoc();
            $tableNum = ($ownerRow && $ownerRow['created_by'] === ($_SESSION['username'] ?? '')) ? 1 : 2;
            $conn->query("DELETE FROM testing_record WHERE testing_id=$tid AND row_number=$rn AND table_number=$tableNum");
            // Re-number subsequent rows down by 1
            $conn->query("UPDATE testing_record SET row_number = row_number - 1 WHERE testing_id=$tid AND row_number > $rn AND table_number=$tableNum");
            $msg     = "Row $rn deleted.";
            $msgType = 'success';
        }

        // ── Configure Fields ──────────────────────────────────────
    } elseif ($action === 'configure_fields') {
        $tid = (int)($_POST['testing_id'] ?? 0);
        $sel = $_POST['selected_fields'] ?? [];
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } elseif (empty($sel)) {
            $msg = "Select at least one field.";
            $msgType = 'error';
        } else {
            $cr = $conn->query("SELECT field_key FROM field_definitions WHERE testing_id=$tid");
            $ck = [];
            while ($c = $cr->fetch_assoc()) $ck[] = (int)$c['field_key'];
            $sk  = array_map('intval', $sel);
            $rem = array_diff($ck, $sk);
            $add = array_diff($sk, $ck);
            $rc  = 0;
            foreach ($rem as $fk) {
                $conn->query("DELETE FROM testing_record WHERE testing_id=$tid AND field_key=$fk");
                $conn->query("DELETE FROM field_definitions WHERE testing_id=$tid AND field_key=$fk");
                $rc++;
            }
            $ac = 0;
            foreach ($add as $fk) {
                $ms = $conn->prepare("SELECT field_name,value_type FROM master_fields WHERE master_field_id=?");
                $ms->bind_param("i", $fk);
                $ms->execute();
                $mr = $ms->get_result();
                if ($mRow = $mr->fetch_assoc()) {
                    $mo = $conn->query("SELECT IFNULL(MAX(display_order),0)+1 AS n FROM field_definitions WHERE testing_id=$tid")->fetch_assoc()['n'];
                    $si = $conn->prepare("INSERT INTO field_definitions (testing_id,field_key,field_name,value_type,display_order) VALUES (?,?,?,?,?)");
                    $si->bind_param("iissi", $tid, $fk, $mRow['field_name'], $mRow['value_type'], $mo);
                    $si->execute();
                    $ac++;
                }
            }
            $msg     = "Fields updated. Added: $ac, Removed: $rc.";
            $msgType = 'success';
        }

        // ── Upload Media (grouped batch) ──────────────────────────
    } elseif ($action === 'upload_media') {
        $tid = (int)($_POST['testing_id'] ?? 0);
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            // Grouped upload: files keyed as media_files[g][],  group names as media_group_names[g]
            $ue       = [UPLOAD_ERR_INI_SIZE => 'Server limit.', UPLOAD_ERR_FORM_SIZE => 'Form limit.', UPLOAD_ERR_PARTIAL => 'Partial.', UPLOAD_ERR_NO_FILE => 'No file.', UPLOAD_ERR_NO_TMP_DIR => 'No temp.', UPLOAD_ERR_CANT_WRITE => 'Write fail.', UPLOAD_ERR_EXTENSION => 'Extension.'];
            $ok       = 0;
            $errMsgs  = [];
            $groupNames = $_POST['media_group_names'] ?? [];
            $groupFiles = $_FILES['media_files'] ?? [];

            // Support both grouped (media_files[g][]) and legacy flat (media_files[]) submission
            if (!empty($groupFiles['name']) && !is_array(reset($groupFiles['name']))) {
                // Legacy flat — wrap into single group
                $groupFiles = ['name' => [0 => $groupFiles['name']], 'type' => [0 => $groupFiles['type']], 'tmp_name' => [0 => $groupFiles['tmp_name']], 'error' => [0 => $groupFiles['error']], 'size' => [0 => $groupFiles['size']]];
                $groupNames = [0 => ''];
            }

            foreach ($groupFiles['name'] as $gi => $names) {
                if (empty($names) || (count($names) === 1 && $names[0] === '')) continue;
                $gName = trim($groupNames[$gi] ?? '');
                $gName = ($gName === '') ? null : $gName;
                $count = count($names);
                for ($i = 0; $i < $count; $i++) {
                    $err = $groupFiles['error'][$gi][$i];
                    if ($err !== UPLOAD_ERR_OK) {
                        $errMsgs[] = htmlspecialchars($names[$i]) . ': ' . ($ue[$err] ?? 'Unknown.');
                        continue;
                    }
                    if ($groupFiles['size'][$gi][$i] > 40 * 1024 * 1024) {
                        $errMsgs[] = htmlspecialchars($names[$i]) . ': exceeds 40MB.';
                        continue;
                    }
                    $fn  = $names[$i];
                    $mt  = $groupFiles['type'][$gi][$i];
                    $fdt = file_get_contents($groupFiles['tmp_name'][$gi][$i]);
                    $s   = $conn->prepare("INSERT INTO media_files (testing_id,group_name,file_name,mime_type,file_data) VALUES (?,?,?,?,?)");
                    $n   = NULL;
                    $s->bind_param("isssb", $tid, $gName, $fn, $mt, $n);
                    $s->send_long_data(4, $fdt);
                    $s->execute();
                    $ok++;
                }
            }

            if ($ok > 0 && empty($errMsgs)) {
                $msg = "$ok file(s) uploaded.";
                $msgType = 'success';
            } elseif ($ok > 0) {
                $msg = "$ok file(s) uploaded. Errors: " . implode(' | ', $errMsgs);
                $msgType = 'warning';
            } elseif (!empty($errMsgs)) {
                $msg = "Upload failed: " . implode(' | ', $errMsgs);
                $msgType = 'error';
            } else {
                $msg = "No files selected.";
                $msgType = 'error';
            }
        }

        // ── Delete Media ──────────────────────────────────────────
    } elseif ($action === 'delete_media') {
        $mid = (int)($_POST['media_id'] ?? 0);
        if ($mid) {
            $conn->query("DELETE FROM media_files WHERE media_id=$mid");
            $msg = "Media deleted.";
            $msgType = 'success';
        } else {
            $msg = "Invalid ID.";
            $msgType = 'error';
        }

        // ── Delete Media Group ────────────────────────────────────
    } elseif ($action === 'delete_media_group') {
        $tid   = (int)($_POST['testing_id'] ?? 0);
        $gname = $_POST['group_name'] ?? null;
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            if ($gname === '__ungrouped__' || $gname === null || $gname === '') {
                // Delete files where group_name IS NULL or empty
                $conn->query("DELETE FROM media_files WHERE testing_id=$tid AND (group_name IS NULL OR group_name='')");
            } else {
                $sg = $conn->prepare("DELETE FROM media_files WHERE testing_id=? AND group_name=?");
                $sg->bind_param("is", $tid, $gname);
                $sg->execute();
            }
            $msg = "Media group deleted.";
            $msgType = 'success';
        }

        // ── Rename Media Group ────────────────────────────────────
    } elseif ($action === 'rename_media_group') {
        $tid      = (int)($_POST['testing_id'] ?? 0);
        $oldName  = $_POST['old_group_name'] ?? null;
        $newName  = trim($_POST['new_group_name'] ?? '');
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            $newVal = ($newName === '') ? null : $newName;
            if ($oldName === '__ungrouped__' || $oldName === null || $oldName === '') {
                $rg = $conn->prepare("UPDATE media_files SET group_name=? WHERE testing_id=? AND (group_name IS NULL OR group_name='')");
                $rg->bind_param("si", $newVal, $tid);
            } else {
                $rg = $conn->prepare("UPDATE media_files SET group_name=? WHERE testing_id=? AND group_name=?");
                $rg->bind_param("sis", $newVal, $tid, $oldName);
            }
            $rg->execute();
            $msg = "Group renamed.";
            $msgType = 'success';
        }

        // ── Rename Media File ─────────────────────────────────────
    } elseif ($action === 'rename_media_file') {
        $mid     = (int)($_POST['media_id'] ?? 0);
        $newName = trim($_POST['new_file_name'] ?? '');
        if (!$mid || $newName === '') {
            $msg = "Invalid parameters.";
            $msgType = 'error';
        } else {
            $s = $conn->prepare("UPDATE media_files SET file_name=? WHERE media_id=?");
            $s->bind_param("si", $newName, $mid);
            $s->execute();
            $msg = "File name updated.";
            $msgType = 'success';
        }

        // ── Add Description (batch) ───────────────────────────────
    } elseif ($action === 'add_descriptions') {
        $tid    = (int)($_POST['testing_id'] ?? 0);
        $items  = $_POST['description_items'] ?? [];
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } elseif (empty($items)) {
            $msg = "No descriptions to add.";
            $msgType = 'error';
        } else {
            $added = 0;
            foreach ($items as $ct) {
                $ct = trim($ct);
                if ($ct === '') continue;
                $s = $conn->prepare("INSERT INTO testing_description (testing_id,content) VALUES (?,?)");
                $s->bind_param("is", $tid, $ct);
                $s->execute();
                $added++;
            }
            $msg     = $added > 0 ? "$added description(s) added." : "Nothing to add (all empty).";
            $msgType = $added > 0 ? 'success' : 'warning';
        }

        // ── Save All ──────────────────────────────────────────────
    } elseif ($action === 'save_all') {
        $tid      = (int)($_POST['testing_id'] ?? 0);
        $msgs     = [];
        $hasError = false;

        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            // 1. Configure Fields
            $sel = $_POST['selected_fields'] ?? [];
            if (!empty($sel)) {
                $cr = $conn->query("SELECT field_key FROM field_definitions WHERE testing_id=$tid");
                $ck = [];
                while ($c2 = $cr->fetch_assoc()) $ck[] = (int)$c2['field_key'];
                $sk  = array_map('intval', $sel);
                $rem = array_diff($ck, $sk);
                $add = array_diff($sk, $ck);
                $rc  = 0;
                $ac = 0;
                foreach ($rem as $fk) {
                    $conn->query("DELETE FROM testing_record WHERE testing_id=$tid AND field_key=$fk");
                    $conn->query("DELETE FROM field_definitions WHERE testing_id=$tid AND field_key=$fk");
                    $rc++;
                }
                foreach ($add as $fk) {
                    $ms = $conn->prepare("SELECT field_name,value_type FROM master_fields WHERE master_field_id=?");
                    $ms->bind_param("i", $fk);
                    $ms->execute();
                    $mr = $ms->get_result();
                    if ($mRow = $mr->fetch_assoc()) {
                        $mo = $conn->query("SELECT IFNULL(MAX(display_order),0)+1 AS n FROM field_definitions WHERE testing_id=$tid")->fetch_assoc()['n'];
                        $si = $conn->prepare("INSERT INTO field_definitions (testing_id,field_key,field_name,value_type,display_order) VALUES (?,?,?,?,?)");
                        $si->bind_param("iissi", $tid, $fk, $mRow['field_name'], $mRow['value_type'], $mo);
                        $si->execute();
                        $ac++;
                    }
                }
                $msgs[] = "Fields: +$ac / -$rc.";
            }

            // 2. Save Records
            $recs = $_POST['records'] ?? [];
            $nr   = (int)($_POST['num_rows'] ?? 0);
            if ($nr > 0) {
                $fdq  = $conn->query("SELECT field_key,value_type,field_name FROM field_definitions WHERE testing_id=$tid");
                $fds2 = [];
                while ($f2 = $fdq->fetch_assoc()) $fds2[(int)$f2['field_key']] = $f2;
                $recErrors = [];
                for ($rn = 1; $rn <= $nr; $rn++) {
                    foreach ($fds2 as $fk => $df) {
                        $v = trim($recs[$rn][$fk] ?? '');
                        if ($v !== '' && $df['value_type'] === 'non_negative_decimal') {
                            if (!is_numeric($v))   $recErrors[] = "Row $rn \"{$df['field_name']}\": not a number.";
                            elseif ((float)$v < 0) $recErrors[] = "Row $rn \"{$df['field_name']}\": must be ≥ 0.";
                        }
                    }
                }
                if (empty($recErrors)) {
                    $cnt = 0;
                    $ownerRowSA = $conn->query("SELECT created_by FROM testing WHERE testing_id=$tid")->fetch_assoc();
                    $tableNumSA = ($ownerRowSA && $ownerRowSA['created_by'] === ($_SESSION['username'] ?? '')) ? 1 : 2;
                    $editedBySA = $_SESSION['username'] ?? null;
                    for ($rn = 1; $rn <= $nr; $rn++) {
                        foreach ($fds2 as $fk => $df) {
                            $v  = trim($recs[$rn][$fk] ?? '');
                            $sv = ($v === '') ? null : $v;
                            $s  = $conn->prepare("INSERT INTO testing_record (testing_id,field_key,row_number,table_number,record_value,edited_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE record_value=VALUES(record_value),edited_by=VALUES(edited_by)");
                            $s->bind_param("iiiiss", $tid, $fk, $rn, $tableNumSA, $sv, $editedBySA);
                            $s->execute();
                            $cnt++;
                        }
                    }
                    $msgs[] = "Records: saved $nr row(s).";
                } else {
                    $msgs[]   = "Record errors: " . implode(', ', $recErrors);
                    $hasError = true;
                }
            }

            // 3. Upload Media (grouped, if any files sent)
            $saGroupFiles = $_FILES['media_files'] ?? [];
            $saGroupNames = $_POST['media_group_names'] ?? [];
            $hasGroupedFiles = !empty($saGroupFiles['name']) && is_array(reset($saGroupFiles['name']));
            $hasFlatFiles    = !empty($saGroupFiles['name']) && !is_array(reset($saGroupFiles['name'])) && $saGroupFiles['name'][0] !== '';
            if ($hasGroupedFiles || $hasFlatFiles) {
                $ue = [UPLOAD_ERR_INI_SIZE => 'Server limit.', UPLOAD_ERR_FORM_SIZE => 'Form limit.', UPLOAD_ERR_PARTIAL => 'Partial.', UPLOAD_ERR_NO_FILE => 'No file.', UPLOAD_ERR_NO_TMP_DIR => 'No temp.', UPLOAD_ERR_CANT_WRITE => 'Write fail.', UPLOAD_ERR_EXTENSION => 'Extension.'];
                if ($hasFlatFiles) {
                    $saGroupFiles = ['name' => [0 => $saGroupFiles['name']], 'type' => [0 => $saGroupFiles['type']], 'tmp_name' => [0 => $saGroupFiles['tmp_name']], 'error' => [0 => $saGroupFiles['error']], 'size' => [0 => $saGroupFiles['size']]];
                    $saGroupNames = [0 => ''];
                }
                $ok = 0;
                $mErrMsgs = [];
                foreach ($saGroupFiles['name'] as $gi => $names) {
                    if (empty($names) || (count($names) === 1 && $names[0] === '')) continue;
                    $gName2 = trim($saGroupNames[$gi] ?? '');
                    $gName2 = ($gName2 === '') ? null : $gName2;
                    $count = count($names);
                    for ($i = 0; $i < $count; $i++) {
                        $err = $saGroupFiles['error'][$gi][$i];
                        if ($err !== UPLOAD_ERR_OK) {
                            $mErrMsgs[] = htmlspecialchars($names[$i]) . ': ' . ($ue[$err] ?? 'Unknown.');
                            continue;
                        }
                        if ($saGroupFiles['size'][$gi][$i] > 40 * 1024 * 1024) {
                            $mErrMsgs[] = htmlspecialchars($names[$i]) . ': exceeds 40MB.';
                            continue;
                        }
                        $fn2 = $names[$i];
                        $mt2 = $saGroupFiles['type'][$gi][$i];
                        $fd2 = file_get_contents($saGroupFiles['tmp_name'][$gi][$i]);
                        $s2  = $conn->prepare("INSERT INTO media_files (testing_id,group_name,file_name,mime_type,file_data) VALUES (?,?,?,?,?)");
                        $n2  = NULL;
                        $s2->bind_param("isssb", $tid, $gName2, $fn2, $mt2, $n2);
                        $s2->send_long_data(4, $fd2);
                        $s2->execute();
                        $ok++;
                    }
                }
                $msgs[] = "Media: $ok file(s) uploaded" . (empty($mErrMsgs) ? '.' : ', errors: ' . implode(', ', $mErrMsgs) . '.');
                if (!empty($mErrMsgs)) $hasError = true;
            }

            // 4. Add Descriptions (queued items)
            $items = $_POST['description_items'] ?? [];
            if (!empty($items)) {
                $added = 0;
                foreach ($items as $ct) {
                    $ct = trim($ct);
                    if ($ct === '') continue;
                    $s3 = $conn->prepare("INSERT INTO testing_description (testing_id,content) VALUES (?,?)");
                    $s3->bind_param("is", $tid, $ct);
                    $s3->execute();
                    $added++;
                }
                if ($added > 0) $msgs[] = "Descriptions: $added added.";
            }

            if (empty($msgs)) $msgs[] = "Nothing to save.";
            $msg     = implode(' | ', $msgs);
            $msgType = $hasError ? 'warning' : 'success';
        }

        // ── Delete Description ────────────────────────────────────
    } elseif ($action === 'delete_description') {
        $did = (int)($_POST['description_id'] ?? 0);
        if ($did) {
            $conn->query("DELETE FROM testing_description WHERE description_id=$did");
            $msg = "Description deleted.";
            $msgType = 'success';
        } else {
            $msg = "Invalid ID.";
            $msgType = 'error';
        }

        // ── Delete Testing ────────────────────────────────────────
    } elseif ($action === 'delete_testing') {
        $tid = (int)($_POST['testing_id'] ?? 0);
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            $s = $conn->prepare("SELECT testing_id FROM testing WHERE testing_id=?");
            $s->bind_param("i", $tid);
            $s->execute();
            if ($s->get_result()->num_rows === 0) {
                $msg = "Testing #$tid not found.";
                $msgType = 'error';
            } else {
                $conn->query("DELETE FROM testing_record WHERE testing_id=$tid");
                $conn->query("DELETE FROM field_definitions WHERE testing_id=$tid");
                $conn->query("DELETE FROM media_files WHERE testing_id=$tid");
                $conn->query("DELETE FROM testing_description WHERE testing_id=$tid");
                $conn->query("DELETE FROM testing WHERE testing_id=$tid");
                $conn->close();
                header("Location: editor.php?deleted=$tid");
                exit;
            }
        }
    }
}

// ── Page data ─────────────────────────────────────────────────
$editTid = (int)($_GET['edit'] ?? 0);
if (isset($_GET['deleted'])) {
    $msg     = "Testing #" . (int)$_GET['deleted'] . " and all its data have been permanently deleted.";
    $msgType = 'success';
}

// All testing rows for cascading load selector
$allTestingRows = [];
$atQ = $conn->query("SELECT testing_id,project_name,project_title,testing_name,testing_method FROM testing ORDER BY project_name,project_title");
while ($r = $atQ->fetch_assoc()) $allTestingRows[] = $r;
$allTestingJson = json_encode($allTestingRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// NEW: Build list of unique suffixes from project titles (for combobox)
$suffixes = [];
foreach ($allTestingRows as $tr) {
    $title = $tr['project_title'];
    // Extract suffix after first underscore if pattern matches, else whole title
    if (preg_match('/^(\d{8})_(.*)$/s', $title, $m)) {
        $suffix = $m[2];
    } else {
        $suffix = $title;
    }
    if (!in_array($suffix, $suffixes)) {
        $suffixes[] = $suffix;
    }
}
sort($suffixes);
$suffixesJson = json_encode($suffixes);

$existingPN = $conn->query("SELECT DISTINCT project_name FROM testing ORDER BY project_name");
$mfQ        = $conn->query("SELECT master_field_id,field_name,value_type FROM master_fields ORDER BY master_field_id");
$mfArr      = [];
while ($m = $mfQ->fetch_assoc()) $mfArr[] = $m;

$editData       = null;
$editFields     = [];
$editRows       = [];
$editRowsOther  = [];
$editedByT1     = null;
$editedByT2     = null;
$myTableNum     = 1;
$otherTableNum  = 2;
$isOwner        = true;
$editMedia      = [];
$editDesc       = [];
$editCFK        = [];
if ($editTid) {
    $s = $conn->prepare("SELECT * FROM testing WHERE testing_id=?");
    $s->bind_param("i", $editTid);
    $s->execute();
    $editData = $s->get_result()->fetch_assoc();
    if ($editData) {
        $fr = $conn->query("SELECT field_key,field_name,value_type,display_order FROM field_definitions WHERE testing_id=$editTid ORDER BY display_order");
        while ($f = $fr->fetch_assoc()) {
            $editFields[] = $f;
            $editCFK[] = (int)$f['field_key'];
        }
        $currentUsername = $_SESSION['username'] ?? '';
        $isOwner = ($editData['created_by'] === $currentUsername);
        $myTableNum = $isOwner ? 1 : 2;
        // For editor's table
        $rr = $conn->query("SELECT field_key,row_number,record_value FROM testing_record WHERE testing_id=$editTid AND table_number=$myTableNum ORDER BY row_number,field_key");
        while ($r = $rr->fetch_assoc()) {
            $rn = (int)$r['row_number'];
            if (!isset($editRows[$rn])) $editRows[$rn] = [];
            $editRows[$rn][(int)$r['field_key']] = $r['record_value'];
        }
        ksort($editRows);
        // Also fetch the other table's rows (read-only view)
        $otherTableNum = $isOwner ? 2 : 1;
        $editRowsOther = [];
        $rrOther = $conn->query("SELECT field_key,row_number,record_value FROM testing_record WHERE testing_id=$editTid AND table_number=$otherTableNum ORDER BY row_number,field_key");
        while ($r = $rrOther->fetch_assoc()) {
            $rn = (int)$r['row_number'];
            if (!isset($editRowsOther[$rn])) $editRowsOther[$rn] = [];
            $editRowsOther[$rn][(int)$r['field_key']] = $r['record_value'];
        }
        ksort($editRowsOther);
        // Get edited_by for both tables
        $ebRow1 = $conn->query("SELECT edited_by FROM testing_record WHERE testing_id=$editTid AND table_number=1 LIMIT 1")->fetch_assoc();
        $ebRow2 = $conn->query("SELECT edited_by FROM testing_record WHERE testing_id=$editTid AND table_number=2 LIMIT 1")->fetch_assoc();
        $editedByT1 = $ebRow1['edited_by'] ?? null;
        $editedByT2 = $ebRow2['edited_by'] ?? null;
        $mr = $conn->query("SELECT media_id,group_name,file_name,mime_type FROM media_files WHERE testing_id=$editTid ORDER BY ISNULL(group_name), group_name, created_at");
        while ($m = $mr->fetch_assoc()) $editMedia[] = $m;
        $dr = $conn->query("SELECT description_id,content FROM testing_description WHERE testing_id=$editTid ORDER BY created_at");
        while ($d = $dr->fetch_assoc()) $editDesc[] = $d;

        // Collect distinct group names for combobox suggestions (across all testings)
        $gnQ = $conn->query("SELECT DISTINCT group_name FROM media_files WHERE group_name IS NOT NULL AND group_name <> '' ORDER BY group_name");
        $existingGroupNames = [];
        while ($gn = $gnQ->fetch_assoc()) $existingGroupNames[] = $gn['group_name'];
    }
}
$tTests  = $conn->query("SELECT COUNT(*) AS c FROM testing")->fetch_assoc()['c'];
$tFields = $conn->query("SELECT COUNT(*) AS c FROM master_fields")->fetch_assoc()['c'];
if (!isset($existingGroupNames)) $existingGroupNames = [];

function getAvgClass($a)
{
    if ($a === null) return 'avg-na';
    if ($a >= 4)     return 'avg-high';
    if ($a >= 2.5)   return 'avg-mid';
    return 'avg-low';
}

// Helper: split project title into prefix (YYYYMMDD) and suffix
function splitProjectTitle($pt)
{
    if (preg_match('/^(\d{8})_(.*)$/s', $pt, $m)) return ['prefix' => $m[1], 'suffix' => $m[2]];
    return ['prefix' => '', 'suffix' => $pt];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & Base ─────────────────────────────── */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: linear-gradient(-45deg, #FFF8F0, #EEF2FF, #F0FFF4, #FFF0F6);
            background-size: 400% 400%;
            animation: bgShift 20s ease infinite;
            overflow-x: hidden;
        }

        @keyframes bgShift {
            0% {
                background-position: 0% 50%
            }

            50% {
                background-position: 100% 50%
            }

            100% {
                background-position: 0% 50%
            }
        }

        /* ── Orbs ─────────────────────────────────────── */
        .page-orbs {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.18;
        }

        .orb-1 {
            width: 520px;
            height: 520px;
            background: #A7C7E7;
            top: -12%;
            left: -6%;
            animation: orbM1 16s ease-in-out infinite;
        }

        .orb-2 {
            width: 440px;
            height: 440px;
            background: #FADADD;
            bottom: -12%;
            right: -6%;
            animation: orbM2 18s ease-in-out infinite;
        }

        .orb-3 {
            width: 360px;
            height: 360px;
            background: #C1E1C1;
            top: 35%;
            left: 45%;
            animation: orbM3 13s ease-in-out infinite;
        }

        .orb-4 {
            width: 280px;
            height: 280px;
            background: #C1A0D8;
            top: 10%;
            right: 15%;
            animation: orbM4 11s ease-in-out infinite;
        }

        .orb-5 {
            width: 240px;
            height: 240px;
            background: #FFD9A0;
            bottom: 20%;
            left: 20%;
            animation: orbM5 14s ease-in-out infinite;
        }

        @keyframes orbM1 {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(80px, 60px)
            }
        }

        @keyframes orbM2 {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(-70px, -80px)
            }
        }

        @keyframes orbM3 {

            0%,
            100% {
                transform: translate(-50%, -50%) scale(1)
            }

            50% {
                transform: translate(-35%, -35%) scale(1.15)
            }
        }

        @keyframes orbM4 {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(-50px, 60px)
            }
        }

        @keyframes orbM5 {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(60px, -50px)
            }
        }

        /* ── Cursor Glow ──────────────────────────────── */
        .cursor-glow {
            position: fixed;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(107, 141, 181, 0.08) 0%, transparent 70%);
            pointer-events: none;
            transform: translate(-50%, -50%);
            z-index: 1;
            transition: opacity 0.3s;
        }

        /* ── Layout ───────────────────────────────────── */
        .app-layout {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 10;
        }

        /* ── Sidebar ──────────────────────────────────── */
        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 200;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 11px;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            color: #6B8DB5;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }

        .sidebar {
            width: 240px;
            flex-shrink: 0;
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            border-right: 1px solid rgba(255, 255, 255, 0.65);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .sidebar-header {
            padding: 22px 18px 18px;
            border-bottom: 1px solid rgba(107, 141, 181, 0.1);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #6B8DB5, #8BB3D9);
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            box-shadow: 0 4px 14px rgba(107, 141, 181, 0.28);
            flex-shrink: 0;
        }

        .brand-text {
            font-size: 15px;
            font-weight: 800;
            color: #2D3748;
            letter-spacing: -0.3px;
            line-height: 1.2;
        }

        .brand-sub {
            font-size: 11px;
            color: #A0AEC0;
            font-weight: 500;
        }

        .sidebar-nav {
            flex: 1;
            padding: 14px 12px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .nav-label {
            font-size: 10px;
            font-weight: 700;
            color: #CBD5E0;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            padding: 12px 10px 4px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 13px;
            color: #718096;
            font-size: 13.5px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.22s;
        }

        .sidebar-nav a i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(107, 141, 181, 0.1);
            color: #4A7BA8;
        }

        .sidebar-nav a.active {
            font-weight: 700;
        }

        .sidebar-footer {
            padding: 14px 12px 18px;
            border-top: 1px solid rgba(107, 141, 181, 0.08);
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: rgba(107, 141, 181, 0.07);
            border-radius: 14px;
            margin-bottom: 10px;
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #C1A0D8, #8BB3D9);
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .user-details {
            flex: 1;
            overflow: hidden;
        }

        .user-name {
            font-size: 13px;
            font-weight: 700;
            color: #2D3748;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 11px;
            color: #A0AEC0;
            font-weight: 500;
        }

        .sidebar-logout {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 12px;
            color: #D07070;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.22s;
            border: 1px solid rgba(208, 112, 112, 0.15);
        }

        .sidebar-logout:hover {
            background: rgba(208, 112, 112, 0.08);
        }

        /* ── Main Content ─────────────────────────────── */
        .main-content {
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .main-header {
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.6);
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .main-header h1 {
            font-size: 21px;
            font-weight: 800;
            color: #2D3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .main-header h1 i {
            color: #6B8DB5;
            font-size: 19px;
        }

        .header-breadcrumb {
            font-size: 12px;
            color: #A0AEC0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .header-breadcrumb a {
            color: #6B8DB5;
            text-decoration: none;
        }

        .header-breadcrumb a:hover {
            text-decoration: underline;
        }

        .main-body {
            padding: 26px 30px;
            overflow-y: auto;
            flex: 1;
        }

        /* ── Cards ────────────────────────────────────── */
        .card {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 22px;
            padding: 26px;
            margin-bottom: 22px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.04), inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }

        .card h2 {
            font-size: 16px;
            font-weight: 800;
            color: #2D3748;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .card h2 i {
            color: #6B8DB5;
        }

        .reveal-on-scroll {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity 0.45s ease, transform 0.45s ease;
        }

        .reveal-on-scroll.revealed {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Stats Bar ────────────────────────────────── */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.04);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            color: #fff;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #6B8DB5, #8BB3D9);
            box-shadow: 0 4px 14px rgba(107, 141, 181, 0.3);
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #B39DDB, #C1A0D8);
            box-shadow: 0 4px 14px rgba(180, 156, 215, 0.3);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #68A87A, #8DC99F);
            box-shadow: 0 4px 14px rgba(104, 168, 122, 0.3);
        }

        .stat-icon.amber {
            background: linear-gradient(135deg, #D4A85A, #E8C07A);
            box-shadow: 0 4px 14px rgba(212, 168, 90, 0.3);
        }

        .stat-value {
            font-size: 26px;
            font-weight: 900;
            color: #2D3748;
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: #A0AEC0;
            margin-top: 3px;
            font-weight: 500;
        }

        /* ── Buttons ──────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 18px;
            border: none;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.25s;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: linear-gradient(135deg, #6B8DB5, #8BB3D9);
            color: #fff;
            box-shadow: 0 4px 14px rgba(107, 141, 181, 0.25);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(107, 141, 181, 0.38);
        }

        .btn-secondary {
            background: rgba(107, 141, 181, 0.1);
            color: #6B8DB5;
            border: 1px solid rgba(107, 141, 181, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(107, 141, 181, 0.18);
        }

        .btn-success {
            background: linear-gradient(135deg, #68A87A, #8DC99F);
            color: #fff;
            box-shadow: 0 4px 14px rgba(104, 168, 122, 0.25);
        }

        .btn-success:hover {
            box-shadow: 0 6px 20px rgba(104, 168, 122, 0.38);
        }

        .btn-danger {
            background: linear-gradient(135deg, #E07070, #D07070);
            color: #fff;
            box-shadow: 0 4px 14px rgba(224, 112, 112, 0.2);
        }

        .btn-danger:hover {
            box-shadow: 0 6px 20px rgba(224, 112, 112, 0.35);
        }

        .btn-warning {
            background: linear-gradient(135deg, #D4A85A, #E8C07A);
            color: #fff;
            box-shadow: 0 4px 14px rgba(212, 168, 90, 0.2);
        }

        .btn-warning:hover {
            box-shadow: 0 6px 20px rgba(212, 168, 90, 0.35);
        }

        .btn-sm {
            padding: 7px 13px;
            font-size: 12px;
            border-radius: 10px;
        }

        .btn:disabled {
            opacity: 0.45;
            pointer-events: none;
            transform: none;
        }

        /* ── Forms ────────────────────────────────────── */
        select,
        input[type=text],
        input[type=password],
        textarea {
            padding: 9px 13px;
            border: 1.5px solid rgba(107, 141, 181, 0.15);
            border-radius: 11px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            background: rgba(255, 255, 255, 0.6);
            color: #2D3748;
            outline: none;
            transition: all 0.25s;
        }

        select:focus,
        input[type=text]:focus,
        textarea:focus {
            border-color: rgba(107, 141, 181, 0.4);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.09);
            background: rgba(255, 255, 255, 0.95);
        }

        label {
            font-size: 13px;
            font-weight: 600;
            color: #4A5568;
        }

        textarea {
            width: 100%;
            resize: vertical;
        }

        .inline-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        /* ── Project Title split input ────────────────── */
        .pt-split {
            display: flex;
            align-items: center;
            gap: 0;
            position: relative;
            /* for combobox dropdown */
        }

        .pt-prefix {
            padding: 9px 12px;
            background: rgba(107, 141, 181, 0.1);
            border: 1.5px solid rgba(107, 141, 181, 0.2);
            border-right: none;
            border-radius: 11px 0 0 11px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            color: #6B8DB5;
            font-weight: 700;
            white-space: nowrap;
            user-select: none;
            z-index: 2;
        }

        .pt-suffix {
            border-radius: 0 11px 11px 0 !important;
            flex: 1;
            min-width: 200px;
        }

        /* ── Table ────────────────────────────────────── */
        .overflow-x {
            overflow-x: auto;
            border-radius: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead th {
            background: rgba(107, 141, 181, 0.07);
            padding: 11px 14px;
            text-align: left;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #718096;
            border-bottom: 1px solid rgba(107, 141, 181, 0.1);
        }

        thead th:first-child {
            border-radius: 14px 0 0 0;
        }

        thead th:last-child {
            border-radius: 0 14px 0 0;
        }

        tbody tr {
            transition: background 0.18s;
        }

        tbody tr:hover {
            background: rgba(107, 141, 181, 0.04);
        }

        tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid rgba(107, 141, 181, 0.06);
            color: #4A5568;
            font-size: 13px;
            vertical-align: middle;
        }

        /* Auto-expanding textarea cells */
        tbody td textarea.cell-input {
            width: 100%;
            min-width: 80px;
            min-height: 34px;
            resize: none;
            overflow: hidden;
            padding: 6px 9px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            border: 1.5px solid rgba(107, 141, 181, 0.15);
            background: rgba(255, 255, 255, 0.6);
            font-family: 'Inter', sans-serif;
            color: #2D3748;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
            display: block;
        }

        tbody td textarea.cell-input:focus {
            border-color: rgba(107, 141, 181, 0.4);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.09);
            background: rgba(255, 255, 255, 0.95);
            outline: none;
        }

        /* ── Avg cells ────────────────────────────────── */
        .avg-cell {
            font-weight: 700;
            text-align: center;
            border-radius: 8px;
            padding: 6px 10px;
        }

        .avg-high {
            background: rgba(104, 168, 122, 0.12);
            color: #276749;
        }

        .avg-mid {
            background: rgba(212, 168, 90, 0.12);
            color: #92600A;
        }

        .avg-low {
            background: rgba(224, 112, 112, 0.12);
            color: #9B2C2C;
        }

        .avg-na {
            background: rgba(160, 174, 192, 0.1);
            color: #A0AEC0;
        }

        /* ── Badges ───────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.4px;
        }

        .badge-text {
            background: rgba(107, 141, 181, 0.12);
            color: #4A7BA8;
        }

        .badge-decimal {
            background: rgba(104, 168, 122, 0.12);
            color: #276749;
        }

        .badge-viewer {
            background: rgba(107, 141, 181, 0.12);
            color: #4A7BA8;
        }

        .badge-editor {
            background: rgba(212, 168, 90, 0.14);
            color: #92600A;
        }

        .badge-developer {
            background: rgba(193, 160, 216, 0.15);
            color: #7B5CA8;
        }

        /* ── Engineer check ───────────────────────────── */
        .engineer-check-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: rgba(104, 168, 122, 0.07);
            border: 1px solid rgba(104, 168, 122, 0.18);
            border-radius: 14px;
            margin-bottom: 16px;
        }

        .engineer-check-row label {
            font-size: 13px;
            font-weight: 600;
            color: #276749;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .engineer-check-row.unchecked {
            background: rgba(160, 174, 192, 0.07);
            border-color: rgba(160, 174, 192, 0.2);
        }

        .engineer-check-row.unchecked label {
            color: #718096;
        }

        /* ── Media ────────────────────────────────────── */
        .media-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
        }

        .media-grid img {
            border-radius: 14px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            transition: transform 0.25s;
        }

        .media-grid img:hover {
            transform: scale(1.04);
        }

        /* ── Media Groups ─────────────────────────────── */
        .media-group-block {
            background: rgba(107, 141, 181, 0.04);
            border: 1.5px solid rgba(107, 141, 181, 0.16);
            border-radius: 16px;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .media-group-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: rgba(107, 141, 181, 0.08);
            border-bottom: 1px solid rgba(107, 141, 181, 0.12);
            font-weight: 600;
            font-size: 13.5px;
            color: #3D5A80;
        }

        .media-group-header i {
            color: #8BB3D9;
            font-size: 14px;
        }

        .media-group-label {
            flex: 1;
        }

        .media-group-count {
            font-size: 11px;
            font-weight: 500;
            color: #7A92A8;
            background: rgba(107, 141, 181, 0.13);
            padding: 2px 8px;
            border-radius: 20px;
        }

        .media-group-body {
            padding: 14px 16px;
        }

        /* ── Group rename input (saved groups) ───────── */
        .group-rename-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 0;
        }

        .group-rename-input {
            flex: 1;
            min-width: 0;
            padding: 4px 10px;
            border: 1.5px solid transparent;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            color: #3D5A80;
            background: transparent;
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            cursor: text;
        }

        .group-rename-input:hover {
            border-color: rgba(107, 141, 181, 0.25);
            background: rgba(255, 255, 255, 0.7);
        }

        .group-rename-input:focus {
            border-color: rgba(107, 141, 181, 0.5);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.1);
            cursor: text;
        }

        .btn-group-rename-save {
            display: none;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: rgba(91, 168, 122, 0.12);
            border: 1.5px solid rgba(91, 168, 122, 0.35);
            border-radius: 8px;
            color: #4A9E6A;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            font-family: 'Inter', sans-serif;
        }

        .btn-group-rename-save.visible {
            display: inline-flex;
        }

        .btn-group-rename-save:hover {
            background: rgba(91, 168, 122, 0.22);
            border-color: rgba(91, 168, 122, 0.6);
        }

        .btn-group-delete {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: rgba(208, 112, 112, 0.08);
            border: 1.5px solid rgba(208, 112, 112, 0.25);
            border-radius: 8px;
            color: #C05252;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            font-family: 'Inter', sans-serif;
            flex-shrink: 0;
        }

        .btn-group-delete:hover {
            background: rgba(208, 112, 112, 0.16);
            border-color: rgba(208, 112, 112, 0.5);
        }

        /* ── Pending Groups (upload UI) ──────────────── */
        .pending-groups-container {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 14px;
        }

        .pending-group-card {
            background: rgba(107, 141, 181, 0.05);
            border: 1.5px solid rgba(107, 141, 181, 0.18);
            border-radius: 16px;
            overflow: visible;
        }

        .pending-group-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: rgba(107, 141, 181, 0.09);
            border-bottom: 1px solid rgba(107, 141, 181, 0.13);
            border-radius: 16px 16px 0 0;
            flex-wrap: wrap;
            gap: 8px;
        }

        .group-name-combobox-wrap {
            position: relative;
            flex: 1;
            min-width: 160px;
        }

        .group-name-input {
            width: 100%;
            padding: 7px 32px 7px 10px;
            border: 1.5px solid rgba(107, 141, 181, 0.3);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: #3D5A80;
            background: #fff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .group-name-input:focus {
            border-color: rgba(107, 141, 181, 0.6);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.12);
        }

        .group-name-dropdown-arrow {
            position: absolute;
            right: 9px;
            top: 50%;
            transform: translateY(-50%);
            color: #8BB3D9;
            font-size: 11px;
            pointer-events: none;
        }

        .group-name-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1.5px solid rgba(107, 141, 181, 0.3);
            border-radius: 12px;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.12);
            z-index: 999;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .group-name-dropdown.open {
            display: block;
        }

        .group-name-option {
            padding: 9px 13px;
            font-size: 13px;
            color: #3D5A80;
            cursor: pointer;
            transition: background 0.15s;
        }

        .group-name-option:hover,
        .group-name-option.highlighted {
            background: rgba(107, 141, 181, 0.1);
        }

        .group-name-option-new {
            padding: 9px 13px;
            font-size: 13px;
            color: #6B8DB5;
            font-style: italic;
            cursor: pointer;
            border-top: 1px solid rgba(107, 141, 181, 0.1);
            transition: background 0.15s;
        }

        .group-name-option-new:hover {
            background: rgba(107, 141, 181, 0.07);
        }

        .group-name-option-empty {
            padding: 9px 13px;
            font-size: 12px;
            color: #9AADBD;
            font-style: italic;
        }

        .pending-group-body {
            padding: 12px 14px;
        }

        .btn-remove-group {
            background: none;
            border: none;
            cursor: pointer;
            color: #D07070;
            font-size: 15px;
            padding: 4px 7px;
            border-radius: 8px;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .btn-remove-group:hover {
            background: rgba(208, 112, 112, 0.12);
        }

        .btn-add-files-to-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: rgba(107, 141, 181, 0.1);
            border: 1.5px dashed rgba(107, 141, 181, 0.3);
            border-radius: 10px;
            color: #6B8DB5;
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 8px;
        }

        .btn-add-files-to-group:hover {
            background: rgba(107, 141, 181, 0.17);
            border-color: rgba(107, 141, 181, 0.5);
            color: #3D5A80;
        }

        .btn-add-group {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 16px;
            background: rgba(107, 141, 181, 0.08);
            border: 1.5px dashed rgba(107, 141, 181, 0.35);
            border-radius: 12px;
            color: #5A7FA8;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.22s;
            margin-top: 4px;
            font-family: 'Inter', sans-serif;
        }

        .btn-add-group:hover {
            background: rgba(107, 141, 181, 0.15);
            border-color: rgba(107, 141, 181, 0.55);
            color: #3D5A80;
        }

        /* ── Clipboard paste hint ─────────────────────── */
        .paste-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            background: rgba(107, 141, 181, 0.06);
            border: 1.5px dashed rgba(107, 141, 181, 0.25);
            border-radius: 12px;
            font-size: 12.5px;
            color: #7A96B0;
            margin-top: 10px;
            transition: background 0.25s, border-color 0.25s, color 0.25s;
            user-select: none;
        }

        .paste-hint i {
            font-size: 13px;
            color: #96B4CC;
            flex-shrink: 0;
        }

        .paste-hint kbd {
            display: inline-block;
            padding: 1px 6px;
            background: rgba(107, 141, 181, 0.12);
            border: 1px solid rgba(107, 141, 181, 0.25);
            border-radius: 5px;
            font-family: 'Inter', sans-serif;
            font-size: 11.5px;
            font-weight: 600;
            color: #5A7FA8;
            line-height: 1.6;
        }

        .paste-hint.paste-active {
            background: rgba(91, 168, 122, 0.1);
            border-color: rgba(91, 168, 122, 0.4);
            color: #4A9E6A;
        }

        .paste-hint.paste-active i {
            color: #5BA87A;
        }

        .paste-no-group-toast {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: rgba(224, 168, 80, 0.12);
            border: 1.5px solid rgba(224, 168, 80, 0.35);
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
            color: #9A6F20;
            margin-top: 10px;
            animation: toastIn 0.25s ease;
        }

        .paste-no-group-toast i {
            color: #C8932A;
            flex-shrink: 0;
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Pending files list ───────────────────────── */
        .pending-files {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }

        .pending-file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(107, 141, 181, 0.07);
            border: 1px solid rgba(107, 141, 181, 0.15);
            border-radius: 11px;
            font-size: 13px;
            color: #4A5568;
        }

        .pending-file-item span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        .pending-file-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: #D07070;
            font-size: 14px;
            padding: 2px 6px;
            border-radius: 6px;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .pending-file-remove:hover {
            background: rgba(208, 112, 112, 0.12);
        }

        /* ── Pending descriptions ─────────────────────── */
        .pending-descs {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }

        .pending-desc-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 14px;
            background: rgba(107, 141, 181, 0.05);
            border: 1px solid rgba(107, 141, 181, 0.12);
            border-radius: 11px;
        }

        .pending-desc-item pre {
            flex: 1;
            margin: 0;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-word;
            color: #4A5568;
        }

        .pending-desc-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: #D07070;
            font-size: 14px;
            padding: 2px 6px;
            border-radius: 6px;
            transition: background 0.2s;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .pending-desc-remove:hover {
            background: rgba(208, 112, 112, 0.12);
        }

        /* ── Description Cards ────────────────────────── */
        .desc-card {
            background: rgba(107, 141, 181, 0.05);
            border: 1px solid rgba(107, 141, 181, 0.1);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 12px;
            font-size: 13px;
            color: #4A5568;
            line-height: 1.75;
        }

        /* ── Upload Zone ──────────────────────────────── */
        .upload-zone {
            border: 2px dashed rgba(107, 141, 181, 0.22);
            border-radius: 16px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s;
            background: rgba(107, 141, 181, 0.03);
        }

        .upload-zone:hover {
            border-color: rgba(107, 141, 181, 0.45);
            background: rgba(107, 141, 181, 0.07);
        }

        .upload-zone i {
            font-size: 30px;
            color: #8BB3D9;
            margin-bottom: 10px;
            display: block;
        }

        .upload-zone p {
            font-size: 13px;
            color: #A0AEC0;
        }

        /* ── Messages ─────────────────────────────────── */
        .msg-success {
            background: rgba(104, 168, 122, 0.09);
            color: #276749;
            border: 1px solid rgba(104, 168, 122, 0.18);
            padding: 13px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .msg-error {
            background: rgba(224, 112, 112, 0.08);
            color: #9B2C2C;
            border: 1px solid rgba(224, 112, 112, 0.14);
            padding: 13px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
            animation: shakeErr 0.45s ease;
        }

        .msg-warning {
            background: rgba(212, 168, 90, 0.09);
            color: #92600A;
            border: 1px solid rgba(212, 168, 90, 0.18);
            padding: 13px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        @keyframes shakeErr {

            0%,
            100% {
                transform: translateX(0)
            }

            20% {
                transform: translateX(-5px)
            }

            40% {
                transform: translateX(5px)
            }

            60% {
                transform: translateX(-3px)
            }

            80% {
                transform: translateX(3px)
            }
        }

        /* ── Empty State ──────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #CBD5E0;
        }

        .empty-state i {
            font-size: 44px;
            margin-bottom: 14px;
            display: block;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* ── Modal ────────────────────────────────────── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 30, 50, 0.3);
            backdrop-filter: blur(7px);
            -webkit-backdrop-filter: blur(7px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(30px);
            border: 1px solid rgba(255, 255, 255, 0.75);
            border-radius: 24px;
            padding: 36px 38px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.1);
            animation: modalPop 0.32s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .modal-box.closing {
            animation: modalOut 0.22s ease forwards;
        }

        @keyframes modalPop {
            from {
                opacity: 0;
                transform: scale(0.88) translateY(22px)
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0)
            }
        }

        @keyframes modalOut {
            to {
                opacity: 0;
                transform: scale(0.92) translateY(14px)
            }
        }

        .modal-icon {
            width: 54px;
            height: 54px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 16px;
        }

        .modal-icon.confirm {
            background: rgba(107, 141, 181, 0.12);
            color: #6B8DB5;
        }

        .modal-icon.danger {
            background: rgba(224, 112, 112, 0.12);
            color: #D07070;
        }

        .modal-icon.warning {
            background: rgba(212, 168, 90, 0.12);
            color: #D4A85A;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 800;
            color: #2D3748;
            margin-bottom: 8px;
        }

        .modal-message {
            font-size: 14px;
            color: #718096;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* ── Save All card ────────────────────────────── */
        .save-all-card {
            background: linear-gradient(135deg, rgba(107, 141, 181, 0.12), rgba(139, 179, 217, 0.08));
            border: 1.5px solid rgba(107, 141, 181, 0.25);
            border-radius: 22px;
            padding: 22px 26px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            box-shadow: 0 4px 20px rgba(107, 141, 181, 0.1);
        }

        .save-all-card .save-all-info {
            flex: 1;
            min-width: 200px;
        }

        .save-all-card .save-all-info h3 {
            font-size: 15px;
            font-weight: 800;
            color: #2D3748;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .save-all-card .save-all-info h3 i {
            color: #6B8DB5;
        }

        .save-all-card .save-all-info p {
            font-size: 12px;
            color: #718096;
            margin: 0;
        }

        .btn-save-all {
            background: linear-gradient(135deg, #6B8DB5, #5a7da5);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 28px;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(107, 141, 181, 0.35);
            border: none;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            transition: all 0.25s;
            white-space: nowrap;
        }

        .btn-save-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(107, 141, 181, 0.45);
        }

        .btn-save-all:active {
            transform: translateY(0);
        }

        .btn-save-all.saving {
            opacity: 0.7;
            pointer-events: none;
        }

        /* ── Misc ─────────────────────────────────────── */
        .text-muted {
            color: #A0AEC0;
            font-size: 13px;
        }

        .text-muted-sm {
            font-size: 12px;
            color: #A0AEC0;
        }

        input[type=checkbox] {
            width: 15px;
            height: 15px;
            accent-color: #6B8DB5;
            cursor: pointer;
        }

        @keyframes rowSlideIn {
            from {
                opacity: 0;
                transform: translateY(-8px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        /* ── Combobox styles (copied from viewer) ─────── */
        .cb-wrap {
            position: relative;
            flex: 1;
            /* to fill the space inside pt-split */
        }

        .cb-field {
            display: flex;
            align-items: center;
            padding: 0;
            background: rgba(255, 255, 255, 0.6);
            border: 1.5px solid rgba(107, 141, 181, 0.15);
            border-radius: 0 11px 11px 0;
            /* rounded right side only */
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
            cursor: text;
            overflow: hidden;
            height: 100%;
        }

        .cb-field:focus-within,
        .cb-wrap.cb-open .cb-field {
            border-color: rgba(107, 141, 181, 0.4);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.09);
            background: rgba(255, 255, 255, 0.95);
        }

        .cb-input {
            flex: 1;
            min-width: 0;
            padding: 9px 6px 9px 13px;
            border: none;
            background: transparent;
            outline: none;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            color: #2D3748;
            cursor: text;
        }

        .cb-input::placeholder {
            color: #A0AEC0;
            font-style: italic;
        }

        .cb-input.cb-selected {
            font-weight: 600;
            color: #2D3748;
        }

        .cb-clear {
            display: none;
            border: none;
            background: none;
            cursor: pointer;
            color: #CBD5E0;
            padding: 0 4px;
            font-size: 11px;
            line-height: 1;
            flex-shrink: 0;
            transition: color 0.18s;
        }

        .cb-clear:hover {
            color: #D07070;
        }

        .cb-arrow {
            border: none;
            background: none;
            cursor: pointer;
            color: #A0AEC0;
            padding: 0 11px 0 4px;
            font-size: 10px;
            flex-shrink: 0;
            transition: color 0.18s, transform 0.22s;
            outline: none;
        }

        .cb-wrap.cb-open .cb-arrow {
            color: #6B8DB5;
            transform: rotate(180deg);
        }

        .cb-drop {
            display: none;
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            z-index: 800;
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1.5px solid rgba(107, 141, 181, 0.18);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1), 0 1px 4px rgba(107, 141, 181, 0.08);
            max-height: 220px;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .cb-drop::-webkit-scrollbar {
            width: 4px;
        }

        .cb-drop::-webkit-scrollbar-track {
            background: transparent;
        }

        .cb-drop::-webkit-scrollbar-thumb {
            background: rgba(107, 141, 181, 0.22);
            border-radius: 4px;
        }

        .cb-wrap.cb-open .cb-drop {
            display: block;
            animation: cbDropIn 0.17s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes cbDropIn {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .cb-opt {
            padding: 8px 13px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            color: #4A5568;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: background 0.12s;
        }

        .cb-opt:hover,
        .cb-opt.cb-cursor {
            background: rgba(107, 141, 181, 0.1);
            color: #2D3748;
        }

        .cb-opt.cb-chosen {
            font-weight: 700;
            color: #4A7BA8;
            background: rgba(107, 141, 181, 0.08);
        }

        .cb-opt.cb-all-row {
            color: #A0AEC0;
            font-style: italic;
            border-bottom: 1px solid rgba(107, 141, 181, 0.08);
        }

        .cb-opt.cb-all-row.cb-chosen {
            color: #6B8DB5;
            font-style: normal;
        }

        .cb-opt mark {
            background: none;
            color: #4A7BA8;
            font-weight: 700;
        }

        .cb-empty {
            padding: 10px 13px;
            font-size: 12px;
            color: #CBD5E0;
            font-style: italic;
            text-align: center;
        }

        /* ── Responsive ───────────────────────────────── */
        @media(max-width:768px) {
            .sidebar-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                transform: translateX(-100%);
                z-index: 150;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-body {
                padding: 18px 16px;
            }

            .main-header {
                padding: 16px 18px;
            }

            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
    <title>Editor — Subjective Portal</title>
</head>

<body>
    <canvas id="particles-bg" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none;"></canvas>
    <div class="page-orbs">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="orb orb-4"></div>
        <div class="orb orb-5"></div>
    </div>
    <script>
        (function() {
            var c = document.getElementById('particles-bg'),
                ctx = c.getContext('2d');
            c.width = window.innerWidth;
            c.height = window.innerHeight;
            var pts = [];
            for (var i = 0; i < 50; i++) pts.push({
                x: Math.random() * c.width,
                y: Math.random() * c.height,
                vx: (Math.random() - 0.5) * 0.2,
                vy: (Math.random() - 0.5) * 0.2,
                r: Math.random() * 1.5 + 0.5,
                o: Math.random() * 0.25 + 0.05
            });

            function draw() {
                ctx.clearRect(0, 0, c.width, c.height);
                for (var i = 0; i < pts.length; i++) {
                    var p = pts[i];
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x < 0) p.x = c.width;
                    if (p.x > c.width) p.x = 0;
                    if (p.y < 0) p.y = c.height;
                    if (p.y > c.height) p.y = 0;
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(107,141,181,' + p.o + ')';
                    ctx.fill();
                    for (var j = i + 1; j < pts.length; j++) {
                        var q = pts[j],
                            dx = p.x - q.x,
                            dy = p.y - q.y,
                            d = Math.sqrt(dx * dx + dy * dy);
                        if (d < 150) {
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(q.x, q.y);
                            ctx.strokeStyle = 'rgba(107,141,181,' + (0.04 * (1 - d / 150)) + ')';
                            ctx.stroke();
                        }
                    }
                }
                requestAnimationFrame(draw);
            }
            draw();
            window.addEventListener('resize', function() {
                c.width = window.innerWidth;
                c.height = window.innerHeight;
            });
        })();
    </script>

    <div class="app-layout"><?php include 'navbar.php'; ?><?php include 'modal.php'; ?>
        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-pen-to-square"></i> Insert / Edit Records</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / Editor</div>
            </div>
            <div class="main-body">

                <div class="stats-bar">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-flask"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $tTests ?>">0</div>
                            <div class="stat-label">Testing Records</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-table-columns"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $tFields ?>">0</div>
                            <div class="stat-label">Master Fields</div>
                        </div>
                    </div>
                </div>

                <?php if ($msg): ?><div class="msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

                <!-- ── CREATE ────────────────────────────────────── -->
                <div class="card">
                    <h2><i class="fas fa-plus-circle"></i> Create New Testing Record</h2>
                    <form method="POST" id="fCT">
                        <input type="hidden" name="action" value="create_testing">
                        <label>Project Name:</label><br>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
                            <div id="existingProjectNameComboContainer" style="width:280px;"></div>
                            <input type="text" name="new_project_name" placeholder="Or type new" style="width:280px;">
                        </div><br>
                        <label>Project Title:</label><br>
                        <div class="pt-split" style="margin-top:4px;max-width:560px;">
                            <span class="pt-prefix" id="createPtPrefix"><?= date('Ymd') ?>_</span>
                            <!-- The suffix input will be transformed into a combobox -->
                            <input type="text" name="project_title_suffix" id="createPtSuffix" class="pt-suffix"
                                placeholder="Enter title..." required
                                oninput="updateCreatePreview()">
                        </div>
                        <div style="margin-top:4px;font-size:11px;color:#A0AEC0;" id="createPtPreview">
                            Full title: <strong><?= date('Ymd') ?>_</strong>
                        </div><br>
                        <label>Testing Name:</label><br>
                        <input type="text" name="testing_name" style="width:500px;" required><br><br>
                        <label>Testing Method:</label><br>
                        <input type="text" name="testing_method" style="width:500px;" required><br><br>
                        <button type="button" class="btn btn-success"
                            onclick="confirmSubmit('fCT','confirm','Create Testing','Create this new testing record?')">
                            <i class="fas fa-plus"></i> Create
                        </button>
                    </form>
                </div>

                <!-- ── SELECT / LOAD (cascading) ─────────────────── -->
                <div class="card">
                    <h2><i class="fas fa-list-check"></i> Select Testing Record to Edit</h2>
                    <form method="GET" id="fLoad">
                        <div style="display:flex;flex-direction:column;gap:12px;max-width:640px;">

                            <!-- Project Name - combobox -->
                            <div>
                                <label style="display:block;margin-bottom:4px;">Project Name</label>
                                <div id="projectNameComboContainer" style="width:100%;"></div>
                            </div>

                            <!-- Testing Record - combobox -->
                            <div>
                                <label style="display:block;margin-bottom:4px;">Testing Record</label>
                                <div id="testingRecordComboContainer" style="width:100%;"></div>
                            </div>

                            <div>
                                <button type="submit" id="btnLoad" class="btn btn-primary" disabled>
                                    <i class="fas fa-download"></i> Load
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($editTid && !$editData): ?>
                    <div class="msg-error">Testing #<?= $editTid ?> not found.</div>
                <?php endif; ?>

                <?php if ($editData):
                    $checkedT1 = (int)($editData['checked_by_engineer_t1'] ?? 0);
                    $checkedT2 = (int)($editData['checked_by_engineer_t2'] ?? 0);
                    $ptParts = splitProjectTitle($editData['project_title']);
                ?>

                    <!-- ── META ──────────────────────────────────── -->
                    <div class="card">
                        <h2><i class="fas fa-info-circle"></i> Edit Meta — Testing #<?= $editTid ?></h2>

                        <form method="POST" id="fUM">
                            <input type="hidden" name="action" value="update_meta">
                            <input type="hidden" name="testing_id" value="<?= $editTid ?>">

                            <label>Project Name:</label><br>
                            <input type="text" name="project_name" value="<?= htmlspecialchars($editData['project_name']) ?>" style="width:500px;" required><br><br>

                            <label>Project Title:</label><br>
                            <div class="pt-split" style="margin-top:4px;max-width:560px;">
                                <?php if ($ptParts['prefix'] !== ''): ?>
                                    <span class="pt-prefix"><?= htmlspecialchars($ptParts['prefix']) ?>_</span>
                                    <input type="hidden" name="project_title_prefix" value="<?= htmlspecialchars($ptParts['prefix']) ?>">
                                <?php else: ?>
                                    <!-- No date prefix found — render as plain editable with today's date as new prefix -->
                                    <span class="pt-prefix"><?= date('Ymd') ?>_</span>
                                    <input type="hidden" name="project_title_prefix" value="<?= date('Ymd') ?>">
                                <?php endif; ?>
                                <!-- The suffix input will be transformed into a combobox -->
                                <input type="text" name="project_title_suffix" class="pt-suffix" id="editPtSuffix"
                                    value="<?= htmlspecialchars($ptParts['suffix']) ?>"
                                    required placeholder="Enter title suffix...">
                            </div><br>

                            <label>Testing Name:</label><br>
                            <input type="text" name="testing_name" value="<?= htmlspecialchars($editData['testing_name']) ?>" style="width:500px;" required><br><br>

                            <label>Testing Method:</label><br>
                            <input type="text" name="testing_method" value="<?= htmlspecialchars($editData['testing_method']) ?>" style="width:500px;" required><br><br>

                            <button type="button" class="btn btn-primary"
                                onclick="confirmSubmit('fUM','confirm','Update Meta','Update meta for Testing #<?= $editTid ?>?')">
                                <i class="fas fa-save"></i> Update Meta
                            </button>
                        </form>
                    </div>

                    <!-- ── FIELDS ─────────────────────────────────── -->
                    <div class="card">
                        <h2><i class="fas fa-sliders"></i> Configure Fields — Testing #<?= $editTid ?></h2>
                        <form method="POST" id="fCF">
                            <input type="hidden" name="action" value="configure_fields">
                            <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                            <p class="text-muted-sm" style="margin-bottom:12px;"><i class="fas fa-info-circle"></i> Unchecking removes the field and all its data.</p>
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:60px;">Include</th>
                                        <th>Field Name</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mfArr as $mf): ?>
                                        <tr>
                                            <td style="text-align:center;">
                                                <input type="checkbox" name="selected_fields[]" value="<?= (int)$mf['master_field_id'] ?>"
                                                    <?= in_array((int)$mf['master_field_id'], $editCFK) ? 'checked' : '' ?>>
                                            </td>
                                            <td><?= htmlspecialchars($mf['field_name']) ?></td>
                                            <td><span class="badge <?= $mf['value_type'] === 'text' ? 'badge-text' : 'badge-decimal' ?>"><?= htmlspecialchars($mf['value_type']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table><br>
                            <button type="button" class="btn btn-warning"
                                onclick="confirmSubmit('fCF','warning','Update Fields','Unchecked fields and data will be removed. Continue?')">
                                <i class="fas fa-save"></i> Save Configuration
                            </button>
                        </form>
                    </div>

                    <!-- ── RECORDS ────────────────────────────────── -->
                    <div class="card">
                        <h2><i class="fas fa-table"></i> Record Data — Testing #<?= $editTid ?></h2>
                        <!-- Engineer Check for Table 1 (inline, AJAX) -->
                        <div class="engineer-check-inline" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                            <div class="engineer-check-row <?= $checkedT1 ? '' : 'unchecked' ?>" id="engCheckRowT1" style="flex-shrink:0;">
                                <label for="cbEngineerT1" style="cursor:pointer;">
                                    <input type="checkbox" id="cbEngineerT1"
                                        <?= $checkedT1 ? 'checked' : '' ?>
                                        onchange="ajaxEngineerCheck(1,this.checked)">
                                    <i class="fas fa-user-check" style="color:inherit;"></i>
                                    Table 1 — Checked by Engineer
                                </label>
                                <span style="font-size:12px;color:inherit;opacity:0.7;" id="engLabelT1">
                                    <?= $checkedT1 ? 'Verified ✓' : 'Not yet verified' ?>
                                </span>
                            </div>
                            <?php if (!empty($editRowsOther)): ?>
                            <div class="engineer-check-row <?= $checkedT2 ? '' : 'unchecked' ?>" id="engCheckRowT2" style="flex-shrink:0;">
                                <label for="cbEngineerT2" style="cursor:pointer;">
                                    <input type="checkbox" id="cbEngineerT2"
                                        <?= $checkedT2 ? 'checked' : '' ?>
                                        onchange="ajaxEngineerCheck(2,this.checked)">
                                    <i class="fas fa-user-check" style="color:inherit;"></i>
                                    Table 2 — Checked by Engineer
                                </label>
                                <span style="font-size:12px;color:inherit;opacity:0.7;" id="engLabelT2">
                                    <?= $checkedT2 ? 'Verified ✓' : 'Not yet verified' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($editFields)): ?>
                            <div class="empty-state"><i class="fas fa-table-columns"></i>
                                <p>No fields configured yet.</p>
                            </div>
                        <?php else:
                            $tf  = [];
                            $nf  = [];
                            foreach ($editFields as $fd) {
                                if ($fd['value_type'] === 'text') $tf[] = $fd;
                                else $nf[] = $fd;
                            }
                            $erc = count($editRows);
                            $dr  = $erc > 0 ? $erc : 1;
                            $nfkJ = json_encode(array_map(fn($f) => (int)$f['field_key'], $nf));
                            $tfkJ = json_encode(array_map(fn($f) => (int)$f['field_key'], $tf));
                        ?>
                            <form method="POST" id="fSR">
                                <input type="hidden" name="action" value="save_records">
                                <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                                <input type="hidden" name="num_rows" id="numRows" value="<?= $dr ?>">
                                <div class="overflow-x">
                                    <table id="recordTable">
                                        <thead>
                                            <tr>
                                                <th style="width:40px;">#</th>
                                                <?php foreach ($tf as $fd): ?><th><?= htmlspecialchars($fd['field_name']) ?></th><?php endforeach; ?>
                                                <th style="text-align:center;"><i class="fas fa-calculator"></i> Avg</th>
                                                <?php foreach ($nf as $fd): ?><th><?= htmlspecialchars($fd['field_name']) ?></th><?php endforeach; ?>
                                                <th style="width:60px;text-align:center;">Del</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            if ($erc > 0) {
                                                $ri = 0;
                                                foreach ($editRows as $rn => $rd) {
                                                    $ri++;
                                                    echo "<tr data-rn='$rn'>";
                                                    echo "<td style='text-align:center;color:#A0AEC0;font-weight:700;'>$ri</td>";
                                                    foreach ($tf as $fd) {
                                                        $fk = (int)$fd['field_key'];
                                                        $v  = htmlspecialchars($rd[$fk] ?? '');
                                                        echo "<td><textarea class='cell-input' name='records[$rn][$fk]' rows='1' oninput='autoResize(this)'>$v</textarea></td>";
                                                    }
                                                    $nv = [];
                                                    foreach ($nf as $fd) {
                                                        $fk = (int)$fd['field_key'];
                                                        $v  = $rd[$fk] ?? '';
                                                        if ($v !== '' && $v !== null && (float)$v >= 0) $nv[] = (float)$v;
                                                    }
                                                    if (count($nv) > 0) {
                                                        $avg = array_sum($nv) / count($nv);
                                                        $cls = getAvgClass($avg);
                                                        echo "<td class='avg-cell $cls'>" . number_format(max(0, $avg), 3) . "</td>";
                                                    } else echo "<td class='avg-cell avg-na'>N/A</td>";
                                                    foreach ($nf as $fd) {
                                                        $fk = (int)$fd['field_key'];
                                                        $v  = htmlspecialchars($rd[$fk] ?? '');
                                                        echo "<td><textarea class='cell-input' name='records[$rn][$fk]' rows='1' oninput='autoResize(this)'>$v</textarea></td>";
                                                    }
                                                    echo "<td style='text-align:center;'><button type='button' class='btn btn-danger btn-sm' onclick='deleteRow(this,$rn)'><i class='fas fa-trash'></i></button></td>";
                                                    echo "</tr>";
                                                }
                                            } else {
                                                echo "<tr data-rn='1'>";
                                                echo "<td style='text-align:center;color:#A0AEC0;font-weight:700;'>1</td>";
                                                foreach ($tf as $fd) {
                                                    $fk = (int)$fd['field_key'];
                                                    echo "<td><textarea class='cell-input' name='records[1][$fk]' rows='1' oninput='autoResize(this)'></textarea></td>";
                                                }
                                                echo "<td class='avg-cell avg-na'>N/A</td>";
                                                foreach ($nf as $fd) {
                                                    $fk = (int)$fd['field_key'];
                                                    echo "<td><textarea class='cell-input' name='records[1][$fk]' rows='1' oninput='autoResize(this)'></textarea></td>";
                                                }
                                                echo "<td style='text-align:center;'><button type='button' class='btn btn-danger btn-sm' onclick='deleteRow(this,1)'><i class='fas fa-trash'></i></button></td>";
                                                echo "</tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div><br>
                                <button type="button" class="btn btn-secondary" onclick="addRow()"><i class="fas fa-plus"></i> Add Row</button>
                                <button type="button" class="btn btn-success"
                                    onclick="confirmSubmit('fSR','confirm','Save Records','Save all records for Testing #<?= $editTid ?>?')">
                                    <i class="fas fa-save"></i> Save All
                                </button>
                            </form>

                            <!-- Hidden delete-row form -->
                            <form method="POST" id="fDR" style="display:none;">
                                <input type="hidden" name="action" value="delete_row">
                                <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                                <input type="hidden" name="row_number" id="drRowNum" value="">
                            </form>

                            <script>
                                var tfk = <?= $tfkJ ?>;
                                var nfk = <?= $nfkJ ?>;

                                function autoResize(el) {
                                    el.style.height = 'auto';
                                    el.style.height = (el.scrollHeight) + 'px';
                                }
                                // Auto-resize all on load
                                document.addEventListener('DOMContentLoaded', function() {
                                    document.querySelectorAll('textarea.cell-input').forEach(function(el) {
                                        autoResize(el);
                                    });
                                });

                                function addRow() {
                                    var tb = document.getElementById('recordTable').getElementsByTagName('tbody')[0];
                                    var nr = document.getElementById('numRows');
                                    var rn = parseInt(nr.value) + 1;
                                    nr.value = rn;
                                    var tr = document.createElement('tr');
                                    tr.dataset.rn = rn;

                                    var td0 = document.createElement('td');
                                    td0.style.textAlign = 'center';
                                    td0.style.color = '#A0AEC0';
                                    td0.style.fontWeight = '700';
                                    td0.textContent = tb.rows.length + 1;
                                    tr.appendChild(td0);

                                    for (var i = 0; i < tfk.length; i++) {
                                        var td = document.createElement('td');
                                        var ta = document.createElement('textarea');
                                        ta.className = 'cell-input';
                                        ta.rows = 1;
                                        ta.name = 'records[' + rn + '][' + tfk[i] + ']';
                                        ta.setAttribute('oninput', 'autoResize(this)');
                                        td.appendChild(ta);
                                        tr.appendChild(td);
                                    }
                                    var at = document.createElement('td');
                                    at.className = 'avg-cell avg-na';
                                    at.textContent = 'N/A';
                                    tr.appendChild(at);

                                    for (var j = 0; j < nfk.length; j++) {
                                        var td2 = document.createElement('td');
                                        var ta2 = document.createElement('textarea');
                                        ta2.className = 'cell-input';
                                        ta2.rows = 1;
                                        ta2.name = 'records[' + rn + '][' + nfk[j] + ']';
                                        ta2.setAttribute('oninput', 'autoResize(this)');
                                        td2.appendChild(ta2);
                                        tr.appendChild(td2);
                                    }

                                    // Delete button
                                    var tdDel = document.createElement('td');
                                    tdDel.style.textAlign = 'center';
                                    var btnDel = document.createElement('button');
                                    btnDel.type = 'button';
                                    btnDel.className = 'btn btn-danger btn-sm';
                                    btnDel.innerHTML = '<i class="fas fa-trash"></i>';
                                    btnDel.onclick = (function(r) {
                                        return function() {
                                            deleteRow(btnDel, r);
                                        };
                                    })(rn);
                                    tdDel.appendChild(btnDel);
                                    tr.appendChild(tdDel);

                                    tb.appendChild(tr);
                                    tr.style.animation = 'rowSlideIn 0.3s ease both';
                                }

                                function deleteRow(btn, rn) {
                                    var isNew = !btn.closest('tr').dataset.rnSaved;
                                    // If rn is a new unsaved row (no server-side data), just remove from DOM
                                    var tid = <?= $editTid ?>;
                                    // Check if this row actually exists in DB by seeing if rn <= original row count
                                    var origCount = <?= $erc ?>;
                                    if (parseInt(rn) > origCount) {
                                        // Unsaved new row — just remove from DOM
                                        var tr = btn.closest('tr');
                                        tr.style.transition = 'opacity 0.2s';
                                        tr.style.opacity = '0';
                                        setTimeout(function() {
                                            tr.remove();
                                            renumberRows();
                                        }, 200);
                                        return;
                                    }
                                    // Saved row — confirm then POST delete
                                    document.getElementById('drRowNum').value = rn;
                                    confirmSubmit('fDR', 'danger', 'Delete Row',
                                        'All data in this row will be permanently deleted. This cannot be undone.');
                                }

                                function renumberRows() {
                                    var rows = document.querySelectorAll('#recordTable tbody tr');
                                    rows.forEach(function(tr, i) {
                                        var numCell = tr.querySelector('td:first-child');
                                        if (numCell) numCell.textContent = i + 1;
                                    });
                                    document.getElementById('numRows').value = rows.length;
                                }
                            </script>
                        <?php endif; ?>
                        <?php if (!empty($editRowsOther)): 
                            $otherLabel = $isOwner ? 'Table 2 (edited by: ' . htmlspecialchars($editedByT2 ?? 'Unknown') . ')' : 'Table 1 (created by: ' . htmlspecialchars($editData['created_by'] ?? 'Unknown') . ')';
                        ?>
                        <h3 style="margin-top:22px;font-size:14px;font-weight:700;color:#4A5568;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-table" style="color:#C1A0D8;"></i> Record Data — <?= $otherLabel ?> <span style="font-size:11px;font-weight:500;color:#A0AEC0;">(Read-only)</span>
                        </h3>
                        <div class="overflow-x" style="margin-top:10px;opacity:0.85;">
                            <table>
                                <thead><tr>
                                    <th style="width:40px;">#</th>
                                    <?php foreach ($editFields as $fd): ?><th><?= htmlspecialchars($fd['field_name']) ?></th><?php endforeach; ?>
                                    <th style="text-align:center;"><i class="fas fa-calculator"></i> Avg</th>
                                </tr></thead>
                                <tbody>
                                <?php if (empty($editRowsOther)): ?>
                                    <tr><td colspan="<?= count($editFields) + 2 ?>" style="text-align:center;color:#A0AEC0;padding:16px;">No data</td></tr>
                                <?php else:
                                    $ri2 = 0;
                                    foreach ($editRowsOther as $rn2 => $rd2):
                                        $ri2++;
                                        $nv2 = [];
                                        foreach ($editFields as $fd2) {
                                            if ($fd2['value_type'] !== 'text') {
                                                $v2 = $rd2[(int)$fd2['field_key']] ?? '';
                                                if ($v2 !== '' && is_numeric($v2) && (float)$v2 >= 0) $nv2[] = (float)$v2;
                                            }
                                        }
                                        $avg2 = count($nv2) > 0 ? array_sum($nv2) / count($nv2) : null;
                                        $cls2 = getAvgClass($avg2);
                                ?>
                                    <tr>
                                        <td style="text-align:center;color:#A0AEC0;font-weight:700;"><?= $ri2 ?></td>
                                        <?php foreach ($editFields as $fd2):
                                            $fk2 = (int)$fd2['field_key'];
                                            $v2 = htmlspecialchars($rd2[$fk2] ?? '');
                                        ?>
                                            <td style="color:#4A5568;"><?= $v2 !== '' ? $v2 : '—' ?></td>
                                        <?php endforeach; ?>
                                        <?php if ($avg2 !== null): ?>
                                            <td class="avg-cell <?= $cls2 ?>"><?= number_format(max(0,$avg2),3) ?></td>
                                        <?php else: ?>
                                            <td class="avg-cell avg-na">N/A</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── MEDIA ──────────────────────────────────── -->
                    <div class="card">
                        <h2><i class="fas fa-images"></i> Media — Testing #<?= $editTid ?></h2>

                        <?php if (!empty($editMedia)):
                            // Group saved media
                            $mediaByGroup = [];
                            foreach ($editMedia as $med) {
                                $g = ($med['group_name'] !== null && $med['group_name'] !== '') ? $med['group_name'] : '__ungrouped__';
                                $mediaByGroup[$g][] = $med;
                            }
                            // Sort: ungrouped last
                            uksort($mediaByGroup, function ($a, $b) {
                                if ($a === '__ungrouped__') return 1;
                                if ($b === '__ungrouped__') return -1;
                                return strcasecmp($a, $b);
                            });
                            $globalMedIdx = 0;
                            $groupIdx = 0;
                            foreach ($mediaByGroup as $groupName => $meds):
                                $displayName = ($groupName === '__ungrouped__') ? 'Ungrouped' : htmlspecialchars($groupName);
                                $safeGroup   = htmlspecialchars($groupName, ENT_QUOTES);
                                $fRenameId   = 'fRG' . $groupIdx;
                                $fDeleteId   = 'fDG' . $groupIdx;
                                $renameInputId = 'rginput-' . $groupIdx;
                                $renameSaveBtnId = 'rgsave-' . $groupIdx;
                        ?>
                                <div class="media-group-block">
                                    <div class="media-group-header">
                                        <i class="fas fa-<?= $groupName === '__ungrouped__' ? 'folder-open' : 'folder' ?>"></i>
                                        <!-- Rename form -->
                                        <form method="POST" id="<?= $fRenameId ?>" style="display:none;">
                                            <input type="hidden" name="action" value="rename_media_group">
                                            <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                                            <input type="hidden" name="old_group_name" value="<?= $safeGroup ?>">
                                            <input type="hidden" name="new_group_name" id="<?= $renameInputId ?>-hidden" value="">
                                        </form>
                                        <div class="group-rename-wrap">
                                            <input type="text"
                                                class="group-rename-input"
                                                id="<?= $renameInputId ?>"
                                                value="<?= $displayName ?>"
                                                <?= $groupName === '__ungrouped__' ? 'placeholder="Ungrouped"' : '' ?>
                                                oninput="onGroupRenameInput('<?= $renameInputId ?>', '<?= $renameSaveBtnId ?>', '<?= $safeGroup ?>')"
                                                onkeydown="if(event.key==='Enter'){event.preventDefault();saveGroupRename('<?= $fRenameId ?>','<?= $renameInputId ?>','<?= $renameSaveBtnId ?>','<?= $safeGroup ?>');}"
                                                onkeyup="if(event.key==='Escape'){resetGroupRename('<?= $renameInputId ?>','<?= $renameSaveBtnId ?>','<?= addslashes($displayName) ?>');}">
                                            <button type="button"
                                                class="btn-group-rename-save"
                                                id="<?= $renameSaveBtnId ?>"
                                                onclick="saveGroupRename('<?= $fRenameId ?>','<?= $renameInputId ?>','<?= $renameSaveBtnId ?>','<?= $safeGroup ?>')">
                                                <i class="fas fa-check"></i> Save
                                            </button>
                                        </div>
                                        <span class="media-group-count"><?= count($meds) ?> file<?= count($meds) !== 1 ? 's' : '' ?></span>
                                        <!-- Delete group button -->
                                        <?php if ($groupName !== '__ungrouped__'): ?>
                                            <form method="POST" id="<?= $fDeleteId ?>" style="display:none;">
                                                <input type="hidden" name="action" value="delete_media_group">
                                                <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                                                <input type="hidden" name="group_name" value="<?= $safeGroup ?>">
                                            </form>
                                            <button type="button" class="btn-group-delete"
                                                onclick="confirmSubmit('<?= $fDeleteId ?>','danger','Delete Group','Delete the entire group &quot;<?= $safeGroup ?>&quot; and all its files? This cannot be undone.')">
                                                <i class="fas fa-trash-can"></i> Delete Group
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" id="<?= $fDeleteId ?>" style="display:none;">
                                                <input type="hidden" name="action" value="delete_media_group">
                                                <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                                                <input type="hidden" name="group_name" value="__ungrouped__">
                                            </form>
                                            <button type="button" class="btn-group-delete"
                                                onclick="confirmSubmit('<?= $fDeleteId ?>','danger','Delete Ungrouped Files','Delete all ungrouped media files? This cannot be undone.')">
                                                <i class="fas fa-trash-can"></i> Delete Group
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="media-group-body">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Preview</th>
                                                    <th>File Name</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($meds as $med): $globalMedIdx++; ?>
                                                    <tr>
                                                        <td><?php
                                                            $url = "media.php?id=" . (int)$med['media_id'];
                                                            $mt  = $med['mime_type'] ?? '';
                                                            if (str_starts_with($mt, "image/")) echo "<a href='$url' target='_blank'><img src='$url' width='100' style='border-radius:12px;'></a>";
                                                            else echo "<a href='$url' target='_blank' class='btn btn-sm btn-secondary'><i class='fas fa-file'></i> View</a>";
                                                            ?></td>
                                                        <td>
                                                            <div class="group-rename-wrap" id="fname-wrap-<?= (int)$med['media_id'] ?>">
                                                                <input type="text" class="group-rename-input" 
                                                                    id="fname-<?= (int)$med['media_id'] ?>"
                                                                    value="<?= htmlspecialchars($med['file_name']) ?>"
                                                                    oninput="onFileRenameInput(<?= (int)$med['media_id'] ?>)"
                                                                    onkeydown="if(event.key==='Enter'){event.preventDefault();saveFileRename(<?= (int)$med['media_id'] ?>);}"
                                                                    onkeyup="if(event.key==='Escape'){resetFileRename(<?= (int)$med['media_id'] ?>, <?= json_encode($med['file_name']) ?>);}">
                                                                <button type="button" class="btn-group-rename-save" id="fname-save-<?= (int)$med['media_id'] ?>"
                                                                    onclick="saveFileRename(<?= (int)$med['media_id'] ?>)">
                                                                    <i class="fas fa-check"></i> Save
                                                                </button>
                                                            </div>
                                                            <!-- hidden form for file rename -->
                                                            <form method="POST" id="fFR<?= (int)$med['media_id'] ?>" style="display:none;">
                                                                <input type="hidden" name="action" value="rename_media_file">
                                                                <input type="hidden" name="media_id" value="<?= (int)$med['media_id'] ?>">
                                                                <input type="hidden" name="new_file_name" id="ffr-name-<?= (int)$med['media_id'] ?>" value="">
                                                            </form>
                                                        </td>
                                                        <td>
                                                            <form method="POST" id="fDM<?= $globalMedIdx ?>">
                                                                <input type="hidden" name="action" value="delete_media">
                                                                <input type="hidden" name="media_id" value="<?= (int)$med['media_id'] ?>">
                                                                <button type="button" class="btn btn-danger btn-sm"
                                                                    onclick="confirmSubmit('fDM<?= $globalMedIdx ?>','danger','Delete Media','Delete &quot;<?= htmlspecialchars($med['file_name'], ENT_QUOTES) ?>&quot;?')">
                                                                    <i class="fas fa-trash"></i> Delete
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php $groupIdx++;
                            endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted"><i class="fas fa-cloud-arrow-up"></i> No media files yet.</p>
                        <?php endif; ?>
                        <br>

                        <!-- Grouped batch upload form -->
                        <form method="POST" enctype="multipart/form-data" id="fUpM">
                            <input type="hidden" name="action" value="upload_media">
                            <input type="hidden" name="testing_id" value="<?= $editTid ?>">

                            <div class="pending-groups-container" id="pendingGroupsContainer"></div>

                            <button type="button" class="btn-add-group" id="btnAddGroup" onclick="addMediaGroup()">
                                <i class="fas fa-plus"></i> Add Media Group
                            </button>

                            <br><br>
                            <button type="button" class="btn btn-primary" id="btnUpload" disabled
                                onclick="submitGroupedUpload()">
                                <i class="fas fa-upload"></i> Upload All Groups
                            </button>

                            <div class="paste-hint" id="pasteHint">
                                <i class="fas fa-clipboard"></i>
                                <span id="pasteHintText">Press <kbd>Ctrl</kbd>+<kbd>V</kbd> (or <kbd>⌘V</kbd>) anywhere on the page to paste a copied image into the last group.</span>
                            </div>
                        </form>
                    </div>

                    <!-- ── DESCRIPTIONS ───────────────────────────── -->
                    <div class="card">
                        <h2><i class="fas fa-align-left"></i> Descriptions — Testing #<?= $editTid ?></h2>
                        <?php if (!empty($editDesc)): foreach ($editDesc as $i => $desc): ?>
                                <div class="desc-card">
                                    <pre style="margin:0;border:none;background:none;padding:0;"><?= htmlspecialchars($desc['content']) ?></pre>
                                    <form method="POST" id="fDD<?= $i ?>" style="margin-top:10px;">
                                        <input type="hidden" name="action" value="delete_description">
                                        <input type="hidden" name="description_id" value="<?= (int)$desc['description_id'] ?>">
                                        <button type="button" class="btn btn-danger btn-sm"
                                            onclick="confirmSubmit('fDD<?= $i ?>','danger','Delete Description','Delete this description?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach;
                        else: ?>
                            <p class="text-muted"><i class="fas fa-pen"></i> No descriptions yet.</p>
                        <?php endif; ?>
                        <br>

                        <!-- Batch add descriptions -->
                        <form method="POST" id="fAD">
                            <input type="hidden" name="action" value="add_descriptions">
                            <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                            <div id="pendingDescsList" class="pending-descs"></div>
                            <div id="descInputArea">
                                <label><i class="fas fa-plus"></i> New Description:</label><br>
                                <textarea id="descDraftInput" rows="5" placeholder="Enter description..."></textarea><br>
                                <button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;" onclick="addDescToQueue()">
                                    <i class="fas fa-plus"></i> Add to Queue
                                </button>
                            </div>
                            <br>
                            <button type="button" class="btn btn-success" id="btnAddDescs" disabled
                                onclick="finaliseDescs()">
                                <i class="fas fa-plus"></i> Save All Descriptions
                            </button>
                        </form>
                    </div>

                    <!-- ── SAVE ALL ───────────────────────────────── -->
                    <div class="save-all-card">
                        <div class="save-all-info">
                            <h3><i class="fas fa-floppy-disk"></i> Save Everything</h3>
                            <p>Saves Field Configuration, Record Data, any pending Media files, and queued Descriptions in one action.</p>
                        </div>
                        <button type="button" class="btn-save-all" id="btnSaveAll" onclick="saveAll()">
                            <i class="fas fa-floppy-disk"></i> Save All
                        </button>
                    </div>

                    <!-- ── DANGER ZONE ────────────────────────────── -->
                    <div class="card" style="border-color:rgba(224,112,112,0.25);background:rgba(255,240,240,0.4);">
                        <h2 style="color:#C53030;"><i class="fas fa-triangle-exclamation"></i> Danger Zone</h2>
                        <p style="font-size:13px;color:#718096;margin-bottom:18px;">
                            Permanently delete Testing <strong>#<?= $editTid ?></strong> and <strong>all</strong>
                            associated data — records, fields, media, and descriptions. This cannot be undone.
                        </p>
                        <form method="POST" id="fDT">
                            <input type="hidden" name="action" value="delete_testing">
                            <input type="hidden" name="testing_id" value="<?= $editTid ?>">
                            <button type="button" class="btn btn-danger"
                                onclick="confirmSubmit('fDT','danger','Delete Testing #<?= $editTid ?>','This will permanently delete Testing #<?= $editTid ?> and ALL its records, fields, media, and descriptions. This cannot be undone.')">
                                <i class="fas fa-trash"></i> Delete Testing #<?= $editTid ?>
                            </button>
                        </form>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
    <!-- makeSearchableDropdown function (adapted from viewer) -->
    <script>
        // makeSearchableDropdown: creates a combobox with value/label options
        // options: array of {value, label}
        // initialValue: the value to pre-select (or empty)
        // onChange: callback(value) when selection changes
        // inputName: if provided, a hidden input with that name is added (for form submission)
        // returns object { el, getValue(), setValue(val), setOptions(newOptions) }
        function makeSearchableDropdown(options, initialValue, onChange, inputName) {
            var pool = options.slice(); // full option list (objects)
            var chosen = initialValue || ''; // committed value
            var cursor = -1;

            // container
            var wrap = document.createElement('div');
            wrap.className = 'cb-wrap';

            // hidden input (if name given)
            var hidden = null;
            if (inputName) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = inputName;
                hidden.value = chosen;
                wrap.appendChild(hidden);
            }

            // visible field
            var field = document.createElement('div');
            field.className = 'cb-field';

            var textEl = document.createElement('input');
            textEl.type = 'text';
            textEl.className = 'cb-input' + (chosen ? ' cb-selected' : '');
            // find label for initial value
            var initOpt = pool.find(o => o.value == chosen);
            textEl.value = initOpt ? initOpt.label : '';
            textEl.placeholder = '— All —';
            textEl.setAttribute('autocomplete', 'off');
            textEl.setAttribute('spellcheck', 'false');

            var btnClear = document.createElement('button');
            btnClear.type = 'button';
            btnClear.className = 'cb-clear';
            btnClear.innerHTML = '<i class="fas fa-times"></i>';
            btnClear.title = 'Clear';
            if (chosen) btnClear.style.display = 'inline-block';

            var btnArrow = document.createElement('button');
            btnArrow.type = 'button';
            btnArrow.className = 'cb-arrow';
            btnArrow.innerHTML = '<i class="fas fa-chevron-down"></i>';
            btnArrow.tabIndex = -1;

            field.appendChild(textEl);
            field.appendChild(btnClear);
            field.appendChild(btnArrow);
            wrap.appendChild(field);

            // dropdown panel
            var drop = document.createElement('div');
            drop.className = 'cb-drop';
            wrap.appendChild(drop);

            // helpers
            function isOpen() {
                return wrap.classList.contains('cb-open');
            }

            function openDrop(filterTerm) {
                wrap.classList.add('cb-open');
                renderDrop(filterTerm !== undefined ? filterTerm : '');
                var sel = drop.querySelector('.cb-opt.cb-chosen');
                if (sel) sel.scrollIntoView({
                    block: 'nearest'
                });
            }

            function closeDrop(restoreText) {
                wrap.classList.remove('cb-open');
                cursor = -1;
                if (restoreText) {
                    var opt = pool.find(o => o.value == chosen);
                    textEl.value = opt ? opt.label : '';
                    textEl.className = 'cb-input' + (chosen ? ' cb-selected' : '');
                }
            }

            function commit(val) {
                chosen = val;
                if (hidden) hidden.value = val;
                var opt = pool.find(o => o.value == val);
                textEl.value = opt ? opt.label : '';
                textEl.className = 'cb-input' + (val ? ' cb-selected' : '');
                btnClear.style.display = val ? 'inline-block' : 'none';
                closeDrop(false);
                if (onChange) onChange(val);
            }

            function esc(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function renderDrop(filterTerm) {
                drop.innerHTML = '';
                cursor = -1;
                var term = (filterTerm || '').trim().toLowerCase();

                // "— All —" row (value empty)
                var allRow = document.createElement('div');
                allRow.className = 'cb-opt cb-all-row' + (chosen === '' ? ' cb-chosen' : '');
                allRow.dataset.v = '';
                allRow.textContent = '— All —';
                drop.appendChild(allRow);

                var hits = 0;
                pool.forEach(function(opt) {
                    var label = opt.label;
                    if (term && label.toLowerCase().indexOf(term) === -1) return;
                    var row = document.createElement('div');
                    row.className = 'cb-opt' + (opt.value == chosen ? ' cb-chosen' : '');
                    row.dataset.v = opt.value;
                    if (term) {
                        var lo = label.toLowerCase(),
                            si = lo.indexOf(term);
                        row.innerHTML = esc(label.slice(0, si)) +
                            '<mark>' + esc(label.slice(si, si + term.length)) + '</mark>' +
                            esc(label.slice(si + term.length));
                    } else {
                        row.textContent = label;
                    }
                    drop.appendChild(row);
                    hits++;
                });

                if (hits === 0 && term) {
                    var empty = document.createElement('div');
                    empty.className = 'cb-empty';
                    empty.textContent = 'No matches for "' + filterTerm + '"';
                    drop.appendChild(empty);
                }
            }

            function visibleOpts() {
                return Array.prototype.slice.call(drop.querySelectorAll('.cb-opt[data-v]'));
            }

            function moveCursor(dir) {
                var opts = visibleOpts();
                if (!opts.length) return;
                opts.forEach(o => o.classList.remove('cb-cursor'));
                cursor = Math.max(0, Math.min(opts.length - 1, cursor + dir));
                opts[cursor].classList.add('cb-cursor');
                opts[cursor].scrollIntoView({
                    block: 'nearest'
                });
            }

            // events
            textEl.addEventListener('focus', function() {
                if (!isOpen()) openDrop('');
            });

            textEl.addEventListener('input', function() {
                if (!isOpen()) wrap.classList.add('cb-open');
                renderDrop(textEl.value);
            });

            textEl.addEventListener('keydown', function(e) {
                if (!isOpen() && (e.key === 'ArrowDown' || e.key === 'Enter')) {
                    e.preventDefault();
                    openDrop('');
                    return;
                }
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveCursor(+1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    moveCursor(-1);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var opts = visibleOpts();
                    if (cursor >= 0 && opts[cursor]) {
                        commit(opts[cursor].dataset.v);
                    } else {
                        // if only one real option remains, auto-select
                        var real = opts.filter(o => o.dataset.v !== '');
                        if (real.length === 1) commit(real[0].dataset.v);
                        else closeDrop(true);
                    }
                } else if (e.key === 'Escape') {
                    closeDrop(true);
                    textEl.blur();
                } else if (e.key === 'Tab') {
                    closeDrop(true);
                }
            });

            textEl.addEventListener('blur', function() {
                setTimeout(function() {
                    if (isOpen()) closeDrop(true);
                }, 180);
            });

            drop.addEventListener('mousedown', function(e) {
                var opt = e.target.closest('.cb-opt[data-v]');
                if (opt) {
                    e.preventDefault();
                    commit(opt.dataset.v);
                }
            });

            btnArrow.addEventListener('mousedown', function(e) {
                e.preventDefault();
                if (isOpen()) {
                    closeDrop(true);
                    textEl.blur();
                } else {
                    textEl.focus();
                }
            });

            btnClear.addEventListener('mousedown', function(e) {
                e.preventDefault();
                commit('');
                textEl.focus();
            });

            // public API
            return {
                el: wrap,
                getValue: function() {
                    return chosen;
                },
                setValue: function(val) {
                    commit(val);
                },
                setOptions: function(newOptions) {
                    pool = newOptions.slice();
                    // if current selection no longer exists, clear
                    if (chosen && !pool.some(o => o.value == chosen)) commit('');
                    else {
                        // re-render dropdown if open
                        if (isOpen()) renderDrop(textEl.value);
                    }
                }
            };
        }

        // NEW: Function to turn an existing input into a combobox (for suffix fields)
        function enhanceSuffixInput(inputEl, options, onChange) {
            // Create a wrapper and move the input inside
            var parent = inputEl.parentNode;
            var wrap = document.createElement('div');
            wrap.className = 'cb-wrap';
            parent.replaceChild(wrap, inputEl);

            // Build field div
            var field = document.createElement('div');
            field.className = 'cb-field';

            // The existing input becomes the text input
            inputEl.classList.add('cb-input');
            if (inputEl.value) inputEl.classList.add('cb-selected');
            inputEl.placeholder = '— All —'; // optional
            inputEl.removeAttribute('oninput'); // we'll reattach after
            var previewFn = inputEl.oninput; // store the preview handler
            inputEl.oninput = null;

            // Create clear and arrow buttons
            var btnClear = document.createElement('button');
            btnClear.type = 'button';
            btnClear.className = 'cb-clear';
            btnClear.innerHTML = '<i class="fas fa-times"></i>';
            btnClear.title = 'Clear';
            if (inputEl.value) btnClear.style.display = 'inline-block';

            var btnArrow = document.createElement('button');
            btnArrow.type = 'button';
            btnArrow.className = 'cb-arrow';
            btnArrow.innerHTML = '<i class="fas fa-chevron-down"></i>';
            btnArrow.tabIndex = -1;

            field.appendChild(inputEl);
            field.appendChild(btnClear);
            field.appendChild(btnArrow);
            wrap.appendChild(field);

            // dropdown panel
            var drop = document.createElement('div');
            drop.className = 'cb-drop';
            wrap.appendChild(drop);

            // State
            var pool = options.map(function(s) {
                return {
                    value: s,
                    label: s
                };
            });
            var chosen = inputEl.value || '';
            var cursor = -1;

            function isOpen() {
                return wrap.classList.contains('cb-open');
            }

            function openDrop(filterTerm) {
                wrap.classList.add('cb-open');
                renderDrop(filterTerm !== undefined ? filterTerm : inputEl.value);
                var sel = drop.querySelector('.cb-opt.cb-chosen');
                if (sel) sel.scrollIntoView({
                    block: 'nearest'
                });
            }

            function closeDrop(restoreText) {
                wrap.classList.remove('cb-open');
                cursor = -1;
                if (restoreText) {
                    // no change
                }
            }

            function commit(val) {
                chosen = val;
                inputEl.value = val;
                inputEl.className = 'cb-input' + (val ? ' cb-selected' : '');
                btnClear.style.display = val ? 'inline-block' : 'none';
                closeDrop(false);
                if (onChange) onChange(val);
                if (previewFn) previewFn.call(inputEl, {
                    target: inputEl
                }); // trigger preview
            }

            function esc(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function renderDrop(filterTerm) {
                drop.innerHTML = '';
                cursor = -1;
                var term = (filterTerm || '').trim().toLowerCase();

                var allRow = document.createElement('div');
                allRow.className = 'cb-opt cb-all-row' + (chosen === '' ? ' cb-chosen' : '');
                allRow.dataset.v = '';
                allRow.textContent = '— All —';
                drop.appendChild(allRow);

                var hits = 0;
                pool.forEach(function(opt) {
                    var label = opt.label;
                    if (term && label.toLowerCase().indexOf(term) === -1) return;
                    var row = document.createElement('div');
                    row.className = 'cb-opt' + (opt.value == chosen ? ' cb-chosen' : '');
                    row.dataset.v = opt.value;
                    if (term) {
                        var lo = label.toLowerCase(),
                            si = lo.indexOf(term);
                        row.innerHTML = esc(label.slice(0, si)) +
                            '<mark>' + esc(label.slice(si, si + term.length)) + '</mark>' +
                            esc(label.slice(si + term.length));
                    } else {
                        row.textContent = label;
                    }
                    drop.appendChild(row);
                    hits++;
                });

                if (hits === 0 && term) {
                    var empty = document.createElement('div');
                    empty.className = 'cb-empty';
                    empty.textContent = 'No matches for "' + filterTerm + '"';
                    drop.appendChild(empty);
                }
            }

            function visibleOpts() {
                return Array.prototype.slice.call(drop.querySelectorAll('.cb-opt[data-v]'));
            }

            function moveCursor(dir) {
                var opts = visibleOpts();
                if (!opts.length) return;
                opts.forEach(o => o.classList.remove('cb-cursor'));
                cursor = Math.max(0, Math.min(opts.length - 1, cursor + dir));
                opts[cursor].classList.add('cb-cursor');
                opts[cursor].scrollIntoView({
                    block: 'nearest'
                });
            }

            // Events
            inputEl.addEventListener('focus', function() {
                if (!isOpen()) openDrop('');
            });
            inputEl.addEventListener('input', function() {
                if (!isOpen()) wrap.classList.add('cb-open');
                renderDrop(inputEl.value);
                if (previewFn) previewFn.call(this, {
                    target: this
                });
            });
            inputEl.addEventListener('keydown', function(e) {
                if (!isOpen() && (e.key === 'ArrowDown' || e.key === 'Enter')) {
                    e.preventDefault();
                    openDrop('');
                    return;
                }
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveCursor(+1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    moveCursor(-1);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var opts = visibleOpts();
                    if (cursor >= 0 && opts[cursor]) {
                        commit(opts[cursor].dataset.v);
                    } else {
                        var real = opts.filter(o => o.dataset.v !== '');
                        if (real.length === 1) commit(real[0].dataset.v);
                        else closeDrop(true);
                    }
                } else if (e.key === 'Escape') {
                    closeDrop(true);
                    inputEl.blur();
                } else if (e.key === 'Tab') {
                    closeDrop(true);
                }
            });
            inputEl.addEventListener('blur', function() {
                setTimeout(function() {
                    if (isOpen()) closeDrop(true);
                }, 180);
            });

            drop.addEventListener('mousedown', function(e) {
                var opt = e.target.closest('.cb-opt[data-v]');
                if (opt) {
                    e.preventDefault();
                    commit(opt.dataset.v);
                }
            });

            btnArrow.addEventListener('mousedown', function(e) {
                e.preventDefault();
                if (isOpen()) {
                    closeDrop(true);
                    inputEl.blur();
                } else inputEl.focus();
            });

            btnClear.addEventListener('mousedown', function(e) {
                e.preventDefault();
                commit('');
                inputEl.focus();
            });

            return {
                el: wrap,
                getValue: function() {
                    return chosen;
                },
                setValue: commit,
                setOptions: function(newOptions) {
                    pool = newOptions.map(function(s) {
                        return {
                            value: s,
                            label: s
                        };
                    });
                    if (chosen && !pool.some(o => o.value == chosen)) commit('');
                    else if (isOpen()) renderDrop(inputEl.value);
                }
            };
        }
    </script>
    <script>
        // Data from PHP
        var ALL_TESTING = <?= $allTestingJson ?>;
        var ALL_SUFFIXES = <?= $suffixesJson ?>;

        // Existing project names for create form
        var existingProjectNames = <?php
                                    $names = [];
                                    $existingPN->data_seek(0);
                                    while ($e = $existingPN->fetch_assoc()) $names[] = $e['project_name'];
                                    echo json_encode($names);
                                    ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // ---------- Create form: existing project name combobox ----------
            var createOpts = existingProjectNames.map(function(name) {
                return {
                    value: name,
                    label: name
                };
            });
            var createCombo = makeSearchableDropdown(
                createOpts,
                '', // no initial selection
                null,
                'existing_project_name'
            );
            document.getElementById('existingProjectNameComboContainer').appendChild(createCombo.el);

            // ---------- Load form: cascading comboboxes ----------
            var projectNames = [];
            ALL_TESTING.forEach(function(r) {
                if (projectNames.indexOf(r.project_name) === -1) projectNames.push(r.project_name);
            });
            projectNames.sort();
            var projectNameOpts = projectNames.map(function(n) {
                return {
                    value: n,
                    label: n
                };
            });

            var projectCombo = makeSearchableDropdown(projectNameOpts, '', function(selectedProject) {
                updateTestingOptions(selectedProject);
            }, null);
            document.getElementById('projectNameComboContainer').appendChild(projectCombo.el);

            var testingCombo = makeSearchableDropdown([], '', function(selectedId) {
                document.getElementById('btnLoad').disabled = !selectedId;
            }, 'edit');
            document.getElementById('testingRecordComboContainer').appendChild(testingCombo.el);

            function updateTestingOptions(projectName) {
                var filtered = projectName ? ALL_TESTING.filter(function(r) {
                    return r.project_name === projectName;
                }) : ALL_TESTING;
                var newOpts = filtered.map(function(r) {
                    var label = r.project_title + ' / ' + r.testing_name + ' / ' + r.testing_method;
                    return {
                        value: r.testing_id.toString(),
                        label: label
                    };
                });
                testingCombo.setOptions(newOpts);
                testingCombo.setValue('');
            }

            <?php if ($editTid && $editData): ?>
                var targetId = <?= $editTid ?>;
                var targetRow = ALL_TESTING.find(function(r) {
                    return r.testing_id == targetId;
                });
                if (targetRow) {
                    projectCombo.setValue(targetRow.project_name);
                    updateTestingOptions(targetRow.project_name);
                    testingCombo.setValue(targetId.toString());
                    document.getElementById('btnLoad').disabled = false;
                    // Scroll to edit section
                    var editSection = document.querySelector('.card h2 .fa-info-circle');
                    if (editSection) {
                        editSection.closest('.card').scrollIntoView({behavior:'smooth', block:'start'});
                    }
                }
            <?php endif; ?>

            // ---------- Project Title suffix combobox for create form ----------
            var createSuffix = document.getElementById('createPtSuffix');
            if (createSuffix) {
                enhanceSuffixInput(createSuffix, ALL_SUFFIXES, function(val) {
                    // update preview already handled by original oninput which we preserved
                });
            }

            // ---------- Project Title suffix combobox for edit form ----------
            var editSuffix = document.getElementById('editPtSuffix');
            if (editSuffix) {
                enhanceSuffixInput(editSuffix, ALL_SUFFIXES, function(val) {
                    // no additional action needed
                });
            }
        });

        // Close comboboxes when clicking elsewhere
        document.addEventListener('mousedown', function(e) {
            document.querySelectorAll('.cb-wrap.cb-open').forEach(function(w) {
                if (!w.contains(e.target)) w.classList.remove('cb-open');
            });
        });
    </script>
    <script>
        VanillaTilt.init(document.querySelectorAll('.stat-card'), {
            max: 8,
            speed: 400,
            glare: true,
            'max-glare': 0.08,
            scale: 1.02
        });

        // ── Stat counter animation ────────────────────────────────
        document.querySelectorAll('.stat-value[data-count]').forEach(function(el) {
            var target = parseInt(el.dataset.count, 10);
            var start = 0;
            var dur = 800;
            var step = Math.ceil(target / (dur / 16));
            var timer = setInterval(function() {
                start = Math.min(start + step, target);
                el.textContent = start;
                if (start >= target) clearInterval(timer);
            }, 16);
        });

        // ── Cursor glow ───────────────────────────────────────────
        var glow = document.createElement('div');
        glow.className = 'cursor-glow';
        document.body.appendChild(glow);
        document.addEventListener('mousemove', function(e) {
            glow.style.left = e.clientX + 'px';
            glow.style.top = e.clientY + 'px';
        });

        // ── Project Title live preview (create form) ──────────────
        function updateCreatePreview() {
            var suffix = document.getElementById('createPtSuffix').value;
            var prefix = document.getElementById('createPtPrefix').textContent;
            var preview = document.getElementById('createPtPreview');
            preview.innerHTML = 'Full title: <strong>' + prefix + '</strong>' + (suffix ? escHtml(suffix) : '<span style="color:#CBD5E0">your title</span>');
        }

        function escHtml(s) {
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // ── Grouped media upload ──────────────────────────────────
        var existingGroupNames = <?= json_encode($existingGroupNames) ?>;
        var mediaGroups = []; // [{id, name, dataTransfer}]
        var groupCounter = 0;

        function addMediaGroup() {
            var gid = ++groupCounter;
            var group = {
                id: gid,
                name: '',
                dt: new DataTransfer()
            };
            mediaGroups.push(group);
            renderGroups();
            // Focus the new group's name input
            setTimeout(function() {
                var inp = document.getElementById('gname-' + gid);
                if (inp) inp.focus();
            }, 50);
        }

        function removeMediaGroup(gid) {
            mediaGroups = mediaGroups.filter(function(g) {
                return g.id !== gid;
            });
            renderGroups();
            updateUploadBtn();
        }

        function renderGroups() {
            var container = document.getElementById('pendingGroupsContainer');
            container.innerHTML = '';
            mediaGroups.forEach(function(group) {
                var card = document.createElement('div');
                card.className = 'pending-group-card';
                card.id = 'group-card-' + group.id;

                // ── Header ──
                var header = document.createElement('div');
                header.className = 'pending-group-header';

                // Combobox wrap
                var cbWrap = document.createElement('div');
                cbWrap.className = 'group-name-combobox-wrap';
                cbWrap.style.position = 'relative';

                var inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'group-name-input';
                inp.id = 'gname-' + group.id;
                inp.placeholder = 'Group name (type or select)…';
                inp.value = group.name;
                inp.autocomplete = 'off';
                inp.addEventListener('input', (function(g) {
                    return function() {
                        g.name = this.value;
                        openDropdown(g.id);
                        updateUploadBtn();
                        updatePasteHint();
                    };
                })(group));
                inp.addEventListener('focus', (function(g) {
                    return function() {
                        openDropdown(g.id);
                    };
                })(group));
                inp.addEventListener('keydown', (function(g) {
                    return function(e) {
                        handleComboKeydown(e, g.id);
                    };
                })(group));

                var arrow = document.createElement('i');
                arrow.className = 'fas fa-chevron-down group-name-dropdown-arrow';

                var dropdown = document.createElement('div');
                dropdown.className = 'group-name-dropdown';
                dropdown.id = 'gdrop-' + group.id;
                renderDropdownOptions(dropdown, group.id, group.name);

                cbWrap.appendChild(inp);
                cbWrap.appendChild(arrow);
                cbWrap.appendChild(dropdown);
                header.appendChild(cbWrap);

                // Remove group button
                var rmBtn = document.createElement('button');
                rmBtn.type = 'button';
                rmBtn.className = 'btn-remove-group';
                rmBtn.title = 'Remove group';
                rmBtn.innerHTML = '<i class="fas fa-times"></i>';
                rmBtn.onclick = (function(gid) {
                    return function() {
                        removeMediaGroup(gid);
                    };
                })(group.id);
                header.appendChild(rmBtn);
                card.appendChild(header);

                // ── Body ──
                var body = document.createElement('div');
                body.className = 'pending-group-body';

                // File list
                var fileList = document.createElement('div');
                fileList.className = 'pending-files';
                fileList.id = 'gfiles-' + group.id;
                renderGroupFileList(fileList, group);
                body.appendChild(fileList);

                // Add files button
                var addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'btn-add-files-to-group';
                addBtn.innerHTML = '<i class="fas fa-paperclip"></i> Add Files';
                addBtn.onclick = (function(g) {
                    return function() {
                        triggerFilePicker(g.id);
                    };
                })(group);
                body.appendChild(addBtn);

                // Hidden file input
                var fi = document.createElement('input');
                fi.type = 'file';
                fi.style.display = 'none';
                fi.id = 'finput-' + group.id;
                fi.multiple = true;
                fi.addEventListener('change', (function(g) {
                    return function() {
                        onGroupFilesSelected(g.id, this.files);
                    };
                })(group));
                body.appendChild(fi);

                card.appendChild(body);
                container.appendChild(card);
            });

            // Close dropdowns when clicking outside
            document.removeEventListener('mousedown', closeAllDropdowns);
            document.addEventListener('mousedown', closeAllDropdowns);

            // Keep paste hint text in sync with current last group
            updatePasteHint();
        }

        function renderDropdownOptions(dropdown, gid, query) {
            dropdown.innerHTML = '';
            var q = query.trim().toLowerCase();
            var filtered = existingGroupNames.filter(function(n) {
                return n.toLowerCase().includes(q);
            });
            if (filtered.length === 0 && q === '') {
                var empty = document.createElement('div');
                empty.className = 'group-name-option-empty';
                empty.textContent = 'No saved group names yet. Type a new name.';
                dropdown.appendChild(empty);
            } else {
                filtered.forEach(function(name, idx) {
                    var opt = document.createElement('div');
                    opt.className = 'group-name-option';
                    opt.dataset.idx = idx;
                    opt.textContent = name;
                    opt.addEventListener('mousedown', (function(n, gid) {
                        return function(e) {
                            e.preventDefault();
                            var g = mediaGroups.find(function(x) {
                                return x.id === gid;
                            });
                            if (g) {
                                g.name = n;
                                document.getElementById('gname-' + gid).value = n;
                            }
                            closeDropdown(gid);
                            updateUploadBtn();
                            updatePasteHint();
                        };
                    })(name, gid));
                    dropdown.appendChild(opt);
                });
            }
            // "Use custom" option when query is non-empty and not exactly matching an existing name
            if (q !== '' && !existingGroupNames.some(function(n) {
                    return n.toLowerCase() === q;
                })) {
                var custom = document.createElement('div');
                custom.className = 'group-name-option-new';
                custom.innerHTML = '<i class="fas fa-plus" style="margin-right:5px;font-size:11px;"></i>Use "' + escHtml(query.trim()) + '"';
                custom.addEventListener('mousedown', (function(name, gid) {
                    return function(e) {
                        e.preventDefault();
                        var g = mediaGroups.find(function(x) {
                            return x.id === gid;
                        });
                        if (g) {
                            g.name = name;
                            document.getElementById('gname-' + gid).value = name;
                        }
                        closeDropdown(gid);
                        updateUploadBtn();
                        updatePasteHint();
                    };
                })(query.trim(), gid));
                dropdown.appendChild(custom);
            }
        }

        function openDropdown(gid) {
            var inp = document.getElementById('gname-' + gid);
            var dd = document.getElementById('gdrop-' + gid);
            if (!dd || !inp) return;
            renderDropdownOptions(dd, gid, inp.value);
            dd.classList.add('open');
        }

        function closeDropdown(gid) {
            var dd = document.getElementById('gdrop-' + gid);
            if (dd) dd.classList.remove('open');
        }

        function closeAllDropdowns(e) {
            mediaGroups.forEach(function(g) {
                var wrap = document.querySelector('.group-name-combobox-wrap:has(#gname-' + g.id + ')') ||
                    (document.getElementById('gname-' + g.id) && document.getElementById('gname-' + g.id).closest('.group-name-combobox-wrap'));
                if (wrap && !wrap.contains(e.target)) closeDropdown(g.id);
            });
        }

        function handleComboKeydown(e, gid) {
            var dd = document.getElementById('gdrop-' + gid);
            if (!dd) return;
            var opts = dd.querySelectorAll('.group-name-option, .group-name-option-new');
            var hi = dd.querySelector('.highlighted');
            var hiIdx = hi ? Array.from(opts).indexOf(hi) : -1;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var next = Math.min(hiIdx + 1, opts.length - 1);
                opts.forEach(function(o) {
                    o.classList.remove('highlighted');
                });
                if (opts[next]) opts[next].classList.add('highlighted');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prev = Math.max(hiIdx - 1, 0);
                opts.forEach(function(o) {
                    o.classList.remove('highlighted');
                });
                if (opts[prev]) opts[prev].classList.add('highlighted');
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (hi) {
                    hi.dispatchEvent(new MouseEvent('mousedown'));
                } else {
                    closeDropdown(gid);
                }
            } else if (e.key === 'Escape') {
                closeDropdown(gid);
            }
        }

        function triggerFilePicker(gid) {
            var fi = document.getElementById('finput-' + gid);
            if (fi) fi.click();
        }

        function onGroupFilesSelected(gid, files) {
            var g = mediaGroups.find(function(x) {
                return x.id === gid;
            });
            if (!g) return;
            for (var i = 0; i < files.length; i++) g.dt.items.add(files[i]);
            var fl = document.getElementById('gfiles-' + gid);
            if (fl) renderGroupFileList(fl, g);
            updateUploadBtn();
        }

        function removeGroupFile(gid, fileIdx) {
            var g = mediaGroups.find(function(x) {
                return x.id === gid;
            });
            if (!g) return;
            var newDT = new DataTransfer();
            for (var i = 0; i < g.dt.files.length; i++) {
                if (i !== fileIdx) newDT.items.add(g.dt.files[i]);
            }
            g.dt = newDT;
            var fl = document.getElementById('gfiles-' + gid);
            if (fl) renderGroupFileList(fl, g);
            updateUploadBtn();
        }

        function renderGroupFileList(container, group) {
            container.innerHTML = '';
            var files = group.dt.files;
            if (files.length === 0) {
                var hint = document.createElement('p');
                hint.style.cssText = 'font-size:12.5px;color:#9AADBD;margin:0 0 4px;';
                hint.textContent = 'No files added yet.';
                container.appendChild(hint);
                return;
            }
            for (var i = 0; i < files.length; i++) {
                (function(idx, file) {
                    var item = document.createElement('div');
                    item.className = 'pending-file-item';
                    var span = document.createElement('span');
                    span.textContent = file.name + ' (' + (file.size > 1048576 ? (file.size / 1048576).toFixed(1) + 'MB' : Math.round(file.size / 1024) + 'KB') + ')';
                    var rmBtn = document.createElement('button');
                    rmBtn.type = 'button';
                    rmBtn.className = 'pending-file-remove';
                    rmBtn.innerHTML = '<i class="fas fa-times"></i>';
                    rmBtn.title = 'Remove';
                    rmBtn.onclick = (function(gid, fi) {
                        return function() {
                            removeGroupFile(gid, fi);
                        };
                    })(group.id, idx);
                    item.appendChild(span);
                    item.appendChild(rmBtn);
                    container.appendChild(item);
                })(i, files[i]);
            }
        }

        function updateUploadBtn() {
            var hasFiles = mediaGroups.some(function(g) {
                return g.dt.files.length > 0;
            });
            var btn = document.getElementById('btnUpload');
            if (btn) btn.disabled = !hasFiles;
        }

        function getTotalPendingFiles() {
            return mediaGroups.reduce(function(sum, g) {
                return sum + g.dt.files.length;
            }, 0);
        }

        function escHtml(s) {
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function submitGroupedUpload() {
            if (getTotalPendingFiles() === 0) return;
            var total = getTotalPendingFiles();
            var gCount = mediaGroups.filter(function(g) {
                return g.dt.files.length > 0;
            }).length;
            showModal('confirm', 'Upload Files',
                'Upload ' + total + ' file(s) across ' + gCount + ' group(s)?',
                function() {
                    doGroupedUploadSubmit();
                }
            );
        }

        function doGroupedUploadSubmit() {
            var form = document.getElementById('fUpM');

            // Remove any previously injected dynamic inputs
            form.querySelectorAll('input[data-dynamic]').forEach(function(el) {
                el.remove();
            });

            // Inject group name hidden inputs so PHP can read media_group_names[gi]
            // Files are already held in each group's DataTransfer — we need a real file input per group
            // Strategy: create one <input type="file"> per group, assign its files, append to form, then submit
            var hasFilled = false;
            mediaGroups.forEach(function(g, gi) {
                if (g.dt.files.length === 0) return;
                hasFilled = true;

                // Group name hidden input
                var nameInput = document.createElement('input');
                nameInput.type = 'hidden';
                nameInput.name = 'media_group_names[' + gi + ']';
                nameInput.value = g.name.trim();
                nameInput.setAttribute('data-dynamic', '1');
                form.appendChild(nameInput);

                // File input for this group — assign the DataTransfer files
                var fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.name = 'media_files[' + gi + '][]';
                fileInput.multiple = true;
                fileInput.style.display = 'none';
                fileInput.setAttribute('data-dynamic', '1');
                fileInput.files = g.dt.files;
                form.appendChild(fileInput);
            });

            if (!hasFilled) return;

            var btn = document.getElementById('btnUpload');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';

            // Standard form submit — page reloads, modal.php fires confetti on msg-success
            form.submit();
        }

        // ── Group rename helpers (saved groups) ──────────────────
        function onGroupRenameInput(inputId, saveBtnId, originalName) {
            var inp = document.getElementById(inputId);
            var btn = document.getElementById(saveBtnId);
            if (!inp || !btn) return;
            var changed = inp.value.trim() !== originalName &&
                !(originalName === '__ungrouped__' && inp.value.trim() === 'Ungrouped');
            if (changed) {
                btn.classList.add('visible');
            } else {
                btn.classList.remove('visible');
            }
        }

        function saveGroupRename(formId, inputId, saveBtnId, originalName) {
            var inp = document.getElementById(inputId);
            var newName = inp ? inp.value.trim() : '';
            if (newName === '' || newName === originalName ||
                (originalName === '__ungrouped__' && newName === 'Ungrouped')) {
                resetGroupRename(inputId, saveBtnId, originalName === '__ungrouped__' ? 'Ungrouped' : originalName);
                return;
            }
            var hiddenId = inputId + '-hidden';
            var hidden = document.getElementById(hiddenId);
            if (hidden) hidden.value = newName;
            var form = document.getElementById(formId);
            if (form) {
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
        }

        function resetGroupRename(inputId, saveBtnId, originalDisplay) {
            var inp = document.getElementById(inputId);
            var btn = document.getElementById(saveBtnId);
            if (inp) inp.value = originalDisplay;
            if (btn) btn.classList.remove('visible');
        }

        // ── Save All: include grouped media ──────────────────────
        function appendGroupedMediaToFormData(fd) {
            mediaGroups.forEach(function(g, gi) {
                if (g.dt.files.length === 0) return;
                fd.append('media_group_names[' + gi + ']', g.name.trim());
                for (var i = 0; i < g.dt.files.length; i++) {
                    fd.append('media_files[' + gi + '][]', g.dt.files[i]);
                }
            });
        }

        // ── Clipboard paste support ───────────────────────────────
        function updatePasteHint() {
            var hint = document.getElementById('pasteHint');
            var hintText = document.getElementById('pasteHintText');
            if (!hint || !hintText) return;
            if (mediaGroups.length === 0) {
                hintText.innerHTML = 'Press <kbd>Ctrl</kbd>+<kbd>V</kbd> (or <kbd>\u2318V</kbd>) anywhere on the page to paste a copied image \u2014 add a group first.';
            } else {
                var lastName = mediaGroups[mediaGroups.length - 1].name.trim();
                var target = lastName ? '\u201c' + lastName + '\u201d' : 'the last group';
                hintText.innerHTML = 'Press <kbd>Ctrl</kbd>+<kbd>V</kbd> (or <kbd>\u2318V</kbd>) anywhere on the page to paste a copied image into ' + target + '.';
            }
        }

        document.addEventListener('paste', function(e) {
            // Only act when we're on the edit page (media section present)
            if (!document.getElementById('pendingGroupsContainer')) return;

            // Ignore paste events that originate from text inputs / textareas
            var tag = (e.target && e.target.tagName) ? e.target.tagName.toUpperCase() : '';
            if (tag === 'TEXTAREA' || (tag === 'INPUT' && e.target.type !== 'file')) return;

            var items = (e.clipboardData || e.originalEvent && e.originalEvent.clipboardData || {}).items;
            if (!items) return;

            // Collect all file items from clipboard
            var pastedFiles = [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    var f = items[i].getAsFile();
                    if (f) pastedFiles.push(f);
                }
            }
            if (pastedFiles.length === 0) return;

            e.preventDefault();

            var hint = document.getElementById('pasteHint');
            var hintText = document.getElementById('pasteHintText');

            // No groups exist — show warning toast and bail
            if (mediaGroups.length === 0) {
                showPasteNoGroupToast();
                return;
            }

            // Add files to the last group
            var targetGroup = mediaGroups[mediaGroups.length - 1];
            pastedFiles.forEach(function(f) {
                targetGroup.dt.items.add(f);
            });

            // Re-render only that group's file list (avoid full re-render which resets focus)
            var fl = document.getElementById('gfiles-' + targetGroup.id);
            if (fl) renderGroupFileList(fl, targetGroup);
            updateUploadBtn();

            // Flash the hint green briefly
            if (hint) {
                hint.classList.add('paste-active');
                var label = pastedFiles.length === 1 ?
                    'Pasted \u201c' + pastedFiles[0].name + '\u201d into group \u201c' + (targetGroup.name.trim() || 'Unnamed') + '\u201d' :
                    'Pasted ' + pastedFiles.length + ' file(s) into group \u201c' + (targetGroup.name.trim() || 'Unnamed') + '\u201d';
                hintText.textContent = label;
                setTimeout(function() {
                    hint.classList.remove('paste-active');
                    hintText.innerHTML = 'Press <kbd>Ctrl</kbd>+<kbd>V</kbd> (or <kbd>\u2318V</kbd>) anywhere on the page to paste a copied image into the last group.';
                }, 2500);
            }

            // Scroll the target group card into view
            var card = document.getElementById('group-card-' + targetGroup.id);
            if (card) card.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        });

        function showPasteNoGroupToast() {
            // Remove any existing toast first
            var existing = document.getElementById('pasteNoGroupToast');
            if (existing) existing.remove();

            var toast = document.createElement('div');
            toast.className = 'paste-no-group-toast';
            toast.id = 'pasteNoGroupToast';
            toast.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Add a group first, then paste your image into it.';

            var hint = document.getElementById('pasteHint');
            if (hint) {
                hint.parentNode.insertBefore(toast, hint.nextSibling);
            } else {
                var container = document.getElementById('pendingGroupsContainer');
                if (container) container.parentNode.appendChild(toast);
            }

            // Auto-dismiss after 3 seconds
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.style.transition = 'opacity 0.3s';
                    toast.style.opacity = '0';
                    setTimeout(function() {
                        if (toast.parentNode) toast.parentNode.removeChild(toast);
                    }, 300);
                }
            }, 3000);
        }

        // ── Batch description queue ───────────────────────────────
        var descQueue = [];

        function addDescToQueue() {
            var ta = document.getElementById('descDraftInput');
            var val = ta.value.trim();
            if (!val) return;
            descQueue.push(val);
            ta.value = '';
            renderDescQueue();
        }

        function renderDescQueue() {
            var list = document.getElementById('pendingDescsList');
            var btn = document.getElementById('btnAddDescs');
            list.innerHTML = '';
            btn.disabled = descQueue.length === 0;
            descQueue.forEach(function(text, idx) {
                var item = document.createElement('div');
                item.className = 'pending-desc-item';
                var pre = document.createElement('pre');
                pre.textContent = text;
                var rmBtn = document.createElement('button');
                rmBtn.type = 'button';
                rmBtn.className = 'pending-desc-remove';
                rmBtn.innerHTML = '<i class="fas fa-times"></i>';
                rmBtn.title = 'Remove';
                rmBtn.onclick = (function(i) {
                    return function() {
                        descQueue.splice(i, 1);
                        renderDescQueue();
                    };
                })(idx);
                item.appendChild(pre);
                item.appendChild(rmBtn);
                list.appendChild(item);
            });
        }

        function finaliseDescs() {
            if (descQueue.length === 0) return;
            var form = document.getElementById('fAD');
            form.querySelectorAll('input[name^="description_items"]').forEach(function(el) {
                el.remove();
            });
            descQueue.forEach(function(text, i) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'description_items[' + i + ']';
                inp.value = text;
                form.appendChild(inp);
            });
            confirmSubmit('fAD', 'confirm', 'Save Descriptions', 'Add ' + descQueue.length + ' description(s)?');
        }

        (function() {
            var proxy = document.createElement('form');
            proxy.id = 'fSaveAllProxy';
            proxy.method = 'POST';
            proxy.style.display = 'none';
            document.body.appendChild(proxy);
            proxy.addEventListener('submit', function(e) {
                e.preventDefault();
                runSaveAllFetch();
            });
        }());

        function saveAll() {
            confirmSubmit(
                'fSaveAllProxy',
                'confirm',
                'Save Everything',
                'Save Field Configuration, Record Data, pending Media groups, and queued Descriptions in one action?'
            );
        }

        function runSaveAllFetch() {
            var btn = document.getElementById('btnSaveAll');
            btn.classList.add('saving');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

            var fd = new FormData();
            fd.append('action', 'save_all');
            fd.append('testing_id', '<?= $editTid ?>');

            var fCF = document.getElementById('fCF');
            if (fCF) {
                fCF.querySelectorAll('input[name="selected_fields[]"]').forEach(function(cb) {
                    if (cb.checked) fd.append('selected_fields[]', cb.value);
                });
            }

            var fSR = document.getElementById('fSR');
            if (fSR) {
                fd.append('num_rows', document.getElementById('numRows').value);
                fSR.querySelectorAll('textarea[name^="records"]').forEach(function(ta) {
                    fd.append(ta.name, ta.value);
                });
            }

            if (typeof appendGroupedMediaToFormData === 'function') {
                appendGroupedMediaToFormData(fd);
            }

            if (typeof descQueue !== 'undefined') {
                descQueue.forEach(function(text, i) {
                    fd.append('description_items[' + i + ']', text);
                });
            }

            fetch('editor.php', {
                    method: 'POST',
                    body: fd
                })
                .then(function(res) {
                    return res.text();
                })
                .then(function(html) {
                    var match = html.match(/<div class="msg-(\w+)">([^<]*)<\/div>/);
                    var mType = match ? match[1] : 'success';
                    var mText = match ? match[2] : 'All sections saved successfully.';

                    var existing = document.querySelector('.msg-success, .msg-warning, .msg-error');
                    if (existing) existing.remove();
                    var banner = document.createElement('div');
                    banner.className = 'msg-' + mType;
                    banner.innerHTML = '<i class="fas fa-' +
                        (mType === 'success' ? 'circle-check' : 'triangle-exclamation') +
                        '" style="margin-right:8px;"></i>' + mText;
                    banner.style.opacity = '0';
                    banner.style.transform = 'translateY(-10px)';
                    banner.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    var mainBody = document.querySelector('.main-body');
                    mainBody.insertBefore(banner, mainBody.firstChild);
                    requestAnimationFrame(function() {
                        banner.style.opacity = '1';
                        banner.style.transform = 'translateY(0)';
                    });
                    banner.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });

                    if (mType === 'success' && typeof confetti === 'function') {
                        confetti({
                            particleCount: 120,
                            spread: 80,
                            origin: {
                                y: 0.3
                            },
                            colors: ['#6B8DB5', '#68A87A', '#C1A0D8', '#FFD9A0', '#FADADD']
                        });
                        setTimeout(function() {
                            confetti({
                                particleCount: 60,
                                spread: 100,
                                origin: {
                                    y: 0.4
                                }
                            });
                        }, 300);
                    }

                    btn.classList.remove('saving');
                    btn.innerHTML = '<i class="fas fa-circle-check"></i> Saved!';
                    btn.style.background = 'linear-gradient(135deg,#5BA87A,#7CC49A)';
                    btn.style.boxShadow = '0 4px 18px rgba(91,168,122,0.35)';

                    setTimeout(function() {
                        window.location.href = 'editor.php?edit=<?= $editTid ?>';
                    }, 2800);
                })
                .catch(function(err) {
                    btn.classList.remove('saving');
                    btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save All';
                    btn.style.background = '';
                    btn.style.boxShadow = '';
                    alert('Save failed: ' + err);
                });
        }
    </script>
    <script>
        // ── AJAX Engineer Check ───────────────────────────────────
        function ajaxEngineerCheck(tableNum, checked) {
            var tid = <?= $editTid ?? 0 ?>;
            var checkRowId = 'engCheckRowT' + tableNum;
            var labelId = 'engLabelT' + tableNum;
            var row = document.getElementById(checkRowId);
            var label = document.getElementById(labelId);
            if (row) {
                row.className = 'engineer-check-row' + (checked ? '' : ' unchecked');
            }
            if (label) {
                label.textContent = checked ? 'Verified ✓' : 'Not yet verified';
            }
            var fd = new FormData();
            fd.append('testing_id', tid);
            fd.append('table_number', tableNum);
            fd.append('checked', checked ? 1 : 0);
            fetch('ajax_engineer_check.php', {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    if (data.success && typeof confetti === 'function') {
                        confetti({particleCount:80,spread:60,origin:{y:0.4},colors:['#6B8DB5','#68A87A','#C1A0D8','#FFD9A0']});
                    }
                })
                .catch(function(e){ console.error('Engineer check failed:', e); });
        }

        // ── File Rename Helpers ───────────────────────────────────
        function onFileRenameInput(mediaId) {
            var inp = document.getElementById('fname-' + mediaId);
            var btn = document.getElementById('fname-save-' + mediaId);
            if (!inp || !btn) return;
            btn.classList.add('visible');
        }

        function saveFileRename(mediaId) {
            var inp = document.getElementById('fname-' + mediaId);
            var newName = inp ? inp.value.trim() : '';
            if (!newName) return;
            var hidden = document.getElementById('ffr-name-' + mediaId);
            if (hidden) hidden.value = newName;
            var form = document.getElementById('fFR' + mediaId);
            if (form) {
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
        }

        function resetFileRename(mediaId, originalName) {
            var inp = document.getElementById('fname-' + mediaId);
            var btn = document.getElementById('fname-save-' + mediaId);
            if (inp) inp.value = originalName;
            if (btn) btn.classList.remove('visible');
        }
    </script>

</body>

</html>
<?php $conn->close(); ?>