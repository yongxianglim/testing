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

    if ($action === 'upload_media') {
        $tid = (int)($_POST['testing_id'] ?? 0);
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            $ue       = [UPLOAD_ERR_INI_SIZE => 'Server limit.', UPLOAD_ERR_FORM_SIZE => 'Form limit.', UPLOAD_ERR_PARTIAL => 'Partial.', UPLOAD_ERR_NO_FILE => 'No file.', UPLOAD_ERR_NO_TMP_DIR => 'No temp.', UPLOAD_ERR_CANT_WRITE => 'Write fail.', UPLOAD_ERR_EXTENSION => 'Extension.'];
            $ok       = 0;
            $errMsgs  = [];
            $groupNames = $_POST['media_group_names'] ?? [];
            $groupFiles = $_FILES['media_files'] ?? [];

            if (!empty($groupFiles['name']) && !is_array(reset($groupFiles['name']))) {
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

    } elseif ($action === 'delete_media') {
        $mid = (int)($_POST['media_id'] ?? 0);
        if ($mid) {
            $s = $conn->prepare("DELETE FROM media_files WHERE media_id=?");
            $s->bind_param("i", $mid);
            $s->execute();
            $msg = "Media deleted.";
            $msgType = 'success';
        } else {
            $msg = "Invalid ID.";
            $msgType = 'error';
        }

    } elseif ($action === 'delete_media_group') {
        $tid   = (int)($_POST['testing_id'] ?? 0);
        $gname = $_POST['group_name'] ?? null;
        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
            if ($gname === '__ungrouped__' || $gname === null || $gname === '') {
                $sg = $conn->prepare("DELETE FROM media_files WHERE testing_id=? AND (group_name IS NULL OR group_name='')");
                $sg->bind_param("i", $tid);
                $sg->execute();
            } else {
                $sg = $conn->prepare("DELETE FROM media_files WHERE testing_id=? AND group_name=?");
                $sg->bind_param("is", $tid, $gname);
                $sg->execute();
            }
            $msg = "Media group deleted.";
            $msgType = 'success';
        }

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

    } elseif ($action === 'save_all') {
        $tid      = (int)($_POST['testing_id'] ?? 0);
        $msgs     = [];
        $hasError = false;

        if (!$tid) {
            $msg = "Invalid ID.";
            $msgType = 'error';
        } else {
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

    } elseif ($action === 'delete_description') {
        $did = (int)($_POST['description_id'] ?? 0);
        if ($did) {
            $s = $conn->prepare("DELETE FROM testing_description WHERE description_id=?");
            $s->bind_param("i", $did);
            $s->execute();
            $msg = "Description deleted.";
            $msgType = 'success';
        } else {
            $msg = "Invalid ID.";
            $msgType = 'error';
        }
    }
}

// ── Page data ─────────────────────────────────────────────────
$editTid = (int)($_GET['edit'] ?? 0);

$editData       = null;
$editFields     = [];
$editMedia      = [];
$editDesc       = [];
if ($editTid) {
    $s = $conn->prepare("SELECT * FROM testing WHERE testing_id=?");
    $s->bind_param("i", $editTid);
    $s->execute();
    $editData = $s->get_result()->fetch_assoc();
    if ($editData) {
        $fr = $conn->prepare("SELECT field_key,field_name,value_type,display_order FROM field_definitions WHERE testing_id=? ORDER BY display_order");
        $fr->bind_param("i", $editTid);
        $fr->execute();
        $frr = $fr->get_result();
        while ($f = $frr->fetch_assoc()) {
            $editFields[] = $f;
        }
        $mr = $conn->prepare("SELECT media_id,group_name,file_name,mime_type FROM media_files WHERE testing_id=? ORDER BY ISNULL(group_name), group_name, created_at");
        $mr->bind_param("i", $editTid);
        $mr->execute();
        $mrr = $mr->get_result();
        while ($m = $mrr->fetch_assoc()) $editMedia[] = $m;
        $dr = $conn->prepare("SELECT description_id,content FROM testing_description WHERE testing_id=? ORDER BY created_at");
        $dr->bind_param("i", $editTid);
        $dr->execute();
        $drr = $dr->get_result();
        while ($d = $drr->fetch_assoc()) $editDesc[] = $d;

        $gnStmt = $conn->prepare("SELECT DISTINCT record_value AS group_name FROM testing_record WHERE field_key = 1 AND testing_id = ? AND record_value IS NOT NULL AND record_value <> '' ORDER BY row_number");
        $gnStmt->bind_param("i", $editTid);
        $gnStmt->execute();
        $gnResult = $gnStmt->get_result();
        $existingGroupNames = [];
        while ($gn = $gnResult->fetch_assoc()) $existingGroupNames[] = $gn['group_name'];
    }
}
if (!isset($existingGroupNames)) $existingGroupNames = [];
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
            overflow: visible;
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
    </style>
    <title>Media &amp; Descriptions — Subjective Portal</title>
</head>

<body>
    <?php include 'orbs.php'; ?>

    <div class="app-layout"><?php include 'navbar.php'; ?><?php include 'modal.php'; ?>
        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-photo-film"></i> Media &amp; Descriptions</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / <a href="editor.php?edit=<?= $editTid ?>">Insert / Edit</a> / Media &amp; Descriptions</div>
            </div>
            <div class="main-body">

                <?php if ($msg): ?><div class="msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

                <a href="editor.php?edit=<?= $editTid ?>" class="btn btn-secondary" style="margin-bottom:16px;"><i class="fas fa-arrow-left"></i> Back to Record Data</a>

                <?php if ($editData): ?>
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
                            <p>Saves any pending Media files and queued Descriptions in one action.</p>
                        </div>
                        <button type="button" class="btn-save-all" id="btnSaveAll" onclick="saveAll()">
                            <i class="fas fa-floppy-disk"></i> Save All
                        </button>
                    </div>


                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
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


        // Close comboboxes when clicking elsewhere
        document.addEventListener('mousedown', function(e) {
            document.querySelectorAll('.cb-wrap.cb-open').forEach(function(w) {
                if (!w.contains(e.target)) w.classList.remove('cb-open');
            });
        });

        // ── Cursor glow ───────────────────────────────────────────
        var glow = document.createElement('div');
        glow.className = 'cursor-glow';
        document.body.appendChild(glow);
        document.addEventListener('mousemove', function(e) {
            glow.style.left = e.clientX + 'px';
            glow.style.top = e.clientY + 'px';
        });

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

        function autoResizeTextarea(el) {
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        }

        function renderDescQueue() {
            var list = document.getElementById('pendingDescsList');
            var btn = document.getElementById('btnAddDescs');
            list.innerHTML = '';
            btn.disabled = descQueue.length === 0;
            descQueue.forEach(function(text, idx) {
                var item = document.createElement('div');
                item.className = 'pending-desc-item';
                var ta = document.createElement('textarea');
                ta.className = 'pending-desc-edit';
                ta.value = text;
                ta.rows = 2;
                ta.oninput = (function(i) {
                    return function() {
                        descQueue[i] = this.value;
                        autoResizeTextarea(this);
                    };
                })(idx);
                setTimeout(function() { autoResizeTextarea(ta); }, 0);
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
                item.appendChild(ta);
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
                'Save pending Media groups and queued Descriptions in one action?'
            );
        }

        function runSaveAllFetch() {
            var btn = document.getElementById('btnSaveAll');
            btn.classList.add('saving');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

            var fd = new FormData();
            fd.append('action', 'save_all');
            fd.append('testing_id', '<?= $editTid ?>');

            if (typeof appendGroupedMediaToFormData === 'function') {
                appendGroupedMediaToFormData(fd);
            }

            if (typeof descQueue !== 'undefined') {
                descQueue.forEach(function(text, i) {
                    fd.append('description_items[' + i + ']', text);
                });
            }

            fetch('editor_media_descriptions.php', {
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
                        window.location.href = 'editor_media_descriptions.php?edit=<?= $editTid ?>';
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
