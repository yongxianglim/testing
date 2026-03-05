<?php
require 'auth.php';
requireRole(['VIEWER', 'EDITOR', 'DEVELOPER']);
require 'db.php';

$totalTests   = $conn->query("SELECT COUNT(*) AS c FROM testing")->fetch_assoc()['c'];
$totalFields  = $conn->query("SELECT COUNT(*) AS c FROM master_fields")->fetch_assoc()['c'];
$totalRecords = $conn->query("SELECT COUNT(DISTINCT CONCAT(testing_id,'-',row_number)) AS c FROM testing_record")->fetch_assoc()['c'];
$totalMedia   = $conn->query("SELECT COUNT(*) AS c FROM media_files")->fetch_assoc()['c'];

// Build full testing catalogue for client-side cascading (id not needed, just the 4 filter columns)
$allRows = [];
$res = $conn->query("SELECT project_name, project_title, testing_name, testing_method FROM testing ORDER BY project_name, project_title, testing_name, testing_method");
while ($r = $res->fetch_assoc()) $allRows[] = $r;
$catalogueJson = json_encode($allRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Current user role — used to control engineer-check display
$viewerRole = $_SESSION['role'] ?? 'VIEWER';

// Read dynamic filter sets from GET: filter[0][project_name] etc.
$filterSets = [];
if (!empty($_GET['filter']) && is_array($_GET['filter'])) {
    foreach ($_GET['filter'] as $idx => $fs) {
        if (!empty($fs['active'])) {
            $filterSets[] = [
                'project_name'   => $fs['project_name']   ?? '',
                'project_title'  => $fs['project_title']  ?? '',
                'testing_name'   => $fs['testing_name']   ?? '',
                'testing_method' => $fs['testing_method'] ?? '',
            ];
        }
    }
}

function getAvgClass($a)
{
    if ($a === null) return 'avg-na';
    if ($a >= 4)     return 'avg-high';
    if ($a >= 2.5)   return 'avg-mid';
    return 'avg-low';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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

        /* ── Dynamic filter sets ──────────────────────── */
        .filter-sets-wrapper {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 8px;
        }

        .filter-set-item {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 18px;
            padding: 18px 20px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
            animation: filterSetIn 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes filterSetIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .filter-set-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .filter-set-label {
            font-size: 13px;
            font-weight: 700;
            color: #4A5568;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-set-label i {
            color: #6B8DB5;
        }

        .btn-remove-set {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border: 1.5px solid rgba(208, 112, 112, 0.2);
            border-radius: 10px;
            background: rgba(208, 112, 112, 0.06);
            color: #D07070;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-remove-set:hover {
            background: rgba(208, 112, 112, 0.12);
            border-color: rgba(208, 112, 112, 0.35);
        }

        .filter-add-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
        }

        .filter-add-bar .divider-line {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(107, 141, 181, 0.15), transparent);
        }

        select.cascade-disabled {
            opacity: 0.48;
            pointer-events: none;
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

        /* ── Engineer check badge ─────────────────────── */
        .eng-check-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            flex-shrink: 0;
        }

        .eng-check-badge.verified {
            background: rgba(104, 168, 122, 0.13);
            color: #276749;
            border: 1px solid rgba(104, 168, 122, 0.2);
        }

        .eng-check-badge.unverified {
            background: rgba(160, 174, 192, 0.12);
            color: #718096;
            border: 1px solid rgba(160, 174, 192, 0.18);
        }

        /* ── Media ────────────────────────────────────── */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .media-thumb-square {
            width: 100%;
            aspect-ratio: 1/1;
            border-radius: 12px;
            overflow: hidden;
            background: rgba(107,141,181,0.06);
        }

        .media-thumb-square img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.3s ease;
        }

        .media-thumb-square:hover img {
            transform: scale(1.06);
        }

        /* ── Row hide button ─────────────────────────── */
        .btn-hide-row { background:none; border:none; color:#CBD5E0; cursor:pointer; padding:3px 6px; border-radius:6px; font-size:13px; transition:all 0.2s; }
        .btn-hide-row:hover { color:#718096; }
        tr.row-hidden { opacity:0.35; }
        tr.row-hidden td:not(:first-child) { color:transparent !important; user-select:none; }

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

        /* ── Live Search ──────────────────────────────── */
        .live-search-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1.5px solid rgba(107, 141, 181, 0.2);
            border-radius: 16px;
            padding: 10px 16px;
            margin-bottom: 18px;
            box-shadow: 0 2px 12px rgba(107, 141, 181, 0.07);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .live-search-bar:focus-within {
            border-color: rgba(107, 141, 181, 0.45);
            box-shadow: 0 0 0 3px rgba(107, 141, 181, 0.09), 0 4px 18px rgba(107, 141, 181, 0.1);
        }

        .live-search-bar i {
            color: #8BB3D9;
            font-size: 15px;
            flex-shrink: 0;
        }

        .live-search-bar input {
            flex: 1;
            border: none;
            background: transparent;
            outline: none;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: #2D3748;
            font-weight: 500;
        }

        .live-search-bar input::placeholder {
            color: #CBD5E0;
            font-weight: 400;
        }

        .live-search-clear {
            background: none;
            border: none;
            cursor: pointer;
            color: #A0AEC0;
            font-size: 14px;
            padding: 2px 6px;
            border-radius: 6px;
            transition: color 0.2s;
            display: none;
            flex-shrink: 0;
        }

        .live-search-clear:hover {
            color: #6B8DB5;
        }

        .live-search-count {
            font-size: 12px;
            color: #A0AEC0;
            white-space: nowrap;
            flex-shrink: 0;
            font-weight: 500;
        }

        .result-card.search-hidden {
            display: none !important;
        }

        /* ── Combobox filter (merged type + pick) ────── */
        .cb-wrap {
            position: relative;
        }

        .cb-field {
            display: flex;
            align-items: center;
            padding: 0;
            background: rgba(255, 255, 255, 0.6);
            border: 1.5px solid rgba(107, 141, 181, 0.15);
            border-radius: 11px;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
            cursor: text;
            overflow: hidden;
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

        /* the dropdown panel */
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

        .radar-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 10px;
        }

        .radar-card {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 18px;
            padding: 16px 18px 14px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05), inset 0 1px 0 rgba(255, 255, 255, 0.85);
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.25s, box-shadow 0.25s;
        }

        .radar-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(107, 141, 181, 0.14), inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }

        .radar-card-label {
            font-size: 11px;
            font-weight: 700;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .radar-card canvas {
            display: block;
            cursor: pointer;
        }

        /* ── Radar Lightbox ───────────────────────────── */
        #radarLightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9000;
            background: rgba(20, 28, 40, 0.72);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            animation: lbFadeIn 0.22s ease;
        }

        #radarLightbox.open {
            display: flex;
        }

        @keyframes lbFadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .lb-panel {
            background: #ffffff;
            border-radius: 24px;
            padding: 28px 28px 22px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.28);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            position: relative;
            animation: lbSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 92vw;
            max-height: 92vh;
        }

        @keyframes lbSlideUp {
            from {
                transform: translateY(24px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .lb-title {
            font-size: 15px;
            font-weight: 800;
            color: #2D3748;
            letter-spacing: -0.2px;
            text-align: center;
        }

        .lb-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 10px;
            background: rgba(107, 141, 181, 0.1);
            color: #6B8DB5;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .lb-close:hover {
            background: rgba(107, 141, 181, 0.2);
        }

        .lb-actions {
            display: flex;
            gap: 10px;
        }

        /* ── Section Toggle Bar ───────────────────────── */
        .section-toggle-bar {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 14px;
            padding: 10px 14px;
            background: rgba(107, 141, 181, 0.05);
            border: 1px solid rgba(107, 141, 181, 0.1);
            border-radius: 14px;
        }

        .section-toggle-bar span {
            font-size: 11px;
            font-weight: 700;
            color: #A0AEC0;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            margin-right: 4px;
        }

        .toggle-check {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #718096;
            background: rgba(255, 255, 255, 0.7);
            border: 1.5px solid rgba(107, 141, 181, 0.15);
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .toggle-check input[type=checkbox] {
            display: none;
        }

        .toggle-check .chk-box {
            width: 14px;
            height: 14px;
            border-radius: 4px;
            border: 2px solid rgba(107, 141, 181, 0.35);
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.18s;
        }

        .toggle-check .chk-box i {
            font-size: 8px;
            color: #fff;
            opacity: 0;
            transition: opacity 0.15s;
        }

        .toggle-check input[type=checkbox]:checked~.chk-box {
            background: linear-gradient(135deg, #6B8DB5, #8BB3D9);
            border-color: #6B8DB5;
        }

        .toggle-check input[type=checkbox]:checked~.chk-box i {
            opacity: 1;
        }

        .toggle-check:has(input:checked) {
            background: rgba(107, 141, 181, 0.1);
            border-color: rgba(107, 141, 181, 0.3);
            color: #4A7BA8;
        }

        /* ── Collapsible sections ─────────────────────── */
        .collapsible-section {
            overflow: hidden;
            transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.3s ease,
                margin 0.3s ease;
            max-height: 2000px;
            opacity: 1;
        }

        .collapsible-section.hidden {
            max-height: 0 !important;
            opacity: 0;
            margin-top: 0 !important;
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
    <div class="app-layout">
        <?php include 'navbar.php'; ?>
        <div class="main-content">
            <div class="main-header">
                <h1><i class="fas fa-eye"></i> View Records</h1>
                <div class="header-breadcrumb"><i class="fas fa-home"></i> <a href="viewer.php">Home</a> / View Records</div>
            </div>
            <div class="main-body">

                <div class="stats-bar">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-flask"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $totalTests ?>">0</div>
                            <div class="stat-label">Testing Records</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-table-columns"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $totalFields ?>">0</div>
                            <div class="stat-label">Master Fields</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-database"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $totalRecords ?>">0</div>
                            <div class="stat-label">Data Rows</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon amber"><i class="fas fa-images"></i></div>
                        <div class="stat-info">
                            <div class="stat-value" data-count="<?= $totalMedia ?>">0</div>
                            <div class="stat-label">Media Files</div>
                        </div>
                    </div>
                </div>

                <!-- Live search bar (client-side DOM filter, independent of server filter) -->
                <div class="live-search-bar">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="liveSearchInput" placeholder="Type to instantly filter result cards by Project Name, Title, Testing Name or Method…" oninput="liveFilter(this.value)">
                    <button type="button" class="live-search-clear" id="liveSearchClear" onclick="clearLiveSearch()" title="Clear search"><i class="fas fa-times"></i></button>
                    <span class="live-search-count" id="liveSearchCount"></span>
                </div>

                <!-- Dynamic filter sets container -->
                <form method="GET" id="filterForm">
                    <div class="filter-sets-wrapper" id="filterSetsWrapper">
                        <!-- JS will render filter set cards here -->
                    </div>
                    <div class="filter-add-bar">
                        <div class="divider-line"></div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addFilterSet()">
                            <i class="fas fa-plus"></i> Add Filter Set
                        </button>
                        <div class="divider-line"></div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                        <a href="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> Reset All</a>
                    </div>
                </form>

                <div class="section-divider"></div>

                <?php
                // Embed the full testing catalogue for JS cascading
                echo "<script>var TESTING_CATALOGUE = " . $catalogueJson . ";</script>";

                // Embed current active filter sets for JS to restore on page load
                $initSets = [];
                foreach ($filterSets as $i => $fs) {
                    $initSets[] = [
                        'project_name'   => $fs['project_name'],
                        'project_title'  => $fs['project_title'],
                        'testing_name'   => $fs['testing_name'],
                        'testing_method' => $fs['testing_method'],
                    ];
                }
                // If no sets from GET, start with one empty set
                if (empty($initSets)) $initSets[] = ['project_name' => '', 'project_title' => '', 'testing_name' => '', 'testing_method' => ''];
                echo "<script>var INIT_FILTER_SETS = " . json_encode($initSets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ";</script>";
                ?>

                <?php
                function runFilterSet($conn, $pn, $p, $n, $m)
                {
                    $w = [];
                    $pa = [];
                    $t = "";
                    if ($pn) {
                        $w[] = "t.project_name LIKE ?";
                        $pa[] = "%$pn%";
                        $t .= "s";
                    }
                    if ($p) {
                        $w[] = "t.project_title LIKE ?";
                        $pa[] = "%$p%";
                        $t .= "s";
                    }
                    if ($n) {
                        $w[] = "t.testing_name LIKE ?";
                        $pa[] = "%$n%";
                        $t .= "s";
                    }
                    if ($m) {
                        $w[] = "t.testing_method LIKE ?";
                        $pa[] = "%$m%";
                        $t .= "s";
                    }
                    $ws  = $w ? "WHERE " . implode(" AND ", $w) : "";
                    $sql = "SELECT t.testing_id,t.project_name,t.project_title,t.testing_name,t.testing_method,t.checked_by_engineer_t1,t.checked_by_engineer_t2,t.created_by,d.display_order,d.field_name,d.field_key,d.value_type,r.record_value,r.row_number,r.table_number,r.edited_by FROM testing t INNER JOIN field_definitions d ON d.testing_id=t.testing_id LEFT JOIN testing_record r ON r.testing_id=t.testing_id AND r.field_key=d.field_key $ws ORDER BY t.testing_id,r.table_number,r.row_number,d.display_order";
                    $stmt = $conn->prepare($sql);
                    if ($pa) $stmt->bind_param($t, ...$pa);
                    $stmt->execute();
                    return $stmt->get_result();
                }

                $setLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
                if (empty($filterSets)) {
                    // No filters submitted — show all
                    displayResults($conn, runFilterSet($conn, '', '', '', ''));
                } else {
                    foreach ($filterSets as $i => $fs) {
                        $label = $setLabels[$i] ?? ($i + 1);
                        echo "<h2 style='margin-bottom:12px;'><i class='fas fa-layer-group'></i> Result Set $label</h2>";
                        displayResults($conn, runFilterSet($conn, $fs['project_name'], $fs['project_title'], $fs['testing_name'], $fs['testing_method']));
                    }
                }

                function renderDataTable($entry, $rowsKey, $tO, $nO) {
                    $rows = $entry['rows'][$rowsKey] ?? [];
                    echo "<div class='overflow-x' style='margin-top:10px;'><table><thead><tr>";
                    echo "<th style='width:36px;'></th>";
                    foreach ($tO as $d) echo "<th>" . htmlspecialchars($entry['fields'][$d]['field_name']) . "</th>";
                    echo "<th style='text-align:center;'><i class='fas fa-calculator'></i> Average</th>";
                    foreach ($nO as $d) echo "<th>" . htmlspecialchars($entry['fields'][$d]['field_name']) . "</th>";
                    echo "</tr></thead><tbody>";
                    if (empty($rows)) {
                        $span = count($tO) + count($nO) + 2;
                        echo "<tr><td colspan='$span' style='text-align:center;color:#A0AEC0;padding:16px;'>No data</td></tr>";
                    } else {
                        foreach ($rows as $rn => $rd) {
                            $rowId = 'row_' . $rowsKey . '_' . $rn . '_' . uniqid();
                            echo "<tr data-row-key='$rowId'>";
                            echo "<td style='text-align:center;'><button type='button' class='btn-hide-row' title='Hide/Show row' onclick='toggleRow(this)'><i class='fas fa-eye'></i></button></td>";
                            foreach ($tO as $d) {
                                $v = $rd[$d] ?? null;
                                echo "<td>" . (($v === null || $v === '') ? '—' : htmlspecialchars($v)) . "</td>";
                            }
                            $nv = [];
                            foreach ($nO as $d) {
                                $v = $rd[$d] ?? null;
                                if ($v !== null && $v !== '') { $fv = (float)$v; if ($fv >= 0) $nv[] = $fv; }
                            }
                            if (count($nv) > 0) {
                                $avg = array_sum($nv) / count($nv);
                                $cls = getAvgClass($avg);
                                echo "<td class='avg-cell $cls'>" . number_format(max(0, $avg), 3) . "</td>";
                            } else echo "<td class='avg-cell avg-na'>N/A</td>";
                            foreach ($nO as $d) {
                                $v = $rd[$d] ?? null;
                                echo "<td>" . (($v === null || $v === '') ? '—' : htmlspecialchars($v)) . "</td>";
                            }
                            echo "</tr>";
                        }
                    }
                    echo "</tbody></table></div>";
                }

                function displayResults($conn, $result)
                {
                    static $renderSeq = 0; // global counter across all displayResults calls

                    $data = [];
                    while ($row = $result->fetch_assoc()) {
                        $tid = (int)$row['testing_id'];
                        if (!isset($data[$tid])) $data[$tid] = [
                            'meta' => [
                                'project_name'   => $row['project_name'],
                                'project_title'  => $row['project_title'],
                                'testing_name'   => $row['testing_name'],
                                'testing_method' => $row['testing_method'],
                                'checked_t1'     => (int)($row['checked_by_engineer_t1'] ?? 0),
                                'checked_t2'     => (int)($row['checked_by_engineer_t2'] ?? 0),
                                'created_by'     => $row['created_by'] ?? null,
                            ],
                            'fields' => [],
                            'rows'   => ['t1' => [], 't2' => []],
                            'edited_by' => ['t1' => null, 't2' => null],
                        ];
                        $d = (int)$row['display_order'];
                        if (!isset($data[$tid]['fields'][$d])) $data[$tid]['fields'][$d] = ['field_name' => $row['field_name'], 'field_key' => (int)$row['field_key'], 'value_type' => $row['value_type']];
                        $rn  = $row['row_number'];
                        $tbl = (int)($row['table_number'] ?? 1);
                        $tk  = $tbl === 2 ? 't2' : 't1';
                        if ($rn !== null) {
                            $rn = (int)$rn;
                            if (!isset($data[$tid]['rows'][$tk][$rn])) $data[$tid]['rows'][$tk][$rn] = [];
                            $data[$tid]['rows'][$tk][$rn][$d] = $row['record_value'];
                            // Track edited_by for this table (set once from any row)
                            if ($data[$tid]['edited_by'][$tk] === null && isset($row['edited_by'])) {
                                $data[$tid]['edited_by'][$tk] = $row['edited_by'];
                            }
                        }
                    }
                    if (empty($data)) {
                        echo "<div class='empty-state'><i class='fas fa-inbox'></i><p>No records found matching your filters.</p></div>";
                        return;
                    }

                    foreach ($data as $tid => $entry) {
                        $seq = $renderSeq++; // unique per rendered card, even if same tid
                        $meta = $entry['meta'];
                        ksort($entry['fields']);
                        ksort($entry['rows']['t1']);
                        ksort($entry['rows']['t2']);
                        $tO = [];
                        $nO = [];
                        foreach ($entry['fields'] as $d => $f) {
                            if ($f['value_type'] === 'text') $tO[] = $d;
                            else $nO[] = $d;
                        }

                        $cardPn = strtolower($meta['project_name']);
                        $cardPt = strtolower($meta['project_title']);
                        $cardTn = strtolower($meta['testing_name']);
                        $cardTm = strtolower($meta['testing_method']);
                        echo "<div class='card result-card' data-pn='" . htmlspecialchars($cardPn, ENT_QUOTES) . "' data-pt='" . htmlspecialchars($cardPt, ENT_QUOTES) . "' data-tn='" . htmlspecialchars($cardTn, ENT_QUOTES) . "' data-tm='" . htmlspecialchars($cardTm, ENT_QUOTES) . "'>";
                        echo "<div style='display:flex;align-items:center;gap:14px;margin-bottom:8px;'>";
                        echo "<div style='width:42px;height:42px;background:linear-gradient(135deg,#6B8DB5,#8BB3D9);border-radius:13px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;box-shadow:0 4px 16px rgba(107,141,181,0.2);flex-shrink:0;'><i class='fas fa-folder-open'></i></div>";
                        echo "<div style='flex:1;min-width:0;'>";
                        echo "<div style='display:flex;align-items:center;gap:10px;flex-wrap:wrap;'>";
                        echo "<h3 style='margin:0;font-size:16px;'>" . htmlspecialchars($meta['project_name']) . " — " . htmlspecialchars($meta['project_title']) . "</h3>";
                        // Engineer check badge
                        $engT1 = (int)($meta['checked_t1'] ?? 0);
                        $engT2 = (int)($meta['checked_t2'] ?? 0);
                        if ($engT1 || $engT2) {
                            $verifiedLabel = 'Engineer Verified';
                            if ($engT1 && $engT2) $verifiedLabel .= ' (T1+T2)';
                            elseif ($engT1) $verifiedLabel .= ' (T1)';
                            else $verifiedLabel .= ' (T2)';
                            echo "<span class='eng-check-badge verified'><i class='fas fa-user-check'></i> $verifiedLabel</span>";
                        } else {
                            echo "<span class='eng-check-badge unverified'><i class='fas fa-user-clock'></i> Not Verified</span>";
                        }
                        echo "</div>";
                        echo "<p class='text-muted-sm' style='margin:2px 0 0;display:flex;align-items:center;gap:6px;flex-wrap:wrap;'><span><i class='fas fa-tag' style='color:#6B8DB5;'></i> " . htmlspecialchars($meta['testing_name']) . "</span><span style='color:#CBD5E0;'>|</span><span><i class='fas fa-microscope' style='color:#C1A0D8;'></i> " . htmlspecialchars($meta['testing_method']) . "</span></p>";
                        echo "</div></div>";

                        // IDs for collapsible sections
                        $t1Id = "sec_t1_{$seq}";
                        $t2Id = "sec_t2_{$seq}";
                        $mId  = "sec_media_{$seq}";
                        $dId  = "sec_desc_{$seq}";
                        $r1Id = "sec_radar1_{$seq}";
                        $r2Id = "sec_radar2_{$seq}";
                        $hasT2 = !empty($entry['rows']['t2']);

                        // Table 1 section
                        $t1EditedBy = $entry['edited_by']['t1'];
                        $t1Label = 'Record Data — Table 1';
                        if ($t1EditedBy) $t1Label .= ' (edited by: ' . htmlspecialchars($t1EditedBy) . ')';
                        elseif ($entry['meta']['created_by']) $t1Label .= ' (created by: ' . htmlspecialchars($entry['meta']['created_by']) . ')';
                        echo "<div id='$t1Id' class='collapsible-section'>";
                        echo "<p style='margin-top:18px;font-weight:700;font-size:13px;color:#4A5568;display:flex;align-items:center;gap:8px;'><i class='fas fa-table' style='color:#6B8DB5;'></i> " . $t1Label . "</p>";
                        renderDataTable($entry, 't1', $tO, $nO);
                        echo "</div>";

                        // Table 2 section
                        echo "<div id='$t2Id' class='collapsible-section'" . ($hasT2 ? '' : " style='display:none;'") . ">";
                        if ($hasT2) {
                            $t2EditedBy = $entry['edited_by']['t2'];
                            $t2Label = 'Record Data — Table 2';
                            if ($t2EditedBy) $t2Label .= ' (edited by: ' . htmlspecialchars($t2EditedBy) . ')';
                            echo "<p style='margin-top:18px;font-weight:700;font-size:13px;color:#4A5568;display:flex;align-items:center;gap:8px;'><i class='fas fa-table' style='color:#C1A0D8;'></i> " . $t2Label . "</p>";
                            renderDataTable($entry, 't2', $tO, $nO);
                        }
                        echo "</div>";

                        // Section visibility toggle bar
                        echo "<div class='section-toggle-bar'>";
                        echo "<span><i class='fas fa-eye' style='margin-right:4px;'></i> Show</span>";
                        echo "<label class='toggle-check'><input type='checkbox' checked onchange=\"toggleSection('$t1Id',this.checked)\"><div class='chk-box'><i class='fas fa-check'></i></div><i class='fas fa-table' style='font-size:11px;margin-right:2px;color:#6B8DB5;'></i> Table 1</label>";
                        if ($hasT2) {
                            echo "<label class='toggle-check'><input type='checkbox' checked onchange=\"toggleSection('$t2Id',this.checked)\"><div class='chk-box'><i class='fas fa-check'></i></div><i class='fas fa-table' style='font-size:11px;margin-right:2px;color:#C1A0D8;'></i> Table 2</label>";
                        }
                        echo "<label class='toggle-check'><input type='checkbox' checked onchange=\"toggleSection('$mId',this.checked)\"><div class='chk-box'><i class='fas fa-check'></i></div><i class='fas fa-images' style='font-size:11px;margin-right:2px;'></i> Media</label>";
                        echo "<label class='toggle-check'><input type='checkbox' checked onchange=\"toggleSection('$dId',this.checked)\"><div class='chk-box'><i class='fas fa-check'></i></div><i class='fas fa-align-left' style='font-size:11px;margin-right:2px;'></i> Description</label>";
                        echo "<label class='toggle-check'><input type='checkbox' checked onchange=\"toggleSection('$r1Id',this.checked)\"><div class='chk-box'><i class='fas fa-check'></i></div><i class='fas fa-chart-area' style='font-size:11px;margin-right:2px;color:#6B8DB5;'></i> Radar T1</label>";
                        if ($hasT2) {
                            echo "<label class='toggle-check'><input type='checkbox' checked onchange=\"toggleSection('$r2Id',this.checked)\"><div class='chk-box'><i class='fas fa-check'></i></div><i class='fas fa-chart-area' style='font-size:11px;margin-right:2px;color:#C1A0D8;'></i> Radar T2</label>";
                        }
                        echo "</div>";

                        echo "<div id='$mId' class='collapsible-section'>";
                        displayMedia($conn, $tid);
                        echo "</div>";

                        echo "<div id='$dId' class='collapsible-section'>";
                        displayDescriptions($conn, $tid);
                        echo "</div>";

                        echo "<div id='$r1Id' class='collapsible-section'>";
                        displayRadarCharts($seq . '_t1', $entry['fields'], $entry['rows']['t1'], $nO, 'Performance Radar (Table 1)', '#6B8DB5');
                        echo "</div>";

                        echo "<div id='$r2Id' class='collapsible-section'" . ($hasT2 ? '' : " style='display:none;'") . ">";
                        if ($hasT2) {
                            displayRadarCharts($seq . '_t2', $entry['fields'], $entry['rows']['t2'], $nO, 'Performance Radar (Table 2)', '#C1A0D8');
                        }
                        echo "</div>";

                        echo "</div>";
                    }
                }

                function displayMedia($conn, $tid)
                {
                    $s = $conn->prepare("SELECT media_id, file_name, mime_type, group_name FROM media_files WHERE testing_id=? ORDER BY group_name, created_at");
                    $s->bind_param("i", $tid);
                    $s->execute();
                    $r = $s->get_result();
                    if ($r->num_rows === 0) return;

                    // Group by group_name (use 'Ungrouped' for NULL)
                    $groups = [];
                    while ($m = $r->fetch_assoc()) {
                        $gname = $m['group_name'] ?? 'Ungrouped';
                        if (!isset($groups[$gname])) $groups[$gname] = [];
                        $groups[$gname][] = $m;
                    }

                    foreach ($groups as $gname => $files) {
                        echo "<p style='margin-top:18px;font-weight:700;font-size:13px;color:#4A5568;display:flex;align-items:center;gap:8px;'><i class='fas fa-layer-group' style='color:#6B8DB5;'></i> " . htmlspecialchars($gname) . "</p>";
                        echo "<div class='media-grid'>";
                        foreach ($files as $m) {
                            $url = "media.php?id=" . (int)$m['media_id'];
                            $fn = htmlspecialchars($m['file_name']);
                            $mt = $m['mime_type'] ?? '';
                            if (str_starts_with($mt, "image/")) {
                                $compareUrl = "media_compare.php?media_id=" . (int)$m['media_id'];
                                echo "<a href='$compareUrl'><div class='media-thumb-square'><img src='$url' alt='$fn'></div></a>";
                            } else {
                                echo "<a href='$url' target='_blank' class='btn btn-sm btn-secondary'><i class='fas fa-file'></i> $fn</a>";
                            }
                        }
                        echo "</div>";
                    }
                }

                function displayDescriptions($conn, $tid)
                {
                    $s = $conn->prepare("SELECT content FROM testing_description WHERE testing_id=? ORDER BY created_at");
                    $s->bind_param("i", $tid);
                    $s->execute();
                    $r = $s->get_result();
                    if ($r->num_rows === 0) return;
                    echo "<p style='margin-top:18px;font-weight:700;font-size:13px;color:#4A5568;display:flex;align-items:center;gap:8px;'><i class='fas fa-align-left' style='color:#C1A0D8;'></i> Description</p>";
                    while ($d = $r->fetch_assoc()) echo "<div class='desc-card'><pre style='margin:0;border:none;background:none;padding:0;'>" . htmlspecialchars($d['content']) . "</pre></div>";
                }

                function displayRadarCharts($seq, $fields, $rows, $nO, $title = 'Performance Radar', $color = '#6B8DB5')
                {
                    if (empty($rows) || empty($nO)) return;

                    // Build axis labels from the numeric field definitions
                    $labels = [];
                    foreach ($nO as $d) {
                        $labels[] = $fields[$d]['field_name'];
                    }

                    // Find global max across all rows for auto-scale
                    $globalMax = 0;
                    foreach ($rows as $rd) {
                        foreach ($nO as $d) {
                            $v = $rd[$d] ?? null;
                            if ($v !== null && $v !== '') {
                                $fv = (float)$v;
                                if ($fv > $globalMax) $globalMax = $fv;
                            }
                        }
                    }
                    // Round up to nearest 0.5 for a clean scale
                    $scale = $globalMax > 0 ? ceil($globalMax * 2) / 2 : 5;

                    if ($color === '#C1A0D8') {
                        $bgColor = 'rgba(193,160,216,0.15)'; $borderColor = 'rgba(193,160,216,0.75)'; $pointColor = 'rgba(193,160,216,1)';
                    } else {
                        $bgColor = 'rgba(107,141,181,0.15)'; $borderColor = 'rgba(107,141,181,0.75)'; $pointColor = 'rgba(107,141,181,1)';
                    }
                    echo "<p style='margin-top:18px;font-weight:700;font-size:13px;color:#4A5568;display:flex;align-items:center;gap:8px;'>"
                        . "<i class='fas fa-chart-area' style='color:" . htmlspecialchars($color) . ";'></i> " . htmlspecialchars($title) . "</p>";
                    echo "<div class='radar-grid'>";

                    foreach ($rows as $rn => $rd) {
                        // Collect data values for this row
                        $values = [];
                        $hasData = false;
                        foreach ($nO as $d) {
                            $v = $rd[$d] ?? null;
                            if ($v !== null && $v !== '') {
                                $fv = (float)$v;
                                $values[] = $fv;
                                $hasData = true;
                            } else {
                                $values[] = 0;
                            }
                        }
                        if (!$hasData) continue;

                        // Build a row label from text fields if available
                        $rowLabel = "Row $rn";
                        foreach ($fields as $d => $f) {
                            if ($f['value_type'] === 'text' && isset($rd[$d]) && $rd[$d] !== '') {
                                $rowLabel = htmlspecialchars($rd[$d]);
                                break;
                            }
                        }

                        $chartId = "radar_s{$seq}_r{$rn}";
                        $labelsJson = json_encode($labels);
                        $valuesJson = json_encode($values);

                        echo "<div class='radar-card' onclick='openRadarLightbox(this)'>";
                        echo "<div class='radar-card-label'>" . $rowLabel . "</div>";
                        echo "<canvas id='$chartId' width='200' height='200'"
                            . " data-labels='" . htmlspecialchars($labelsJson, ENT_QUOTES) . "'"
                            . " data-values='" . htmlspecialchars($valuesJson, ENT_QUOTES) . "'"
                            . " data-scale='$scale'"
                            . " data-label='" . htmlspecialchars($rowLabel, ENT_QUOTES) . "'"
                            . " data-stepsize='" . ($scale <= 5 ? 1 : ($scale <= 10 ? 2 : 5)) . "'"
                            . "></canvas>";
                        echo "<script>
(function(){
    var ctx = document.getElementById(" . json_encode($chartId) . ").getContext('2d');
    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: $labelsJson,
            datasets: [{
                data: $valuesJson,
                backgroundColor: '$bgColor',
                borderColor: '$borderColor',
                borderWidth: 2,
                pointBackgroundColor: '$pointColor',
                pointBorderColor: '#fff',
                pointBorderWidth: 1.5,
                pointRadius: 3.5,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: 'rgba(193,160,216,1)',
                fill: true
            }]
        },
        options: {
            responsive: false,
            animation: { duration: 600, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(255,255,255,0.92)',
                    titleColor: '#2D3748',
                    bodyColor: '#4A5568',
                    borderColor: 'rgba(107,141,181,0.2)',
                    borderWidth: 1,
                    padding: 10,
                    bodyFont: { family: 'Inter', size: 12 },
                    titleFont: { family: 'Inter', size: 12, weight: '700' },
                    callbacks: {
                        title: function(items) { return items[0].label; },
                        label: function(item) { return ' ' + item.raw; }
                    }
                }
            },
            scales: {
                r: {
                    min: 0,
                    max: $scale,
                    beginAtZero: true,
                    ticks: {
                        stepSize: " . ($scale <= 5 ? 1 : ($scale <= 10 ? 2 : 5)) . ",
                        font: { family: 'Inter', size: 9 },
                        color: '#A0AEC0',
                        backdropColor: 'transparent',
                        z: 1
                    },
                    grid: {
                        color: 'rgba(107,141,181,0.12)'
                    },
                    angleLines: {
                        color: 'rgba(107,141,181,0.15)'
                    },
                    pointLabels: {
                        font: { family: 'Inter', size: 9.5, weight: '600' },
                        color: '#718096',
                        padding: 6
                    }
                }
            }
        }
    });
})();
</script>";
                        echo "</div>";
                    }
                    echo "</div>";
                }
                $conn->close();
                ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>

    <!-- Radar Lightbox overlay -->
    <div id="radarLightbox">
        <div class="lb-panel">
            <button class="lb-close" onclick="closeRadarLightbox()" title="Close"><i class="fas fa-times"></i></button>
            <div class="lb-title" id="lbTitle"></div>
            <canvas id="lbCanvas" width="520" height="520"></canvas>
            <div class="lb-actions">
                <button class="btn btn-primary btn-sm" onclick="saveRadarChart()"><i class="fas fa-download"></i> Save as PNG</button>
                <button class="btn btn-secondary btn-sm" onclick="closeRadarLightbox()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>

    <script>
        // ── Vanilla Tilt ──────────────────────────────
        VanillaTilt.init(document.querySelectorAll('.stat-card'), {
            max: 8,
            speed: 400,
            glare: true,
            'max-glare': 0.08,
            scale: 1.02
        });

        // ── Section toggle ────────────────────────────
        function toggleSection(id, show) {
            var el = document.getElementById(id);
            if (!el) return;
            if (show) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        }

        function toggleRow(btn) {
            var tr = btn.closest('tr');
            if (!tr) return;
            tr.classList.toggle('row-hidden');
            var icon = btn.querySelector('i');
            if (icon) {
                icon.className = tr.classList.contains('row-hidden') ? 'fas fa-eye-slash' : 'fas fa-eye';
            }
            btn.title = tr.classList.contains('row-hidden') ? 'Show row' : 'Hide row';
        }

        // ── Radar Lightbox ────────────────────────────
        var _lbChart = null;

        function openRadarLightbox(cardEl) {
            var canvas = cardEl.querySelector('canvas');
            if (!canvas) return;

            var labels = JSON.parse(canvas.dataset.labels);
            var values = JSON.parse(canvas.dataset.values);
            var scale = parseFloat(canvas.dataset.scale);
            var label = canvas.dataset.label;
            var stepSize = parseFloat(canvas.dataset.stepsize);

            document.getElementById('lbTitle').textContent = label;

            var lb = document.getElementById('radarLightbox');
            lb.classList.add('open');
            document.body.style.overflow = 'hidden';

            // Destroy previous chart instance
            if (_lbChart) {
                _lbChart.destroy();
                _lbChart = null;
            }

            var lbCanvas = document.getElementById('lbCanvas');
            var ctx = lbCanvas.getContext('2d');

            _lbChart = new Chart(ctx, {
                type: 'radar',
                plugins: [{
                    id: 'whiteBg',
                    beforeDraw: function(chart) {
                        var c = chart.ctx;
                        c.save();
                        c.fillStyle = '#ffffff';
                        c.fillRect(0, 0, chart.width, chart.height);
                        c.restore();
                    }
                }],
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: 'rgba(107,141,181,0.15)',
                        borderColor: 'rgba(107,141,181,0.75)',
                        borderWidth: 2.5,
                        pointBackgroundColor: 'rgba(107,141,181,1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: 'rgba(193,160,216,1)',
                        fill: true
                    }]
                },
                options: {
                    responsive: false,
                    animation: {
                        duration: 500,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(255,255,255,0.95)',
                            titleColor: '#2D3748',
                            bodyColor: '#4A5568',
                            borderColor: 'rgba(107,141,181,0.25)',
                            borderWidth: 1,
                            padding: 12,
                            bodyFont: {
                                family: 'Inter',
                                size: 13
                            },
                            titleFont: {
                                family: 'Inter',
                                size: 13,
                                weight: '700'
                            },
                            callbacks: {
                                title: function(items) {
                                    return items[0].label;
                                },
                                label: function(item) {
                                    return '  ' + item.raw;
                                }
                            }
                        }
                    },
                    scales: {
                        r: {
                            min: 0,
                            max: scale,
                            beginAtZero: true,
                            ticks: {
                                stepSize: stepSize,
                                font: {
                                    family: 'Inter',
                                    size: 11
                                },
                                color: '#A0AEC0',
                                backdropColor: 'transparent',
                                z: 1
                            },
                            grid: {
                                color: 'rgba(107,141,181,0.15)'
                            },
                            angleLines: {
                                color: 'rgba(107,141,181,0.18)'
                            },
                            pointLabels: {
                                font: {
                                    family: 'Inter',
                                    size: 12,
                                    weight: '600'
                                },
                                color: '#4A5568',
                                padding: 8
                            }
                        }
                    }
                }
            });
        }

        function closeRadarLightbox() {
            document.getElementById('radarLightbox').classList.remove('open');
            document.body.style.overflow = '';
        }

        function saveRadarChart() {
            if (!_lbChart) return;
            var lbCanvas = document.getElementById('lbCanvas');
            var title = document.getElementById('lbTitle').textContent || 'radar';
            var safe = title.replace(/[^a-z0-9_\-]/gi, '_');

            // Draw white background then export
            lbCanvas.toBlob(function(blob) {
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'radar_' + safe + '.png';
                a.click();
                URL.revokeObjectURL(url);
            }, 'image/png');
        }

        // Close lightbox on backdrop click
        document.getElementById('radarLightbox').addEventListener('click', function(e) {
            if (e.target === this) closeRadarLightbox();
        });

        // ── Cascading Filter Sets ─────────────────────
        var _setCounter = 0;
        var SET_LABELS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

        function getCascadeOptions(field, constraints) {
            var seen = {},
                opts = [];
            TESTING_CATALOGUE.forEach(function(row) {
                for (var k in constraints) {
                    if (constraints[k] && row[k] !== constraints[k]) return;
                }
                var v = row[field];
                if (v && !seen[v]) {
                    seen[v] = true;
                    opts.push(v);
                }
            });
            opts.sort();
            return opts;
        }

        // ── makeCombobox ──────────────────────────────
        // Returns an object: { el, getValue, setValue, setOptions }
        // formInputName : the name= for the hidden <input> that PHP reads
        // initOpts      : string[] of option values
        // initVal       : pre-selected value ('' = All)
        // onPick        : callback(value) fired when user commits a selection
        function makeCombobox(formInputName, initOpts, initVal, onPick) {
            var pool = initOpts.slice(); // full option list
            var chosen = initVal || ''; // committed value
            var cursor = -1; // keyboard-highlighted index

            // ── DOM ──────────────────────────────────
            var wrap = document.createElement('div');
            wrap.className = 'cb-wrap';

            // hidden input — what the GET form submits
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = formInputName;
            hidden.value = chosen;
            wrap.appendChild(hidden);

            // visible field (input + buttons)
            var field = document.createElement('div');
            field.className = 'cb-field';

            var textEl = document.createElement('input');
            textEl.type = 'text';
            textEl.className = 'cb-input' + (chosen ? ' cb-selected' : '');
            textEl.value = chosen;
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

            // ── Helpers ──────────────────────────────
            function isOpen() {
                return wrap.classList.contains('cb-open');
            }

            function openDrop(filterTerm) {
                wrap.classList.add('cb-open');
                renderDrop(filterTerm !== undefined ? filterTerm : '');
                // scroll chosen row into view
                var sel = drop.querySelector('.cb-opt.cb-chosen');
                if (sel) sel.scrollIntoView({
                    block: 'nearest'
                });
            }

            function closeDrop(restoreText) {
                wrap.classList.remove('cb-open');
                cursor = -1;
                if (restoreText) {
                    textEl.value = chosen;
                    textEl.className = 'cb-input' + (chosen ? ' cb-selected' : '');
                }
            }

            function commit(val) {
                chosen = val;
                hidden.value = val;
                textEl.value = val;
                textEl.className = 'cb-input' + (val ? ' cb-selected' : '');
                btnClear.style.display = val ? 'inline-block' : 'none';
                closeDrop(false);
                if (onPick) onPick(val);
            }

            function esc(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function renderDrop(filterTerm) {
                drop.innerHTML = '';
                cursor = -1;
                var term = (filterTerm || '').trim().toLowerCase();

                // "— All —" row always first
                var allRow = document.createElement('div');
                allRow.className = 'cb-opt cb-all-row' + (chosen === '' ? ' cb-chosen' : '');
                allRow.dataset.v = '';
                allRow.textContent = '— All —';
                drop.appendChild(allRow);

                var hits = 0;
                pool.forEach(function(v) {
                    if (term && v.toLowerCase().indexOf(term) === -1) return;
                    var row = document.createElement('div');
                    row.className = 'cb-opt' + (v === chosen ? ' cb-chosen' : '');
                    row.dataset.v = v;
                    if (term) {
                        var lo = v.toLowerCase(),
                            si = lo.indexOf(term);
                        row.innerHTML = esc(v.slice(0, si)) +
                            '<mark>' + esc(v.slice(si, si + term.length)) + '</mark>' +
                            esc(v.slice(si + term.length));
                    } else {
                        row.textContent = v;
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
                opts.forEach(function(o) {
                    o.classList.remove('cb-cursor');
                });
                cursor = Math.max(0, Math.min(opts.length - 1, cursor + dir));
                opts[cursor].classList.add('cb-cursor');
                opts[cursor].scrollIntoView({
                    block: 'nearest'
                });
            }

            // ── Events ───────────────────────────────
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
                        // auto-select when only one real option remains
                        var real = opts.filter(function(o) {
                            return o.dataset.v !== '';
                        });
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

            // click inside dropdown
            drop.addEventListener('mousedown', function(e) {
                var opt = e.target.closest('.cb-opt[data-v]');
                if (opt) {
                    e.preventDefault();
                    commit(opt.dataset.v);
                }
            });

            // chevron toggle
            btnArrow.addEventListener('mousedown', function(e) {
                e.preventDefault();
                if (isOpen()) {
                    closeDrop(true);
                    textEl.blur();
                } else {
                    textEl.focus();
                }
            });

            // clear button
            btnClear.addEventListener('mousedown', function(e) {
                e.preventDefault();
                commit('');
                textEl.focus();
            });

            // ── Public API ───────────────────────────
            return {
                el: wrap,
                getValue: function() {
                    return chosen;
                },
                setValue: function(val) {
                    commit(val);
                },
                setOptions: function(newOpts) {
                    pool = newOpts;
                    // If current selection no longer exists, reset
                    if (chosen && newOpts.indexOf(chosen) === -1) commit('');
                }
            };
        }

        // ── refreshCascade — reads from stored _cb refs ───
        function refreshCascade(setEl) {
            var pnCb = setEl._cbs && setEl._cbs['project_name'];
            var ptCb = setEl._cbs && setEl._cbs['project_title'];
            var tnCb = setEl._cbs && setEl._cbs['testing_name'];
            var tmCb = setEl._cbs && setEl._cbs['testing_method'];

            var pn = pnCb ? pnCb.getValue() : '';

            var ptOpts = getCascadeOptions('project_title', {
                project_name: pn
            });
            if (ptCb) ptCb.setOptions(ptOpts);
            var pt = ptCb ? ptCb.getValue() : '';

            var tnOpts = getCascadeOptions('testing_name', {
                project_name: pn,
                project_title: pt
            });
            if (tnCb) tnCb.setOptions(tnOpts);
            var tn = tnCb ? tnCb.getValue() : '';

            var tmOpts = getCascadeOptions('testing_method', {
                project_name: pn,
                project_title: pt,
                testing_name: tn
            });
            if (tmCb) tmCb.setOptions(tmOpts);
        }

        function createFilterSetEl(idx, vals) {
            vals = vals || {};
            var label = SET_LABELS[idx] || ('Set ' + (idx + 1));

            var wrapper = document.createElement('div');
            wrapper.className = 'filter-set-item';
            wrapper.dataset.setIdx = idx;
            wrapper._cbs = {}; // store combobox refs keyed by field

            // hidden active flag
            var activeInput = document.createElement('input');
            activeInput.type = 'hidden';
            activeInput.name = 'filter[' + idx + '][active]';
            activeInput.value = '1';
            wrapper.appendChild(activeInput);

            // header
            var header = document.createElement('div');
            header.className = 'filter-set-header';
            var lbl = document.createElement('div');
            lbl.className = 'filter-set-label';
            lbl.innerHTML = '<i class="fas fa-filter"></i> Filter Set ' + label;
            header.appendChild(lbl);
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-set';
            removeBtn.innerHTML = '<i class="fas fa-times"></i> Remove';
            removeBtn.onclick = function() {
                removeFilterSet(wrapper);
            };
            header.appendChild(removeBtn);
            wrapper.appendChild(header);

            var row = document.createElement('div');
            row.className = 'filter-row';

            var fields = [{
                    key: 'project_name',
                    label: 'Project Name'
                },
                {
                    key: 'project_title',
                    label: 'Project Title'
                },
                {
                    key: 'testing_name',
                    label: 'Testing Name'
                },
                {
                    key: 'testing_method',
                    label: 'Testing Method'
                },
            ];

            fields.forEach(function(f) {
                var grp = document.createElement('div');
                grp.className = 'filter-group';
                var lbl2 = document.createElement('label');
                lbl2.textContent = f.label;
                grp.appendChild(lbl2);

                var constraints = {};
                if (f.key === 'project_title')
                    constraints = {
                        project_name: vals.project_name || ''
                    };
                if (f.key === 'testing_name')
                    constraints = {
                        project_name: vals.project_name || '',
                        project_title: vals.project_title || ''
                    };
                if (f.key === 'testing_method')
                    constraints = {
                        project_name: vals.project_name || '',
                        project_title: vals.project_title || '',
                        testing_name: vals.testing_name || ''
                    };

                var opts = getCascadeOptions(f.key, constraints);
                var cb = makeCombobox(
                    'filter[' + idx + '][' + f.key + ']',
                    opts,
                    vals[f.key] || '',
                    function() {
                        refreshCascade(wrapper);
                    }
                );

                wrapper._cbs[f.key] = cb; // store ref for refreshCascade
                grp.appendChild(cb.el);
                row.appendChild(grp);
            });

            wrapper.appendChild(row);
            return wrapper;
        }

        function addFilterSet(vals) {
            var wrapper = document.getElementById('filterSetsWrapper');
            var idx = _setCounter++;
            var el = createFilterSetEl(idx, vals || {});
            wrapper.appendChild(el);
            updateRemoveButtons();
        }

        function removeFilterSet(el) {
            el.style.transition = 'opacity 0.2s, transform 0.2s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            setTimeout(function() {
                el.remove();
                renumberSets();
                updateRemoveButtons();
            }, 220);
        }

        function renumberSets() {
            var sets = document.querySelectorAll('#filterSetsWrapper .filter-set-item');
            sets.forEach(function(setEl, newIdx) {
                setEl.dataset.setIdx = newIdx;
                var label = SET_LABELS[newIdx] || ('Set ' + (newIdx + 1));
                var lbl = setEl.querySelector('.filter-set-label');
                if (lbl) lbl.innerHTML = '<i class="fas fa-filter"></i> Filter Set ' + label;

                // rename all inputs (hidden active + all combobox hidden inputs)
                setEl.querySelectorAll('input[name]').forEach(function(inp) {
                    inp.name = inp.name.replace(/filter\[\d+\]/, 'filter[' + newIdx + ']');
                });
            });
            _setCounter = sets.length;
        }

        function updateRemoveButtons() {
            var sets = document.querySelectorAll('#filterSetsWrapper .filter-set-item');
            sets.forEach(function(setEl) {
                var btn = setEl.querySelector('.btn-remove-set');
                if (btn) btn.style.display = sets.length > 1 ? '' : 'none';
            });
        }

        // Close open comboboxes when clicking elsewhere on the page
        document.addEventListener('mousedown', function(e) {
            document.querySelectorAll('.cb-wrap.cb-open').forEach(function(w) {
                if (!w.contains(e.target)) w.classList.remove('cb-open');
            });
        });

        // ── Init filter sets on page load ─────────────
        document.addEventListener('DOMContentLoaded', function() {
            INIT_FILTER_SETS.forEach(function(vals) {
                addFilterSet(vals);
            });
        });

        // ── Live Search (global DOM filter) ───────────
        function liveFilter(term) {
            term = term.trim().toLowerCase();
            var cards = document.querySelectorAll('.result-card');
            var visible = 0;
            var total = cards.length;
            var clearBtn = document.getElementById('liveSearchClear');
            var countEl = document.getElementById('liveSearchCount');

            clearBtn.style.display = term ? 'block' : 'none';

            cards.forEach(function(card) {
                if (!term) {
                    card.classList.remove('search-hidden');
                    visible++;
                    return;
                }
                var pn = card.dataset.pn || '';
                var pt = card.dataset.pt || '';
                var tn = card.dataset.tn || '';
                var tm = card.dataset.tm || '';
                var match = pn.indexOf(term) !== -1 ||
                    pt.indexOf(term) !== -1 ||
                    tn.indexOf(term) !== -1 ||
                    tm.indexOf(term) !== -1;
                if (match) {
                    card.classList.remove('search-hidden');
                    visible++;
                } else {
                    card.classList.add('search-hidden');
                }
            });

            if (term && total > 0) {
                countEl.textContent = visible + ' / ' + total + ' shown';
            } else {
                countEl.textContent = '';
            }
        }

        function clearLiveSearch() {
            var inp = document.getElementById('liveSearchInput');
            inp.value = '';
            liveFilter('');
            inp.focus();
        }

        // ── Close on Escape key ───────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeRadarLightbox();
        });
    </script>
    <?php include 'modal.php'; ?>
</body>

</html>