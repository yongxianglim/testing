<?php
require 'auth.php';
requireRole(['VIEWER', 'EDITOR', 'DEVELOPER']);
require 'db.php';

// Pre-load all testing records for the search dropdown
$allTestingRows = [];
$atQ = $conn->query("SELECT testing_id, project_name, project_title, testing_name, testing_method FROM testing ORDER BY project_name, project_title");
while ($r = $atQ->fetch_assoc()) $allTestingRows[] = $r;

// Fetch all media (id, testing_id, group_name, file_name, mime_type)
$allMedia = [];
$mQ = $conn->query("SELECT m.media_id, m.testing_id, m.group_name, m.file_name, m.mime_type, t.project_title, t.testing_name, t.testing_method FROM media_files m JOIN testing t ON t.testing_id = m.testing_id ORDER BY t.project_name, t.project_title, m.group_name, m.created_at");
while ($m = $mQ->fetch_assoc()) $allMedia[] = $m;

// Pre-select media from query string
$preMediaId = (int)($_GET['media_id'] ?? 0);

// Build field data per testing_id for hover display
$fieldsByTid = [];
$rQ = $conn->query("SELECT r.testing_id, r.row_number, r.table_number, r.record_value, d.field_name, d.value_type FROM testing_record r JOIN field_definitions d ON d.testing_id = r.testing_id AND d.field_key = r.field_key WHERE r.table_number = 1 ORDER BY r.testing_id, r.row_number, d.display_order");
while ($row = $rQ->fetch_assoc()) {
    $tid = (int)$row['testing_id'];
    $rn  = (int)$row['row_number'];
    if (!isset($fieldsByTid[$tid])) $fieldsByTid[$tid] = [];
    if (!isset($fieldsByTid[$tid][$rn])) $fieldsByTid[$tid][$rn] = [];
    $fieldsByTid[$tid][$rn][] = ['field_name' => $row['field_name'], 'value_type' => $row['value_type'], 'record_value' => $row['record_value']];
}

// Build reverse map: group_name → row_number
// Supports both "fk1_value" and "fk1_value, fk2_value" format group names
$fk1Map = [];
$fk1Q = $conn->query("SELECT testing_id, row_number, record_value FROM testing_record WHERE field_key = 1 AND table_number = 1 AND record_value IS NOT NULL AND record_value <> '' ORDER BY testing_id, row_number");
$fk1Rows = [];
while ($fk1Row = $fk1Q->fetch_assoc()) {
    $tid = (int)$fk1Row['testing_id'];
    if (!isset($fk1Map[$tid])) $fk1Map[$tid] = [];
    $fk1Map[$tid][$fk1Row['record_value']] = (int)$fk1Row['row_number'];
    $fk1Rows[] = ['tid' => $tid, 'rn' => (int)$fk1Row['row_number'], 'val' => $fk1Row['record_value']];
}

// Also build combined "fk1, fk2" format entries for group_name resolution
$fk2Q = $conn->query("SELECT testing_id, row_number, record_value FROM testing_record WHERE field_key = 2 AND table_number = 1 ORDER BY testing_id, row_number");
$fk2ByTidRn = [];
while ($fk2Row = $fk2Q->fetch_assoc()) {
    $fk2ByTidRn[(int)$fk2Row['testing_id']][(int)$fk2Row['row_number']] = $fk2Row['record_value'];
}
foreach ($fk1Rows as $row) {
    $tid = $row['tid'];
    $rn = $row['rn'];
    if (isset($fk2ByTidRn[$tid][$rn])) {
        $combinedKey = $row['val'] . ', ' . $fk2ByTidRn[$tid][$rn];
        $fk1Map[$tid][$combinedKey] = $rn;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'head.php'; ?>
    <title>Media Compare — Subjective Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: linear-gradient(-45deg, #FFF8F0, #EEF2FF, #F0FFF4, #FFF0F6);
            background-size: 400% 400%;
            animation: bgShift 20s ease infinite;
            overflow-x: hidden;
        }
        @keyframes bgShift { 0% { background-position: 0% 50% } 50% { background-position: 100% 50% } 100% { background-position: 0% 50% } }
        .cursor-glow {
            position: fixed; width: 300px; height: 300px; border-radius: 50%;
            background: radial-gradient(circle, rgba(107,141,181,0.08) 0%, transparent 70%);
            pointer-events: none; transform: translate(-50%,-50%); z-index: 1; transition: opacity 0.3s;
        }
        .page-orbs { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
        .orb { position: absolute; border-radius: 50%; filter: blur(90px); opacity: 0.18; }
        .orb-1 { width:520px;height:520px;background:#A7C7E7;top:-12%;left:-6%;animation:orbM1 16s ease-in-out infinite; }
        .orb-2 { width:440px;height:440px;background:#FADADD;bottom:-12%;right:-6%;animation:orbM2 18s ease-in-out infinite; }
        .orb-3 { width:360px;height:360px;background:#C1E1C1;top:35%;left:45%;animation:orbM3 13s ease-in-out infinite; }
        .orb-4 { width:280px;height:280px;background:#C1A0D8;top:10%;right:15%;animation:orbM4 11s ease-in-out infinite; }
        .orb-5 { width:240px;height:240px;background:#FFD9A0;bottom:20%;left:20%;animation:orbM5 14s ease-in-out infinite; }
        @keyframes orbM1 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(80px,60px)} }
        @keyframes orbM2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-70px,-80px)} }
        @keyframes orbM3 { 0%,100%{transform:translate(-50%,-50%) scale(1)} 50%{transform:translate(-35%,-35%) scale(1.15)} }
        @keyframes orbM4 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-50px,60px)} }
        @keyframes orbM5 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(60px,-50px)} }
        .app-layout { display:flex; min-height:100vh; position:relative; z-index:10; }
        .sidebar-toggle {
            display:none; position:fixed; top:14px; left:14px; z-index:200;
            width:40px; height:40px; border:none; border-radius:11px;
            background:rgba(255,255,255,0.7); backdrop-filter:blur(12px);
            color:#6B8DB5; font-size:16px; cursor:pointer;
            box-shadow:0 2px 12px rgba(0,0,0,0.08);
        }
        .sidebar {
            width:240px; flex-shrink:0; background:rgba(255,255,255,0.5);
            backdrop-filter:blur(28px); -webkit-backdrop-filter:blur(28px);
            border-right:1px solid rgba(255,255,255,0.65); display:flex;
            flex-direction:column; position:sticky; top:0; height:100vh;
            overflow-y:auto; z-index:100; transition:transform 0.3s cubic-bezier(0.16,1,0.3,1);
        }
        .sidebar-header { padding:22px 18px 18px; border-bottom:1px solid rgba(107,141,181,0.1); }
        .brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
        .brand-icon {
            width:42px; height:42px; background:linear-gradient(135deg,#6B8DB5,#8BB3D9);
            border-radius:13px; display:flex; align-items:center; justify-content:center;
            color:#fff; font-size:18px; box-shadow:0 4px 14px rgba(107,141,181,0.28); flex-shrink:0;
        }
        .brand-text { font-size:15px; font-weight:800; color:#2D3748; letter-spacing:-0.3px; line-height:1.2; }
        .brand-sub { font-size:11px; color:#A0AEC0; font-weight:500; }
        .sidebar-nav { flex:1; padding:14px 12px; display:flex; flex-direction:column; gap:2px; }
        .nav-label { font-size:10px; font-weight:700; color:#CBD5E0; letter-spacing:1.5px; text-transform:uppercase; padding:12px 10px 4px; }
        .sidebar-nav a {
            display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:13px;
            color:#718096; font-size:13.5px; font-weight:500; text-decoration:none; transition:all 0.22s;
        }
        .sidebar-nav a i { width:18px; text-align:center; font-size:14px; flex-shrink:0; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background:rgba(107,141,181,0.1); color:#4A7BA8; }
        .sidebar-nav a.active { font-weight:700; }
        .sidebar-footer { padding:14px 12px 18px; border-top:1px solid rgba(107,141,181,0.08); }
        .sidebar-user {
            display:flex; align-items:center; gap:10px; padding:12px;
            background:rgba(107,141,181,0.07); border-radius:14px; margin-bottom:10px;
        }
        .user-avatar {
            width:38px; height:38px; background:linear-gradient(135deg,#C1A0D8,#8BB3D9);
            border-radius:11px; display:flex; align-items:center; justify-content:center;
            color:#fff; font-size:13px; font-weight:700; flex-shrink:0;
        }
        .user-details { flex:1; overflow:hidden; }
        .user-name { font-size:13px; font-weight:700; color:#2D3748; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .user-role { font-size:11px; color:#A0AEC0; font-weight:500; }
        .sidebar-logout {
            display:flex; align-items:center; gap:8px; padding:9px 12px; border-radius:12px;
            color:#D07070; font-size:13px; font-weight:600; text-decoration:none;
            transition:all 0.22s; border:1px solid rgba(208,112,112,0.15);
        }
        .sidebar-logout:hover { background:rgba(208,112,112,0.08); }
        .main-content { flex:1; overflow:hidden; display:flex; flex-direction:column; min-width:0; }
        .main-header {
            background:rgba(255,255,255,0.45); backdrop-filter:blur(20px);
            border-bottom:1px solid rgba(255,255,255,0.6);
            padding:20px 30px; display:flex; align-items:center;
            justify-content:space-between; flex-wrap:wrap; gap:10px;
        }
        .main-header h1 { font-size:21px; font-weight:800; color:#2D3748; display:flex; align-items:center; gap:10px; }
        .main-header h1 i { color:#6B8DB5; font-size:19px; }
        .header-breadcrumb { font-size:12px; color:#A0AEC0; display:flex; align-items:center; gap:6px; }
        .header-breadcrumb a { color:#6B8DB5; text-decoration:none; }
        .main-body { padding:26px 30px; overflow-y:auto; flex:1; }
        .card {
            background:rgba(255,255,255,0.55); backdrop-filter:blur(20px);
            border:1px solid rgba(255,255,255,0.65); border-radius:22px;
            padding:26px; margin-bottom:22px;
            box-shadow:0 8px 32px rgba(0,0,0,0.04),inset 0 1px 0 rgba(255,255,255,0.85);
        }
        .card h2 { font-size:16px; font-weight:800; color:#2D3748; margin-bottom:18px; display:flex; align-items:center; gap:9px; }
        .card h2 i { color:#6B8DB5; }
        .btn {
            display:inline-flex; align-items:center; gap:7px; padding:10px 18px;
            border:none; border-radius:12px; font-size:13px; font-weight:600;
            font-family:'Inter',sans-serif; cursor:pointer; text-decoration:none;
            transition:all 0.25s; white-space:nowrap;
        }
        .btn:hover { transform:translateY(-2px); }
        .btn-primary { background:linear-gradient(135deg,#6B8DB5,#8BB3D9); color:#fff; box-shadow:0 4px 14px rgba(107,141,181,0.25); }
        .btn-secondary { background:rgba(107,141,181,0.1); color:#6B8DB5; border:1px solid rgba(107,141,181,0.2); }
        .btn-sm { padding:7px 13px; font-size:12px; border-radius:10px; }
        /* Layout controls */
        .compare-controls {
            display:flex; align-items:center; gap:14px; margin-bottom:22px; flex-wrap:wrap;
        }
        .layout-toggle-group {
            display:flex; gap:6px;
        }
        .layout-btn {
            display:inline-flex; align-items:center; gap:6px; padding:8px 14px;
            border:1.5px solid rgba(107,141,181,0.2); border-radius:10px;
            background:rgba(255,255,255,0.5); color:#718096; font-size:12px;
            font-weight:600; cursor:pointer; transition:all 0.2s;
            font-family:'Inter',sans-serif;
        }
        .layout-btn.active {
            background:linear-gradient(135deg,#6B8DB5,#8BB3D9); color:#fff;
            border-color:transparent; box-shadow:0 4px 14px rgba(107,141,181,0.25);
        }
        /* Compare grid */
        .compare-grid {
            display:grid; gap:18px; transition:all 0.3s;
        }
        .compare-grid.layout-1x4 { grid-template-columns: repeat(4, 1fr); }
        .compare-grid.layout-2x2 { grid-template-columns: repeat(2, 1fr); }
        .compare-slot {
            background:rgba(255,255,255,0.6); border:2px solid rgba(107,141,181,0.15);
            border-radius:18px; overflow:hidden; transition:all 0.3s;
            box-shadow:0 4px 18px rgba(0,0,0,0.04);
        }
        .compare-slot:hover { box-shadow:0 8px 28px rgba(107,141,181,0.18); border-color:rgba(107,141,181,0.3); }
        .compare-slot.active { border-color:rgba(107,141,181,0.45); }
        .slot-header {
            padding:12px 14px; background:rgba(107,141,181,0.07);
            border-bottom:1px solid rgba(107,141,181,0.1);
            display:flex; align-items:center; justify-content:space-between; gap:8px;
        }
        .slot-num { font-size:11px; font-weight:700; color:#6B8DB5; text-transform:uppercase; letter-spacing:1px; }
        .slot-clear-btn {
            background:none; border:none; color:#A0AEC0; cursor:pointer;
            padding:3px 6px; border-radius:6px; font-size:13px; transition:color 0.2s;
        }
        .slot-clear-btn:hover { color:#D07070; }
        .slot-image-area {
            position:relative; width:100%; aspect-ratio:1/1; overflow:hidden;
            background:rgba(107,141,181,0.04);
        }
        .slot-image-area img {
            width:100%; height:100%; object-fit:contain; display:block;
            transition:transform 0.3s ease; background:rgba(107,141,181,0.04);
        }
        .slot-image-area:hover img { transform:scale(1.04); }
        .slot-no-image {
            width:100%; height:100%; display:flex; align-items:center;
            justify-content:center; flex-direction:column; gap:8px;
            color:#CBD5E0; font-size:13px; cursor:pointer; transition:all 0.2s;
        }
        .slot-no-image:hover { color:#8BB3D9; background:rgba(107,141,181,0.06); }
        .slot-no-image i { font-size:32px; transition:transform 0.2s; }
        .slot-no-image:hover i { transform:scale(1.12); }
        .slot-no-image span { font-weight:500; }
        .slot-no-image small { font-size:11px; color:#CBD5E0; }
        .slot-info { padding:12px 14px; }
        .slot-meta-title { font-size:13px; font-weight:700; color:#2D3748; margin-bottom:2px; }
        .slot-meta-sub { font-size:11.5px; color:#718096; margin-bottom:8px; }
        .slot-fields { display:flex; flex-direction:column; gap:3px; margin-top:6px; }
        .slot-field-row { display:flex; justify-content:space-between; font-size:12px; gap:8px; }
        .slot-field-name { color:#A0AEC0; font-weight:500; }
        .slot-field-val { color:#2D3748; font-weight:600; text-align:right; max-width:60%; word-break:break-word; }
        .slot-picker-area {
            padding:14px; border-top:1px solid rgba(107,141,181,0.1);
        }
        /* Media search */
        .media-search-wrap { position:relative; }
        .media-search-input {
            width:100%; padding:9px 13px 9px 34px; border:1.5px solid rgba(107,141,181,0.2);
            border-radius:11px; font-size:13px; font-family:'Inter',sans-serif;
            background:rgba(255,255,255,0.7); color:#2D3748; outline:none; transition:all 0.25s;
        }
        .media-search-input:focus { border-color:rgba(107,141,181,0.4); box-shadow:0 0 0 3px rgba(107,141,181,0.09); }
        .media-search-icon {
            position:absolute; left:11px; top:50%; transform:translateY(-50%);
            color:#A0AEC0; font-size:13px; pointer-events:none;
        }
        .media-search-drop {
            display:none; position:absolute; top:calc(100% + 6px); left:0; right:0;
            background:rgba(255,255,255,0.97); border:1.5px solid rgba(107,141,181,0.18);
            border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,0.1);
            z-index:200; max-height:260px; overflow-y:auto;
        }
        .media-search-drop.open { display:block; animation:dropIn 0.18s ease; }
        @keyframes dropIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
        .media-opt {
            display:flex; align-items:center; gap:10px; padding:9px 12px;
            cursor:pointer; transition:background 0.12s; border-bottom:1px solid rgba(107,141,181,0.07);
        }
        .media-opt:last-child { border-bottom:none; }
        .media-opt:hover, .media-opt.hovered { background:rgba(107,141,181,0.09); }
        .media-opt-thumb {
            width:38px; height:38px; border-radius:8px; object-fit:cover; flex-shrink:0;
            background:rgba(107,141,181,0.1);
        }
        .media-opt-icon {
            width:38px; height:38px; border-radius:8px; display:flex; align-items:center;
            justify-content:center; background:rgba(107,141,181,0.1); color:#6B8DB5;
            font-size:16px; flex-shrink:0;
        }
        .media-opt-info { flex:1; min-width:0; }
        .media-opt-name { font-size:12.5px; font-weight:600; color:#2D3748; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .media-opt-sub { font-size:11px; color:#A0AEC0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .media-search-empty { padding:14px; text-align:center; color:#A0AEC0; font-size:13px; font-style:italic; }

        /* ── Browse Media Button ── */
        .btn-browse-media {
            display:inline-flex; align-items:center; gap:7px; margin-top:10px;
            padding:8px 14px; border:1.5px dashed rgba(107,141,181,0.4);
            border-radius:11px; background:rgba(107,141,181,0.05);
            color:#6B8DB5; font-size:12px; font-weight:600; font-family:'Inter',sans-serif;
            cursor:pointer; width:100%; justify-content:center; transition:all 0.22s;
        }
        .btn-browse-media:hover { background:rgba(107,141,181,0.12); border-color:rgba(107,141,181,0.6); transform:none; }

        /* ── Media Picker Modal ── */
        .picker-overlay {
            display:none; position:fixed; inset:0; z-index:2000;
            background:rgba(20,24,40,0.55); backdrop-filter:blur(6px);
            align-items:center; justify-content:center; padding:20px;
        }
        .picker-overlay.open { display:flex; animation:pickerFadeIn 0.22s ease; }
        @keyframes pickerFadeIn { from{opacity:0} to{opacity:1} }

        .picker-modal {
            background:rgba(255,255,255,0.97); border-radius:24px; width:100%; max-width:980px;
            max-height:88vh; display:flex; flex-direction:column;
            box-shadow:0 24px 80px rgba(0,0,0,0.22),0 0 0 1px rgba(255,255,255,0.8);
            animation:pickerSlideUp 0.25s cubic-bezier(0.16,1,0.3,1);
            overflow:hidden;
        }
        @keyframes pickerSlideUp { from{opacity:0;transform:translateY(28px) scale(0.97)} to{opacity:1;transform:translateY(0) scale(1)} }

        /* Modal Header */
        .picker-header {
            padding:18px 22px 14px; border-bottom:1px solid rgba(107,141,181,0.12);
            display:flex; align-items:center; gap:14px; flex-shrink:0;
            background:linear-gradient(to bottom, rgba(107,141,181,0.06), transparent);
        }
        .picker-header-icon {
            width:42px; height:42px; border-radius:13px; flex-shrink:0;
            background:linear-gradient(135deg,#6B8DB5,#8BB3D9);
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-size:17px; box-shadow:0 4px 14px rgba(107,141,181,0.3);
        }
        .picker-header-text { flex:1; min-width:0; }
        .picker-header-title { font-size:16px; font-weight:800; color:#2D3748; }
        .picker-header-sub { font-size:12px; color:#A0AEC0; margin-top:1px; }
        .picker-close-btn {
            width:36px; height:36px; border:none; border-radius:10px; cursor:pointer;
            background:rgba(107,141,181,0.08); color:#718096; font-size:16px;
            display:flex; align-items:center; justify-content:center;
            transition:all 0.2s; flex-shrink:0;
        }
        .picker-close-btn:hover { background:rgba(208,112,112,0.1); color:#D07070; }

        /* Modal Search Bar */
        .picker-search-bar {
            padding:12px 22px; border-bottom:1px solid rgba(107,141,181,0.1); flex-shrink:0;
        }
        .picker-search-inner { position:relative; }
        .picker-search-inner input {
            width:100%; padding:10px 14px 10px 38px;
            border:1.5px solid rgba(107,141,181,0.22); border-radius:12px;
            font-size:13.5px; font-family:'Inter',sans-serif; color:#2D3748;
            background:rgba(107,141,181,0.04); outline:none; transition:all 0.22s;
        }
        .picker-search-inner input:focus { border-color:rgba(107,141,181,0.5); background:#fff; box-shadow:0 0 0 3px rgba(107,141,181,0.1); }
        .picker-search-inner i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#A0AEC0; font-size:14px; pointer-events:none; }
        .picker-search-clear {
            position:absolute; right:10px; top:50%; transform:translateY(-50%);
            background:none; border:none; color:#A0AEC0; cursor:pointer; padding:4px;
            border-radius:6px; font-size:13px; display:none; line-height:1;
        }
        .picker-search-clear.visible { display:block; }

        /* Modal Body */
        .picker-body { display:flex; flex:1; overflow:hidden; min-height:0; }

        /* Sidebar filters */
        .picker-sidebar {
            width:210px; flex-shrink:0; border-right:1px solid rgba(107,141,181,0.1);
            overflow-y:auto; padding:14px 12px;
            background:rgba(107,141,181,0.02);
        }
        .picker-filter-section { margin-bottom:18px; }
        .picker-filter-label {
            font-size:10px; font-weight:700; color:#A0AEC0; letter-spacing:1.4px;
            text-transform:uppercase; padding:0 4px; margin-bottom:8px; display:block;
        }
        .picker-filter-item {
            display:flex; align-items:center; justify-content:space-between;
            padding:7px 10px; border-radius:9px; cursor:pointer; transition:all 0.15s;
            font-size:12.5px; color:#4A5568; font-weight:500; margin-bottom:2px;
        }
        .picker-filter-item:hover { background:rgba(107,141,181,0.1); color:#4A7BA8; }
        .picker-filter-item.active { background:rgba(107,141,181,0.14); color:#4A7BA8; font-weight:700; }
        .picker-filter-count {
            font-size:10.5px; color:#A0AEC0; background:rgba(107,141,181,0.1);
            padding:2px 7px; border-radius:20px; font-weight:600;
        }
        .picker-filter-item.active .picker-filter-count { background:rgba(107,141,181,0.2); color:#6B8DB5; }

        /* Media grid */
        .picker-grid-wrap { flex:1; overflow-y:auto; padding:16px; min-width:0; }
        .picker-grid-info {
            font-size:12px; color:#A0AEC0; margin-bottom:12px;
            display:flex; align-items:center; justify-content:space-between;
        }
        .picker-grid-info strong { color:#4A5568; }
        .picker-view-btns { display:flex; gap:5px; }
        .picker-view-btn {
            width:28px; height:28px; border:1.5px solid rgba(107,141,181,0.2);
            border-radius:7px; background:none; color:#A0AEC0; cursor:pointer;
            display:flex; align-items:center; justify-content:center; font-size:12px; transition:all 0.15s;
        }
        .picker-view-btn.active { background:rgba(107,141,181,0.12); color:#6B8DB5; border-color:rgba(107,141,181,0.35); }

        .picker-media-grid { display:flex; flex-direction:column; gap:18px; }
        .picker-group-header {
            display:flex; align-items:center; gap:8px; padding:0 2px 8px;
            font-size:13px; font-weight:700; color:#4A5568; border-bottom:1px solid rgba(107,141,181,0.1);
            margin-bottom:10px;
        }
        .picker-group-header i { color:#6B8DB5; font-size:13px; }
        .picker-group-header .picker-group-count {
            font-size:10.5px; color:#A0AEC0; background:rgba(107,141,181,0.1);
            padding:2px 7px; border-radius:20px; font-weight:600; margin-left:auto;
        }
        .picker-group-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
        .picker-group-grid.list-view { grid-template-columns:1fr; gap:6px; }

        .picker-media-item {
            border:2px solid rgba(107,141,181,0.12); border-radius:14px; overflow:hidden;
            cursor:pointer; transition:all 0.2s; background:rgba(255,255,255,0.7);
            position:relative;
        }
        .picker-media-item:hover { border-color:rgba(107,141,181,0.45); box-shadow:0 6px 20px rgba(107,141,181,0.18); transform:translateY(-2px); }
        .picker-media-item.selected { border-color:#6B8DB5; box-shadow:0 0 0 3px rgba(107,141,181,0.2); }
        .picker-media-item .item-check {
            position:absolute; top:7px; right:7px; width:22px; height:22px; border-radius:50%;
            background:#6B8DB5; color:#fff; font-size:11px; display:none;
            align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(107,141,181,0.4);
            z-index:2;
        }
        .picker-media-item.selected .item-check { display:flex; }

        /* Grid view thumbnail */
        .picker-media-item .item-thumb {
            width:100%; aspect-ratio:1/1; overflow:hidden; background:rgba(107,141,181,0.06);
            display:flex; align-items:center; justify-content:center;
        }
        .picker-media-item .item-thumb img { width:100%; height:100%; object-fit:cover; transition:transform 0.3s; }
        .picker-media-item:hover .item-thumb img { transform:scale(1.06); }
        .picker-media-item .item-thumb .file-icon { font-size:28px; color:#8BB3D9; }
        .picker-media-item .item-caption {
            padding:8px 10px; border-top:1px solid rgba(107,141,181,0.08);
        }
        .picker-media-item .item-name { font-size:11.5px; font-weight:600; color:#2D3748; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .picker-media-item .item-sub { font-size:10.5px; color:#A0AEC0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }

        /* List view */
        .picker-group-grid.list-view .picker-media-item { border-radius:11px; }
        .picker-group-grid.list-view .item-thumb { width:50px; aspect-ratio:1/1; flex-shrink:0; border-radius:0; }
        .picker-group-grid.list-view .picker-media-item { display:flex; flex-direction:row; align-items:center; }
        .picker-group-grid.list-view .item-caption { border-top:none; border-left:1px solid rgba(107,141,181,0.08); flex:1; min-width:0; padding:10px 12px; }
        .picker-group-grid.list-view .item-check { position:static; margin-right:10px; flex-shrink:0; }
        .picker-group-grid.list-view .picker-media-item.selected .item-check { order:-1; }

        .picker-empty { padding:50px 20px; text-align:center; color:#A0AEC0; }
        .picker-empty i { font-size:42px; margin-bottom:14px; display:block; opacity:0.4; }
        .picker-empty p { font-size:14px; }

        /* Modal Footer */
        .picker-footer {
            padding:14px 22px; border-top:1px solid rgba(107,141,181,0.12);
            display:flex; align-items:center; justify-content:space-between; gap:12px;
            flex-shrink:0; background:rgba(255,255,255,0.8);
        }
        .picker-selection-info { font-size:13px; color:#718096; }
        .picker-selection-info strong { color:#2D3748; font-weight:700; }
        .picker-footer-btns { display:flex; gap:10px; }

        /* Responsive */
        @media(max-width:900px) {
            .compare-grid.layout-1x4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media(max-width:600px) {
            .compare-grid.layout-1x4, .compare-grid.layout-2x2 { grid-template-columns: 1fr; }
            .main-body { padding:16px; }
        }
        @media(max-width:768px) {
            .sidebar-toggle { display:flex; align-items:center; justify-content:center; }
            .sidebar { position:fixed; top:0; left:0; height:100vh; transform:translateX(-100%); z-index:150; }
            .sidebar.open { transform:translateX(0); }
            .main-header { padding:16px 18px; }
        }
    </style>
</head>
<body>
    <?php include 'orbs.php'; ?>
    <div class="app-layout">
        <?php include 'navbar.php'; ?>
        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-images"></i> Media Compare</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / Media Compare</div>
            </div>
            <div class="main-body">

                <div class="card">
                    <h2><i class="fas fa-sliders"></i> Compare Up to 4 Media Items</h2>

                    <!-- Layout toggle -->
                    <div class="compare-controls">
                        <span style="font-size:13px;font-weight:600;color:#4A5568;">Layout:</span>
                        <div class="layout-toggle-group">
                            <button class="layout-btn active" id="btnLayout1x4" onclick="setLayout('1x4')">
                                <i class="fas fa-grip-lines-vertical"></i> 1×4
                            </button>
                            <button class="layout-btn" id="btnLayout2x2" onclick="setLayout('2x2')">
                                <i class="fas fa-grip"></i> 2×2
                            </button>
                        </div>
                    </div>

                    <!-- 4-slot comparison grid -->
                    <div class="compare-grid layout-1x4" id="compareGrid">
                        <?php for ($s = 1; $s <= 4; $s++): ?>
                        <div class="compare-slot" id="slot<?= $s ?>">
                            <div class="slot-header">
                                <span class="slot-num">Slot <?= $s ?></span>
                                <button class="slot-clear-btn" onclick="clearSlot(<?= $s ?>)" title="Clear"><i class="fas fa-times"></i></button>
                            </div>
                            <div class="slot-image-area" id="slotImg<?= $s ?>" onclick="openSlotMediaPicker(<?= $s ?>)">
                                <div class="slot-no-image"><i class="fas fa-image"></i><span>No media selected</span><small>Click to browse</small></div>
                            </div>
                            <div class="slot-info" id="slotInfo<?= $s ?>">
                                <div style="font-size:12px;color:#CBD5E0;font-style:italic;">Select media below</div>
                            </div>
                            <div class="slot-picker-area">
                                <button class="btn-browse-media" onclick="openPickerModal(<?= $s ?>)">
                                    <i class="fas fa-folder-open"></i> Browse Media
                                </button>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Google Picker–style Media Picker Modal ── -->
    <div class="picker-overlay" id="pickerOverlay" onclick="handlePickerOverlayClick(event)">
        <div class="picker-modal" id="pickerModal">
            <!-- Header -->
            <div class="picker-header">
                <div class="picker-header-icon"><i class="fas fa-photo-film"></i></div>
                <div class="picker-header-text">
                    <div class="picker-header-title">Select Media</div>
                    <div class="picker-header-sub" id="pickerHeaderSub">Choose a media item for Slot 1</div>
                </div>
                <button class="picker-close-btn" onclick="closePickerModal()" title="Close"><i class="fas fa-times"></i></button>
            </div>

            <!-- Search bar -->
            <div class="picker-search-bar">
                <div class="picker-search-inner">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="pickerSearchInput" placeholder="Search by file name, project, group…" oninput="pickerHandleSearch(this.value)" autocomplete="off" spellcheck="false">
                    <button class="picker-search-clear" id="pickerSearchClear" onclick="pickerClearSearch()"><i class="fas fa-times-circle"></i></button>
                </div>
            </div>

            <!-- Body: sidebar + grid -->
            <div class="picker-body">
                <!-- Sidebar -->
                <div class="picker-sidebar" id="pickerSidebar">
                    <div class="picker-filter-section">
                        <span class="picker-filter-label">Type</span>
                        <div class="picker-filter-item active" id="pf-type-all" onclick="pickerSetTypeFilter('all')">
                            <span><i class="fas fa-layer-group" style="width:14px;margin-right:6px;color:#A0AEC0;"></i>All media</span>
                            <span class="picker-filter-count" id="pf-count-all">0</span>
                        </div>
                        <div class="picker-filter-item" id="pf-type-image" onclick="pickerSetTypeFilter('image')">
                            <span><i class="fas fa-image" style="width:14px;margin-right:6px;color:#A0AEC0;"></i>Images</span>
                            <span class="picker-filter-count" id="pf-count-image">0</span>
                        </div>
                        <div class="picker-filter-item" id="pf-type-file" onclick="pickerSetTypeFilter('file')">
                            <span><i class="fas fa-file" style="width:14px;margin-right:6px;color:#A0AEC0;"></i>Files</span>
                            <span class="picker-filter-count" id="pf-count-file">0</span>
                        </div>
                    </div>
                    <div class="picker-filter-section">
                        <span class="picker-filter-label">Project</span>
                        <div id="pickerProjectFilters"></div>
                    </div>
                </div>

                <!-- Media grid -->
                <div class="picker-grid-wrap" id="pickerGridWrap">
                    <div class="picker-grid-info">
                        <span id="pickerResultCount"><strong>0</strong> items</span>
                        <div class="picker-view-btns">
                            <button class="picker-view-btn active" id="pvbGrid" onclick="setPickerView('grid')" title="Grid view"><i class="fas fa-grip"></i></button>
                            <button class="picker-view-btn" id="pvbList" onclick="setPickerView('list')" title="List view"><i class="fas fa-list"></i></button>
                        </div>
                    </div>
                    <div class="picker-media-grid" id="pickerMediaGrid"></div>
                </div>
            </div>

            <!-- Footer -->
            <div class="picker-footer">
                <div class="picker-selection-info" id="pickerSelInfo">No item selected</div>
                <div class="picker-footer-btns">
                    <button class="btn btn-secondary btn-sm" onclick="closePickerModal()">Cancel</button>
                    <button class="btn btn-primary btn-sm" id="pickerConfirmBtn" onclick="pickerConfirmSelection()" disabled>
                        <i class="fas fa-check"></i> Select
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Embed all media metadata for JS (no blob data) -->
    <script>
    var ALL_MEDIA = <?php
        $forJs = [];
        foreach ($allMedia as $m) {
            $tid = (int)$m['testing_id'];
            $gn  = $m['group_name'];
            $mn  = $m['file_name'];
            $mt  = $m['mime_type'];
            $mid = (int)$m['media_id'];
            $rn  = isset($fk1Map[$tid][$gn]) ? $fk1Map[$tid][$gn] : null; // group_name == field_key=1 record_value → row_number mapping

            // Build field rows for this media via group_name→row_number
            $fields = [];
            if ($rn !== null && isset($fieldsByTid[$tid][$rn])) {
                $fields = $fieldsByTid[$tid][$rn];
            }

            $forJs[] = [
                'media_id'     => $mid,
                'testing_id'   => $tid,
                'file_name'    => $mn,
                'mime_type'    => $mt,
                'group_name'   => $gn,
                'is_image'     => str_starts_with($mt, 'image/'),
                'project_title'=> $m['project_title'],
                'testing_name' => $m['testing_name'],
                'testing_method'=> $m['testing_method'],
                'row_number'   => $rn,
                'fields'       => $fields,
            ];
        }
        echo json_encode($forJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    var PRE_MEDIA_ID = <?= $preMediaId ?>;

    // State: which media is in each slot (null or media_id)
    var slotMedia = { 1: null, 2: null, 3: null, 4: null };
    var currentLayout = '1x4';
    var searchTimers = {};

    function setLayout(layout) {
        currentLayout = layout;
        var grid = document.getElementById('compareGrid');
        grid.className = 'compare-grid layout-' + layout;
        document.getElementById('btnLayout1x4').classList.toggle('active', layout === '1x4');
        document.getElementById('btnLayout2x2').classList.toggle('active', layout === '2x2');
    }

    function findMedia(mediaId) {
        return ALL_MEDIA.find(function(m) { return m.media_id === mediaId; });
    }

    function openSlotMediaPicker(slot) {
        if (slotMedia[slot]) return; // only trigger when no media is selected
        openPickerModal(slot);
    }

    // ── Media Picker Modal State ──
    var pickerActiveSlot = null;
    var pickerTypeFilter = 'all';
    var pickerProjectFilter = null;
    var pickerSearchQuery = '';
    var pickerSelectedId = null;
    var pickerView = 'grid';

    function openPickerModal(slot) {
        pickerActiveSlot = slot;
        pickerSelectedId = slotMedia[slot] || null;
        pickerTypeFilter = 'all';
        pickerProjectFilter = null;
        pickerSearchQuery = '';

        document.getElementById('pickerHeaderSub').textContent = 'Choose a media item for Slot ' + slot;
        document.getElementById('pickerSearchInput').value = '';
        document.getElementById('pickerSearchClear').classList.remove('visible');

        buildPickerSidebarProjects();
        updatePickerTypeCounts();
        renderPickerGrid();
        updatePickerFooter();

        document.getElementById('pickerOverlay').classList.add('open');
        setTimeout(function() { document.getElementById('pickerSearchInput').focus(); }, 120);
    }

    function closePickerModal() {
        document.getElementById('pickerOverlay').classList.remove('open');
        pickerActiveSlot = null;
        pickerSelectedId = null;
    }

    function handlePickerOverlayClick(e) {
        if (e.target === document.getElementById('pickerOverlay')) closePickerModal();
    }

    function buildPickerSidebarProjects() {
        var seen = {};
        ALL_MEDIA.forEach(function(m) { seen[m.project_title] = (seen[m.project_title] || 0) + 1; });
        var projects = Object.keys(seen).sort();
        var container = document.getElementById('pickerProjectFilters');
        container.innerHTML = '';

        var allItem = document.createElement('div');
        allItem.className = 'picker-filter-item active';
        allItem.id = 'pf-proj-all';
        allItem.innerHTML = '<span><i class="fas fa-folder-open" style="width:14px;margin-right:6px;color:#A0AEC0;"></i>All projects</span>' +
            '<span class="picker-filter-count">' + ALL_MEDIA.length + '</span>';
        allItem.onclick = function() { pickerSetProjectFilter(null); };
        container.appendChild(allItem);

        projects.forEach(function(proj) {
            var item = document.createElement('div');
            item.className = 'picker-filter-item';
            item.id = 'pf-proj-' + encodeURIComponent(proj);
            item.innerHTML = '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px;" title="' + escHtml(proj) + '">' +
                '<i class="fas fa-folder" style="width:14px;margin-right:6px;color:#A0AEC0;flex-shrink:0;"></i>' + escHtml(proj) + '</span>' +
                '<span class="picker-filter-count">' + seen[proj] + '</span>';
            item.onclick = (function(p) { return function() { pickerSetProjectFilter(p); }; })(proj);
            container.appendChild(item);
        });
    }

    function updatePickerTypeCounts() {
        var all = filterPickerMedia('', null, '');
        var images = all.filter(function(m) { return m.is_image; });
        var files  = all.filter(function(m) { return !m.is_image; });
        document.getElementById('pf-count-all').textContent = all.length;
        document.getElementById('pf-count-image').textContent = images.length;
        document.getElementById('pf-count-file').textContent = files.length;
    }

    function filterPickerMedia(typeF, projF, searchQ) {
        var q = (searchQ || '').trim().toLowerCase();
        return ALL_MEDIA.filter(function(m) {
            if (typeF === 'image' && !m.is_image) return false;
            if (typeF === 'file'  && m.is_image)  return false;
            if (projF !== null && m.project_title !== projF) return false;
            if (q) {
                var hay = (m.file_name + ' ' + (m.group_name || '') + ' ' + m.project_title + ' ' + m.testing_name).toLowerCase();
                if (hay.indexOf(q) < 0) return false;
            }
            return true;
        });
    }

    function renderPickerGrid() {
        var grid = document.getElementById('pickerMediaGrid');
        var results = filterPickerMedia(pickerTypeFilter, pickerProjectFilter, pickerSearchQuery);

        document.getElementById('pickerResultCount').innerHTML = '<strong>' + results.length + '</strong> item' + (results.length !== 1 ? 's' : '');

        grid.className = 'picker-media-grid';
        grid.innerHTML = '';

        if (results.length === 0) {
            grid.innerHTML = '<div class="picker-empty"><i class="fas fa-photo-film"></i><p>No media matches your search.</p></div>';
            return;
        }

        // Group results by group_name (preserve order of first appearance)
        var groupOrder = [];
        var grouped = {};
        results.forEach(function(m) {
            var gn = m.group_name || 'Ungrouped';
            if (!grouped[gn]) {
                grouped[gn] = [];
                groupOrder.push(gn);
            }
            grouped[gn].push(m);
        });

        var isListView = pickerView === 'list';

        groupOrder.forEach(function(gn) {
            var section = document.createElement('div');
            section.className = 'picker-group-section';

            var header = document.createElement('div');
            header.className = 'picker-group-header';
            header.innerHTML = '<i class="fas fa-layer-group"></i>' + escHtml(gn) +
                '<span class="picker-group-count">' + grouped[gn].length + '</span>';
            section.appendChild(header);

            var subGrid = document.createElement('div');
            subGrid.className = 'picker-group-grid' + (isListView ? ' list-view' : '');

            grouped[gn].forEach(function(m) {
                var item = document.createElement('div');
                item.className = 'picker-media-item' + (m.media_id === pickerSelectedId ? ' selected' : '');
                item.dataset.mediaId = m.media_id;

                var checkEl = document.createElement('div');
                checkEl.className = 'item-check';
                checkEl.innerHTML = '<i class="fas fa-check"></i>';

                var thumbEl = document.createElement('div');
                thumbEl.className = 'item-thumb';
                if (m.is_image) {
                    var img = document.createElement('img');
                    img.src = 'media.php?id=' + m.media_id;
                    img.alt = m.file_name;
                    img.loading = 'lazy';
                    thumbEl.appendChild(img);
                } else {
                    thumbEl.innerHTML = '<i class="fas fa-file file-icon"></i>';
                }

                var captionEl = document.createElement('div');
                captionEl.className = 'item-caption';
                captionEl.innerHTML = '<div class="item-name" title="' + escHtml(m.file_name) + '">' + escHtml(m.file_name) + '</div>' +
                    '<div class="item-sub">' + escHtml(m.project_title) + (m.group_name ? ' · ' + escHtml(m.group_name) : '') + '</div>';

                item.appendChild(checkEl);
                item.appendChild(thumbEl);
                item.appendChild(captionEl);

                (function(mediaId) {
                    item.addEventListener('click', function() {
                        pickerSelectItem(mediaId);
                    });
                    item.addEventListener('dblclick', function() {
                        pickerSelectItem(mediaId);
                        pickerConfirmSelection();
                    });
                })(m.media_id);

                subGrid.appendChild(item);
            });

            section.appendChild(subGrid);
            grid.appendChild(section);
        });
    }

    function pickerSelectItem(mediaId) {
        pickerSelectedId = (pickerSelectedId === mediaId) ? null : mediaId;
        // Update visual state without full re-render
        document.querySelectorAll('.picker-media-item').forEach(function(el) {
            el.classList.toggle('selected', parseInt(el.dataset.mediaId) === pickerSelectedId);
        });
        updatePickerFooter();
    }

    function updatePickerFooter() {
        var infoEl = document.getElementById('pickerSelInfo');
        var confirmBtn = document.getElementById('pickerConfirmBtn');
        if (pickerSelectedId) {
            var m = findMedia(pickerSelectedId);
            infoEl.innerHTML = '<strong>' + escHtml(m ? m.file_name : 'Selected') + '</strong> &mdash; ' + escHtml(m ? m.project_title : '');
            confirmBtn.disabled = false;
        } else {
            infoEl.textContent = 'No item selected';
            confirmBtn.disabled = true;
        }
    }

    function pickerConfirmSelection() {
        if (!pickerSelectedId || !pickerActiveSlot) return;
        selectMedia(pickerActiveSlot, pickerSelectedId);
        closePickerModal();
    }

    function pickerSetTypeFilter(type) {
        pickerTypeFilter = type;
        ['all','image','file'].forEach(function(t) {
            document.getElementById('pf-type-' + t).classList.toggle('active', t === type);
        });
        renderPickerGrid();
    }

    function pickerSetProjectFilter(proj) {
        pickerProjectFilter = proj;
        document.getElementById('pf-proj-all').classList.toggle('active', proj === null);
        document.querySelectorAll('[id^="pf-proj-"]').forEach(function(el) {
            if (el.id === 'pf-proj-all') return;
            el.classList.remove('active');
        });
        if (proj !== null) {
            var el = document.getElementById('pf-proj-' + encodeURIComponent(proj));
            if (el) el.classList.add('active');
        }
        renderPickerGrid();
    }

    function pickerHandleSearch(val) {
        pickerSearchQuery = val;
        document.getElementById('pickerSearchClear').classList.toggle('visible', val.length > 0);
        renderPickerGrid();
    }

    function pickerClearSearch() {
        document.getElementById('pickerSearchInput').value = '';
        pickerHandleSearch('');
        document.getElementById('pickerSearchInput').focus();
    }

    function setPickerView(view) {
        pickerView = view;
        document.getElementById('pvbGrid').classList.toggle('active', view === 'grid');
        document.getElementById('pvbList').classList.toggle('active', view === 'list');
        renderPickerGrid();
    }

    // Keyboard shortcut: Esc closes picker
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('pickerOverlay').classList.contains('open')) {
            closePickerModal();
        }
    });

    function clearSlot(slot) {
        slotMedia[slot] = null;
        renderSlot(slot);
    }

    function selectMedia(slot, mediaId) {
        slotMedia[slot] = mediaId;
        renderSlot(slot);
        // Clear the search input and close dropdown
        var inp = document.getElementById('slotSearch' + slot);
        if (inp) inp.value = '';
        closeSearchDrop(slot, 0);
    }

    function renderSlot(slot) {
        var mid = slotMedia[slot];
        var imgArea = document.getElementById('slotImg' + slot);
        var infoArea = document.getElementById('slotInfo' + slot);
        var slotEl = document.getElementById('slot' + slot);

        if (!mid) {
            imgArea.innerHTML = '<div class="slot-no-image"><i class="fas fa-image"></i><span>No media selected</span><small>Click to browse</small></div>';
            infoArea.innerHTML = '<div style="font-size:12px;color:#CBD5E0;font-style:italic;">Select media below</div>';
            slotEl.classList.remove('active');
            return;
        }

        var m = findMedia(mid);
        if (!m) return;
        slotEl.classList.add('active');

        if (m.is_image) {
            imgArea.innerHTML = '<a href="media_detail.php?media_id=' + mid + '">' +
                '<img src="media.php?id=' + mid + '" alt="' + escHtml(m.file_name) + '">' +
                '</a>';
        } else {
            imgArea.innerHTML = '<div class="slot-no-image"><i class="fas fa-file" style="color:#6B8DB5;"></i>' +
                '<a href="media_detail.php?media_id=' + mid + '" class="btn btn-sm btn-secondary" style="margin-top:8px;">' +
                '<i class="fas fa-eye"></i> View Details</a></div>';
        }

        var rowLabel = m.group_name ? 'Group: ' + escHtml(m.group_name) : '';
        var info = '<div class="slot-meta-title">' + escHtml(m.project_title) + '</div>' +
            '<div class="slot-meta-sub">' + escHtml(m.testing_name) + ' / ' + escHtml(m.testing_method) + '</div>';
        if (rowLabel) info += '<div style="font-size:11px;color:#6B8DB5;margin-bottom:6px;">' + rowLabel + '</div>';
        info += '<div class="slot-fields">';
        if (m.fields && m.fields.length > 0) {
            m.fields.forEach(function(f) {
                var val = f.record_value !== null && f.record_value !== '' ? f.record_value : '—';
                info += '<div class="slot-field-row"><span class="slot-field-name">' + escHtml(f.field_name) + '</span>' +
                    '<span class="slot-field-val">' + escHtml(val) + '</span></div>';
            });
        } else {
            info += '<div style="font-size:12px;color:#CBD5E0;font-style:italic;">No field data</div>';
        }
        info += '</div>';
        infoArea.innerHTML = info;
    }

    function handleSearchInput(slot, query) {
        clearTimeout(searchTimers[slot]);
        searchTimers[slot] = setTimeout(function() {
            renderSearchDrop(slot, query);
        }, 120);
    }

    function openSearchDrop(slot) {
        renderSearchDrop(slot, document.getElementById('slotSearch' + slot).value);
        document.getElementById('slotDrop' + slot).classList.add('open');
    }

    function closeSearchDrop(slot, delay) {
        setTimeout(function() {
            var drop = document.getElementById('slotDrop' + slot);
            if (drop) drop.classList.remove('open');
        }, delay);
    }

    function renderSearchDrop(slot, query) {
        var drop = document.getElementById('slotDrop' + slot);
        if (!drop) return;
        drop.classList.add('open');
        drop.innerHTML = '';

        var q = (query || '').trim().toLowerCase();
        var filtered = ALL_MEDIA.filter(function(m) {
            if (!q) return true;
            var hay = (m.file_name + ' ' + (m.group_name || '') + ' ' + m.project_title + ' ' + m.testing_name).toLowerCase();
            return hay.indexOf(q) >= 0;
        }).slice(0, 60);

        if (filtered.length === 0) {
            drop.innerHTML = '<div class="media-search-empty">No media found.</div>';
            return;
        }

        filtered.forEach(function(m) {
            var opt = document.createElement('div');
            opt.className = 'media-opt';
            if (slotMedia[slot] === m.media_id) opt.classList.add('hovered');

            var thumb;
            if (m.is_image) {
                thumb = document.createElement('img');
                thumb.className = 'media-opt-thumb';
                thumb.src = 'media.php?id=' + m.media_id;
                thumb.alt = m.file_name;
                thumb.loading = 'lazy';
            } else {
                thumb = document.createElement('div');
                thumb.className = 'media-opt-icon';
                thumb.innerHTML = '<i class="fas fa-file"></i>';
            }

            var infoDiv = document.createElement('div');
            infoDiv.className = 'media-opt-info';
            var nameDiv = document.createElement('div');
            nameDiv.className = 'media-opt-name';
            nameDiv.textContent = m.file_name;
            var subDiv = document.createElement('div');
            subDiv.className = 'media-opt-sub';
            subDiv.textContent = m.project_title + ' · ' + m.testing_name + (m.group_name ? ' · ' + m.group_name : '');
            infoDiv.appendChild(nameDiv);
            infoDiv.appendChild(subDiv);

            opt.appendChild(thumb);
            opt.appendChild(infoDiv);

            (function(mediaId, sl) {
                opt.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    selectMedia(sl, mediaId);
                });
            })(m.media_id, slot);

            drop.appendChild(opt);
        });
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Cursor glow
    var glow = document.createElement('div');
    glow.className = 'cursor-glow';
    document.body.appendChild(glow);
    document.addEventListener('mousemove', function(e) {
        glow.style.left = e.clientX + 'px';
        glow.style.top = e.clientY + 'px';
    });

    // Pre-load media if coming from viewer
    document.addEventListener('DOMContentLoaded', function() {
        if (PRE_MEDIA_ID && PRE_MEDIA_ID > 0) {
            var m = findMedia(PRE_MEDIA_ID);
            if (m) selectMedia(1, PRE_MEDIA_ID);
        }
        // Close dropdowns on outside click
        document.addEventListener('mousedown', function(e) {
            [1,2,3,4].forEach(function(s) {
                var drop = document.getElementById('slotDrop' + s);
                var inp  = document.getElementById('slotSearch' + s);
                if (drop && inp && !drop.contains(e.target) && e.target !== inp) {
                    drop.classList.remove('open');
                }
            });
        });
    });
    </script>
</body>
</html>