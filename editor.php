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

            if (empty($msgs)) $msgs[] = "Nothing to save.";
            $msg     = implode(' | ', $msgs);
            $msgType = $hasError ? 'warning' : 'success';
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
        // When Editor B first opens (Table 2 is empty), pre-populate with Table 1 data
        if (!$isOwner && empty($editRows) && !empty($editRowsOther)) {
            $editRows = $editRowsOther;
        }
        // Get edited_by for both tables
        $ebRes1 = $conn->query("SELECT edited_by FROM testing_record WHERE testing_id=$editTid AND table_number=1 LIMIT 1");
        $ebRow1 = $ebRes1 ? $ebRes1->fetch_assoc() : null;
        $ebRes2 = $conn->query("SELECT edited_by FROM testing_record WHERE testing_id=$editTid AND table_number=2 LIMIT 1");
        $ebRow2 = $ebRes2 ? $ebRes2->fetch_assoc() : null;
        $editedByT1 = $ebRow1['edited_by'] ?? null;
        $editedByT2 = $ebRow2['edited_by'] ?? null;
    }
}
$tTests  = $conn->query("SELECT COUNT(*) AS c FROM testing")->fetch_assoc()['c'];
$tFields = $conn->query("SELECT COUNT(*) AS c FROM master_fields")->fetch_assoc()['c'];

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
    <?php include 'head.php'; ?>
    <style>
        /* Page-specific overrides: Cards */
            overflow: visible;

        /* Page-specific overrides: Buttons */
        .btn:disabled {
            opacity: 0.45;
            pointer-events: none;
            transform: none;

        /* Page-specific overrides: Forms */
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

        /* Page-specific overrides: Table */
        /* Auto-expanding textarea cells */
        tbody td textarea.cell-input {
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
        tbody td textarea.cell-input:focus {
            border-color: rgba(107, 141, 181, 0.4);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.09);
            background: rgba(255, 255, 255, 0.95);
            outline: none;
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

        .pending-desc-edit {
            flex: 1;
            margin: 0;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            color: #4A5568;
            background: rgba(255, 255, 255, 0.6);
            border: 1.5px solid rgba(107, 141, 181, 0.15);
            border-radius: 8px;
            padding: 8px 10px;
            resize: vertical;
            outline: none;
            transition: border-color 0.25s, box-shadow 0.25s;
            width: 100%;
            min-width: 0;
        }

        .pending-desc-edit:focus {
            border-color: rgba(107, 141, 181, 0.4);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.09);
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

        /* Page-specific overrides: Messages */
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
            z-index: 9999;
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

    </style>
    <title>Editor — Subjective Portal</title>
</head>

<body>
    <?php include 'orbs.php'; ?>

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
                        <h2><i class="fas fa-info-circle"></i> Edit Meta — Testing <?= htmlspecialchars($editData['testing_name']) ?></h2>

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
                        <h2><i class="fas fa-sliders"></i> Configure Fields — Testing <?= htmlspecialchars($editData['testing_name']) ?></h2>
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
                        <h2><i class="fas fa-table"></i> Record Data — Testing <?= htmlspecialchars($editData['testing_name']) ?></h2>
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
                                <a href="editor_media_descriptions.php?edit=<?= $editTid ?>" class="btn btn-primary" style="margin-left:4px;">
                                    <i class="fas fa-arrow-right"></i> Next
                                </a>
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
                                    <?php foreach ($tf as $fd2): ?><th><?= htmlspecialchars($fd2['field_name']) ?></th><?php endforeach; ?>
                                    <th style="text-align:center;"><i class="fas fa-calculator"></i> Avg</th>
                                    <?php foreach ($nf as $fd2): ?><th><?= htmlspecialchars($fd2['field_name']) ?></th><?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                <?php if (empty($editRowsOther)): ?>
                                    <tr><td colspan="<?= count($editFields) + 2 ?>" style="text-align:center;color:#A0AEC0;padding:16px;">No data</td></tr>
                                <?php else:
                                    $ri2 = 0;
                                    foreach ($editRowsOther as $rn2 => $rd2):
                                        $ri2++;
                                        $nv2 = [];
                                        foreach ($nf as $fd2) {
                                            $v2 = $rd2[(int)$fd2['field_key']] ?? '';
                                            if ($v2 !== '' && is_numeric($v2) && (float)$v2 >= 0) $nv2[] = (float)$v2;
                                        }
                                        $avg2 = count($nv2) > 0 ? array_sum($nv2) / count($nv2) : null;
                                        $cls2 = getAvgClass($avg2);
                                ?>
                                    <tr>
                                        <td style="text-align:center;color:#A0AEC0;font-weight:700;"><?= $ri2 ?></td>
                                        <?php foreach ($tf as $fd2):
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
                                        <?php foreach ($nf as $fd2):
                                            $fk2 = (int)$fd2['field_key'];
                                            $v2 = htmlspecialchars($rd2[$fk2] ?? '');
                                        ?>
                                            <td style="color:#4A5568;"><?= $v2 !== '' ? $v2 : '—' ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── NEXT: Media & Descriptions ──────────────── -->
                    <div class="save-all-card">
                        <div class="save-all-info">
                            <h3><i class="fas fa-arrow-right"></i> Continue to Media &amp; Descriptions</h3>
                            <p>Add media files and descriptions for Testing #<?= $editTid ?> on the next page.</p>
                        </div>
                        <a href="editor_media_descriptions.php?edit=<?= $editTid ?>" class="btn-save-all" style="text-decoration:none;text-align:center;">
                            <i class="fas fa-arrow-right"></i> Next
                        </a>
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
    </script>

</body>

</html>
<?php $conn->close(); ?>