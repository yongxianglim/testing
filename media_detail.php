<?php
require 'auth.php';
requireRole(['VIEWER', 'EDITOR', 'DEVELOPER']);
require 'db.php';

$mediaId = (int)($_GET['media_id'] ?? 0);
if (!$mediaId) {
    http_response_code(400);
    exit("Invalid media ID.");
}

// Fetch media file info (without blob data for the page; we use media.php?id= for serving)
$stmt = $conn->prepare("SELECT m.media_id, m.testing_id, m.group_name, m.file_name, m.mime_type,
    t.project_title, t.testing_name, t.testing_method
    FROM media_files m
    JOIN testing t ON t.testing_id = m.testing_id
    WHERE m.media_id = ?");
$stmt->bind_param("i", $mediaId);
$stmt->execute();
$media = $stmt->get_result()->fetch_assoc();
if (!$media) {
    http_response_code(404);
    exit("Media not found.");
}

$tid       = (int)$media['testing_id'];
$groupName = $media['group_name'];
$isImage   = str_starts_with($media['mime_type'], 'image/');

// Resolve row_number from group_name:
// group_name may be "fk1_value" or "fk1_value, fk2_value" format
$rowNumber = null;
$rowFields = [];

if ($groupName !== null && $groupName !== '') {
    // Try exact match with field_key=1 first
    $rStmt = $conn->prepare("SELECT row_number FROM testing_record WHERE testing_id = ? AND field_key = 1 AND record_value = ? AND table_number = 1 LIMIT 1");
    $rStmt->bind_param("is", $tid, $groupName);
    $rStmt->execute();
    $rRow = $rStmt->get_result()->fetch_assoc();

    // If no match and group_name contains ", ", try combined fk1+fk2 format
    if (!$rRow && str_contains($groupName, ', ')) {
        $parts = explode(', ', $groupName, 2);
        $fk1Val = $parts[0];
        $fk2Val = $parts[1];
        $rStmt2 = $conn->prepare("SELECT r1.row_number FROM testing_record r1 JOIN testing_record r2 ON r2.testing_id = r1.testing_id AND r2.row_number = r1.row_number AND r2.table_number = r1.table_number WHERE r1.testing_id = ? AND r1.field_key = 1 AND r1.record_value = ? AND r2.field_key = 2 AND r2.record_value = ? AND r1.table_number = 1 LIMIT 1");
        $rStmt2->bind_param("iss", $tid, $fk1Val, $fk2Val);
        $rStmt2->execute();
        $rRow = $rStmt2->get_result()->fetch_assoc();
    }

    if ($rRow) {
        $rowNumber = (int)$rRow['row_number'];
    }
}

// If we have a row_number, fetch ALL field data for that row
if ($rowNumber !== null) {
    $fStmt = $conn->prepare("SELECT d.field_name, d.value_type, r.record_value
        FROM testing_record r
        JOIN field_definitions d ON d.testing_id = r.testing_id AND d.field_key = r.field_key
        WHERE r.testing_id = ? AND r.row_number = ? AND r.table_number = 1
        ORDER BY d.display_order");
    $fStmt->bind_param("ii", $tid, $rowNumber);
    $fStmt->execute();
    $fResult = $fStmt->get_result();
    while ($f = $fResult->fetch_assoc()) {
        $rowFields[] = $f;
    }
}

// Fetch all media in the same group (same testing_id + group_name) for gallery
$galleryMedia = [];
if ($groupName !== null && $groupName !== '') {
    $gStmt = $conn->prepare("SELECT media_id, file_name, mime_type FROM media_files WHERE testing_id = ? AND group_name = ? ORDER BY created_at");
    $gStmt->bind_param("is", $tid, $groupName);
    $gStmt->execute();
    $gResult = $gStmt->get_result();
    while ($gm = $gResult->fetch_assoc()) {
        $galleryMedia[] = $gm;
    }
} else {
    $galleryMedia[] = ['media_id' => $media['media_id'], 'file_name' => $media['file_name'], 'mime_type' => $media['mime_type']];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'head.php'; ?>
    <title>Media Detail — Subjective Portal</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Inter',sans-serif; min-height:100vh;
            background:linear-gradient(-45deg,#FFF8F0,#EEF2FF,#F0FFF4,#FFF0F6);
            background-size:400% 400%; animation:bgShift 20s ease infinite;
            overflow-x:hidden;
        }
        @keyframes bgShift { 0%{background-position:0% 50%} 50%{background-position:100% 50%} 100%{background-position:0% 50%} }
        .cursor-glow {
            position:fixed; width:300px; height:300px; border-radius:50%;
            background:radial-gradient(circle,rgba(107,141,181,0.08) 0%,transparent 70%);
            pointer-events:none; transform:translate(-50%,-50%); z-index:1; transition:opacity 0.3s;
        }
        .page-orbs { position:fixed; inset:0; z-index:0; pointer-events:none; overflow:hidden; }
        .orb { position:absolute; border-radius:50%; filter:blur(90px); opacity:0.18; }
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

        /* Cards */
        .card {
            background:rgba(255,255,255,0.55); backdrop-filter:blur(20px);
            border:1px solid rgba(255,255,255,0.65); border-radius:22px;
            padding:26px; margin-bottom:22px;
            box-shadow:0 8px 32px rgba(0,0,0,0.04),inset 0 1px 0 rgba(255,255,255,0.85);
            animation:cardFadeIn 0.5s ease both;
        }
        @keyframes cardFadeIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
        .card h2 { font-size:16px; font-weight:800; color:#2D3748; margin-bottom:18px; display:flex; align-items:center; gap:9px; }
        .card h2 i { color:#6B8DB5; }

        /* Buttons */
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

        /* Detail layout */
        .detail-grid {
            display:grid; grid-template-columns:1fr 1fr; gap:24px;
        }
        @media(max-width:900px) { .detail-grid { grid-template-columns:1fr; } }

        /* Media preview card */
        .media-preview-card {
            background:rgba(255,255,255,0.6); border:1px solid rgba(255,255,255,0.65);
            border-radius:20px; overflow:hidden;
            box-shadow:0 8px 32px rgba(0,0,0,0.04);
        }
        .media-preview-img {
            width:100%; aspect-ratio:4/3; background:rgba(107,141,181,0.06);
            display:flex; align-items:center; justify-content:center; overflow:hidden;
        }
        .media-preview-img img {
            width:100%; height:100%; object-fit:contain; transition:transform 0.35s ease;
        }
        .media-preview-img:hover img { transform:scale(1.03); }
        .media-preview-img .file-placeholder {
            display:flex; flex-direction:column; align-items:center; gap:10px; color:#8BB3D9;
        }
        .media-preview-img .file-placeholder i { font-size:48px; }
        .media-preview-img .file-placeholder span { font-size:14px; font-weight:600; }
        .media-preview-footer {
            padding:14px 18px; border-top:1px solid rgba(107,141,181,0.1);
            display:flex; align-items:center; justify-content:space-between; gap:10px;
        }
        .media-file-name { font-size:13px; font-weight:600; color:#2D3748; word-break:break-word; }
        .media-download-btn {
            display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
            background:linear-gradient(135deg,#6B8DB5,#8BB3D9); color:#fff;
            border-radius:10px; font-size:12px; font-weight:600; text-decoration:none;
            transition:all 0.25s; white-space:nowrap;
        }
        .media-download-btn:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(107,141,181,0.3); }

        /* Meta info */
        .meta-block { margin-bottom:16px; }
        .meta-label {
            font-size:10px; font-weight:700; color:#A0AEC0; letter-spacing:1.2px;
            text-transform:uppercase; margin-bottom:4px;
        }
        .meta-value { font-size:15px; font-weight:600; color:#2D3748; }

        /* Row data table */
        .row-data-table { width:100%; border-collapse:separate; border-spacing:0; }
        .row-data-table th, .row-data-table td {
            padding:11px 16px; text-align:left; font-size:13px;
        }
        .row-data-table thead th {
            background:rgba(107,141,181,0.08); color:#4A5568; font-weight:700;
            font-size:11px; letter-spacing:0.8px; text-transform:uppercase;
            border-bottom:2px solid rgba(107,141,181,0.12);
        }
        .row-data-table thead th:first-child { border-radius:12px 0 0 0; }
        .row-data-table thead th:last-child { border-radius:0 12px 0 0; }
        .row-data-table tbody tr { transition:background 0.15s; }
        .row-data-table tbody tr:hover { background:rgba(107,141,181,0.05); }
        .row-data-table tbody td {
            border-bottom:1px solid rgba(107,141,181,0.07); color:#2D3748;
        }
        .row-data-table .field-name { font-weight:600; color:#4A5568; }
        .row-data-table .field-type {
            font-size:11px; padding:3px 8px; border-radius:6px;
            background:rgba(107,141,181,0.08); color:#6B8DB5; font-weight:600;
            display:inline-block;
        }
        .row-data-table .field-value { font-weight:600; color:#2D3748; }
        .row-data-table .field-value.empty-val { color:#CBD5E0; font-style:italic; font-weight:400; }

        /* Gallery strip */
        .gallery-strip {
            display:flex; gap:10px; overflow-x:auto; padding:4px 0 8px;
        }
        .gallery-strip::-webkit-scrollbar { height:6px; }
        .gallery-strip::-webkit-scrollbar-thumb { background:rgba(107,141,181,0.2); border-radius:3px; }
        .gallery-item {
            flex-shrink:0; width:90px; height:90px; border-radius:12px; overflow:hidden;
            border:2px solid rgba(107,141,181,0.15); cursor:pointer;
            transition:all 0.2s; position:relative;
        }
        .gallery-item:hover { border-color:rgba(107,141,181,0.4); transform:translateY(-2px); box-shadow:0 4px 14px rgba(107,141,181,0.15); }
        .gallery-item.active { border-color:#6B8DB5; box-shadow:0 0 0 3px rgba(107,141,181,0.2); }
        .gallery-item img { width:100%; height:100%; object-fit:cover; }
        .gallery-item .gallery-file-icon {
            width:100%; height:100%; display:flex; align-items:center; justify-content:center;
            background:rgba(107,141,181,0.06); color:#8BB3D9; font-size:24px;
        }

        /* No data placeholder */
        .no-data {
            text-align:center; padding:30px 20px; color:#A0AEC0; font-size:14px;
        }
        .no-data i { font-size:36px; display:block; margin-bottom:10px; opacity:0.4; }

        /* Responsive */
        @media(max-width:768px) {
            .sidebar-toggle { display:flex; align-items:center; justify-content:center; }
            .sidebar { position:fixed; top:0; left:0; height:100vh; transform:translateX(-100%); z-index:150; }
            .sidebar.open { transform:translateX(0); }
            .main-header { padding:16px 18px; }
            .main-body { padding:16px; }
        }
    </style>
</head>
<body>
    <?php include 'orbs.php'; ?>
    <div class="app-layout">
        <?php include 'navbar.php'; ?>
        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-photo-film"></i> Media Detail</h1>
                <div class="header-breadcrumb">
                    <i class="fas fa-home"></i>
                    <a href="viewer.php">Home</a> /
                    <a href="media_compare.php">Media Compare</a> /
                    Detail
                </div>
            </div>
            <div class="main-body">

                <!-- Project / Testing info -->
                <div class="card" style="animation-delay:0.05s;">
                    <h2><i class="fas fa-flask"></i> Testing Information</h2>
                    <div style="display:grid;grid-template-columns:1fr;gap:16px;">
                        <div class="meta-block">
                            <div class="meta-label">Project Title</div>
                            <div class="meta-value"><?= htmlspecialchars($media['project_title']) ?></div>
                        </div>
                        <div class="meta-block">
                            <div class="meta-label">Testing Name</div>
                            <div class="meta-value"><?= htmlspecialchars($media['testing_name']) ?></div>
                        </div>
                        <div class="meta-block">
                            <div class="meta-label">Testing Method</div>
                            <div class="meta-value"><?= htmlspecialchars($media['testing_method']) ?></div>
                        </div>
                        <?php if ($groupName): ?>
                        <div class="meta-block">
                            <div class="meta-label">Media Group</div>
                            <div class="meta-value"><?= htmlspecialchars($groupName) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-grid">
                    <!-- Left: Media preview -->
                    <div>
                        <div class="card" style="animation-delay:0.1s;">
                            <h2><i class="fas fa-image"></i> Media Preview</h2>
                            <div class="media-preview-card" id="mainPreviewCard">
                                <div class="media-preview-img" id="mainPreviewArea">
                                    <?php if ($isImage): ?>
                                        <img src="media.php?id=<?= $mediaId ?>" alt="<?= htmlspecialchars($media['file_name']) ?>" id="mainPreviewImg">
                                    <?php else: ?>
                                        <div class="file-placeholder">
                                            <i class="fas fa-file"></i>
                                            <span><?= htmlspecialchars($media['file_name']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="media-preview-footer">
                                    <span class="media-file-name" id="mainFileName"><?= htmlspecialchars($media['file_name']) ?></span>
                                    <a href="media.php?id=<?= $mediaId ?>" target="_blank" class="media-download-btn" id="mainDownloadBtn">
                                        <i class="fas fa-external-link-alt"></i> Open
                                    </a>
                                </div>
                            </div>

                            <?php if (count($galleryMedia) > 1): ?>
                            <div style="margin-top:14px;">
                                <div style="font-size:11px;font-weight:700;color:#A0AEC0;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:8px;">
                                    All files in this group (<?= count($galleryMedia) ?>)
                                </div>
                                <div class="gallery-strip">
                                    <?php foreach ($galleryMedia as $gm): ?>
                                    <div class="gallery-item <?= (int)$gm['media_id'] === $mediaId ? 'active' : '' ?>"
                                         onclick="switchPreview(<?= (int)$gm['media_id'] ?>, <?= str_starts_with($gm['mime_type'], 'image/') ? 'true' : 'false' ?>, this)"
                                         title="<?= htmlspecialchars($gm['file_name']) ?>">
                                        <?php if (str_starts_with($gm['mime_type'], 'image/')): ?>
                                            <img src="media.php?id=<?= (int)$gm['media_id'] ?>" alt="<?= htmlspecialchars($gm['file_name']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <div class="gallery-file-icon"><i class="fas fa-file"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:4px;">
                            <a href="media_compare.php?media_id=<?= $mediaId ?>" class="btn btn-secondary btn-sm">
                                <i class="fas fa-images"></i> Open in Compare
                            </a>
                        </div>
                    </div>

                    <!-- Right: Row data -->
                    <div>
                        <div class="card" style="animation-delay:0.15s;">
                            <h2><i class="fas fa-table-list"></i> Row Data<?= $rowNumber !== null ? ' — Row ' . $rowNumber : '' ?></h2>

                            <?php if (!empty($rowFields)): ?>
                            <table class="row-data-table">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>Type</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rowFields as $f): ?>
                                    <tr>
                                        <td class="field-name"><?= htmlspecialchars($f['field_name']) ?></td>
                                        <td><span class="field-type"><?= htmlspecialchars($f['value_type']) ?></span></td>
                                        <td>
                                            <?php if ($f['record_value'] !== null && $f['record_value'] !== ''): ?>
                                                <span class="field-value"><?= htmlspecialchars($f['record_value']) ?></span>
                                            <?php else: ?>
                                                <span class="field-value empty-val">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-table"></i>
                                <p>No record data linked to this media group.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    // Gallery preview switch
    function switchPreview(mediaId, isImage, el) {
        var area = document.getElementById('mainPreviewArea');
        var fnEl = document.getElementById('mainFileName');
        var dlBtn = document.getElementById('mainDownloadBtn');

        // Update active state
        document.querySelectorAll('.gallery-item').forEach(function(g) { g.classList.remove('active'); });
        el.classList.add('active');

        // Update preview
        if (isImage) {
            area.innerHTML = '<img src="media.php?id=' + mediaId + '" alt="Media" id="mainPreviewImg">';
        } else {
            area.innerHTML = '<div class="file-placeholder"><i class="fas fa-file"></i><span>File</span></div>';
        }

        // Update download link
        dlBtn.href = 'media.php?id=' + mediaId;

        // Update file name from title attribute
        var title = el.getAttribute('title') || 'File';
        fnEl.textContent = title;
    }

    // Cursor glow
    var glow = document.createElement('div');
    glow.className = 'cursor-glow';
    document.body.appendChild(glow);
    document.addEventListener('mousemove', function(e) {
        glow.style.left = e.clientX + 'px';
        glow.style.top = e.clientY + 'px';
    });
    </script>
</body>
</html>
