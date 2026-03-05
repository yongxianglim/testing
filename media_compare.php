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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Media Compare — Subjective Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
            color:#CBD5E0; font-size:13px; cursor:pointer;
        }
        .slot-no-image i { font-size:32px; }
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
    <canvas id="particles-bg" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none;"></canvas>
    <div class="page-orbs">
        <div class="orb orb-1"></div><div class="orb orb-2"></div>
        <div class="orb orb-3"></div><div class="orb orb-4"></div><div class="orb orb-5"></div>
    </div>
    <script>
    (function(){
        var c=document.getElementById('particles-bg'),ctx=c.getContext('2d');
        c.width=window.innerWidth;c.height=window.innerHeight;
        var pts=[];
        for(var i=0;i<50;i++) pts.push({x:Math.random()*c.width,y:Math.random()*c.height,vx:(Math.random()-0.5)*0.2,vy:(Math.random()-0.5)*0.2,r:Math.random()*1.5+0.5,o:Math.random()*0.25+0.05});
        function draw(){
            ctx.clearRect(0,0,c.width,c.height);
            for(var i=0;i<pts.length;i++){var p=pts[i];p.x+=p.vx;p.y+=p.vy;if(p.x<0)p.x=c.width;if(p.x>c.width)p.x=0;if(p.y<0)p.y=c.height;if(p.y>c.height)p.y=0;ctx.beginPath();ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fillStyle='rgba(107,141,181,'+p.o+')';ctx.fill();for(var j=i+1;j<pts.length;j++){var q=pts[j],dx=p.x-q.x,dy=p.y-q.y,d=Math.sqrt(dx*dx+dy*dy);if(d<150){ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(q.x,q.y);ctx.strokeStyle='rgba(107,141,181,'+(0.04*(1-d/150))+')';ctx.stroke();}}}
            requestAnimationFrame(draw);
        }
        draw();
        window.addEventListener('resize',function(){c.width=window.innerWidth;c.height=window.innerHeight;});
    })();
    </script>
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
                            <div class="slot-image-area" id="slotImg<?= $s ?>" onclick="triggerSlotPicker(<?= $s ?>)">
                                <div class="slot-no-image"><i class="fas fa-image"></i><span>No media selected</span></div>
                            </div>
                            <div class="slot-info" id="slotInfo<?= $s ?>">
                                <div style="font-size:12px;color:#CBD5E0;font-style:italic;">Select media below</div>
                            </div>
                            <div class="slot-picker-area">
                                <div class="media-search-wrap">
                                    <i class="fas fa-magnifying-glass media-search-icon"></i>
                                    <input type="text" class="media-search-input" id="slotSearch<?= $s ?>"
                                        placeholder="Search media by name or group…"
                                        oninput="handleSearchInput(<?= $s ?>, this.value)"
                                        onfocus="openSearchDrop(<?= $s ?>)"
                                        onblur="closeSearchDrop(<?= $s ?>, 200)"
                                        autocomplete="off" spellcheck="false">
                                    <div class="media-search-drop" id="slotDrop<?= $s ?>"></div>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
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
            $rn  = is_numeric($gn) ? (int)$gn : null; // group_name == row_number mapping

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

    function triggerSlotPicker(slot) {
        if (slotMedia[slot]) return; // only trigger when no media is selected
        var inp = document.getElementById('slotSearch' + slot);
        if (inp) { inp.focus(); }
    }

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
            imgArea.innerHTML = '<div class="slot-no-image"><i class="fas fa-image"></i><span>No media selected</span></div>';
            infoArea.innerHTML = '<div style="font-size:12px;color:#CBD5E0;font-style:italic;">Select media below</div>';
            slotEl.classList.remove('active');
            return;
        }

        var m = findMedia(mid);
        if (!m) return;
        slotEl.classList.add('active');

        if (m.is_image) {
            imgArea.innerHTML = '<a href="media.php?id=' + mid + '" target="_blank">' +
                '<img src="media.php?id=' + mid + '" alt="' + escHtml(m.file_name) + '">' +
                '</a>';
        } else {
            imgArea.innerHTML = '<div class="slot-no-image"><i class="fas fa-file" style="color:#6B8DB5;"></i>' +
                '<a href="media.php?id=' + mid + '" target="_blank" class="btn btn-sm btn-secondary" style="margin-top:8px;">' +
                '<i class="fas fa-download"></i> ' + escHtml(m.file_name) + '</a></div>';
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
