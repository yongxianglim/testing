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

        /* ── Orbs & Particles ─────────────────────────── */
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

        /* Scroll reveal */
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

        /* ── Filter Section ───────────────────────────── */
        .filter-section {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 22px;
            padding: 22px 26px;
            margin-bottom: 16px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.04);
        }

        .filter-section h3 {
            font-size: 14px;
            font-weight: 700;
            color: #4A5568;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-section h3 i {
            color: #6B8DB5;
        }

        .filter-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .section-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(107, 141, 181, 0.15), transparent);
            margin: 8px 0 24px;
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

        .btn-magnetic {
            transition: transform 0.2s ease;
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
        input[type=password]:focus,
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

        .scrollable-list {
            max-height: 220px;
            overflow-y: auto;
            border: 1.5px solid rgba(107, 141, 181, 0.12);
            border-radius: 13px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.4);
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .scrollable-list label {
            font-size: 13px;
            font-weight: 500;
            color: #4A5568;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
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

        tbody td input[type=text] {
            width: 100%;
            min-width: 90px;
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
    <title>Manage Fields — Subjective Portal</title>
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