<?php
/**
 * Panel Sterowania Kotłownią - PI0 (10.0.0.118)
 *
 * Obsługiwane urządzenia:
 * - Piec Gazowy CWU (GPIO526 / pin 14)
 * - Piec Gazowy CO  (GPIO527 / pin 15)
 * - Pompa obiegowa  (GPIO530 / pin 18)
 */

require_once __DIR__ . '/config.php';

// =============================================================================
// Obsługa API (JSON) oraz zapytań AJAX / REST / Legacy
// =============================================================================

// 1. Odpytanie o aktualny status urządzeń (?api=status)
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $statuses = getAllDevicesStatus();
    $activeCount = 0;
    foreach ($statuses as $s) {
        if (!empty($s['is_on'])) {
            $activeCount++;
        }
    }
    echo json_encode([
        'success'      => true,
        'timestamp'    => time(),
        'active_count' => $activeCount,
        'total_count'  => count($statuses),
        'devices'      => $statuses
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Obsługa akcji POST / GET (AJAX lub curl)
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || isset($_POST['ajax']) || isset($_GET['ajax']);

$targetDevice = $_POST['device'] ?? $_POST['relay'] ?? $_GET['device'] ?? $_GET['relay'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Obsługa legacy sw1, sw2, sw3...
if (!$targetDevice && !$action) {
    if (isset($_GET['sw1'])) {
        $targetDevice = 'piec_cwu';
        $action = $_GET['sw1'];
    } elseif (isset($_GET['sw2'])) {
        $targetDevice = 'piec_co';
        $action = $_GET['sw2'];
    } elseif (isset($_GET['sw3'])) {
        $targetDevice = 'pompa';
        $action = $_GET['sw3'];
    } elseif (isset($_GET['sw100'])) {
        $targetDevice = 'ALL';
        $action = $_GET['sw100'];
    }
}

if ($targetDevice && $action) {
    if ($targetDevice === 'ALL' || $targetDevice === 'all') {
        $result = setAllDevicesState($action);
    } else {
        $result = setDeviceState($targetDevice, $action);
    }

    if ($isAjax || isset($_GET['format']) && $_GET['format'] === 'json' || isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Jeśli zwykłe wywołanie z formularza GET/POST - przekieruj na czysty adres
    header('Location: index.php?msg=' . urlencode($result['message'] ?? 'Wykonano'));
    exit;
}

$devicesStatus = getAllDevicesStatus();
$activeCount = 0;
foreach ($devicesStatus as $s) {
    if (!empty($s['is_on'])) $activeCount++;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sterowanie Kotłownią • PI0</title>
    <style>
        :root {
            --bg-body: #0b0f19;
            --bg-card: #111827;
            --bg-card-hover: #182234;
            --border-color: #1f2937;
            --border-color-light: #374151;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --danger: #ef4444;
            --danger-glow: rgba(239, 68, 68, 0.25);
            --warning: #f59e0b;
            --warning-glow: rgba(245, 158, 11, 0.25);
            --info: #06b6d4;
            --text-main: #f9fafb;
            --text-muted: #94a3b8;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            padding: 16px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .container {
            width: 100%;
            max-width: 900px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Pasek linków zewnętrznych / serwerów domowych */
        .server-links {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-bottom: 2px;
            flex-wrap: wrap;
        }

        .server-link {
            font-size: 0.76rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }

        .server-link:hover {
            color: #ffffff;
            background: rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        .server-link.active-host {
            color: #93c5fd;
            border-color: rgba(59, 130, 246, 0.4);
            font-weight: 600;
        }

        /* Pasek nawigacji między serwerami */
        .server-nav {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: var(--bg-card);
            padding: 10px 16px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .server-nav-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .server-nav-label svg {
            width: 16px;
            height: 16px;
            stroke: #94a3b8;
            fill: none;
            stroke-width: 2;
        }

        .server-nav-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            flex: 1;
        }

        .server-nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: var(--radius-md);
            background: #182234;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .server-nav-link:hover {
            background: #23334d;
            border-color: var(--primary);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .server-nav-link svg {
            width: 15px;
            height: 15px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            flex-shrink: 0;
        }

        .server-nav-link .badge-ip {
            background: rgba(255, 255, 255, 0.07);
            color: var(--text-muted);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .server-nav-link:hover .badge-ip {
            color: #93c5fd;
            background: rgba(59, 130, 246, 0.15);
        }

        /* Header */
        header {
            background: var(--bg-card);
            padding: 20px 24px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .header-title h1 {
            font-size: 1.45rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.02em;
        }

        .header-title h1 svg {
            stroke: #f97316;
            flex-shrink: 0;
        }

        .header-title p {
            color: var(--text-muted);
            font-size: 0.86rem;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-global {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: #1e293b;
            color: var(--text-main);
            transition: all 0.2s ease;
        }

        .btn-global:hover {
            background: #334155;
            transform: translateY(-1px);
        }

        .btn-global.danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }

        .btn-global.danger:hover {
            background: var(--danger);
            border-color: var(--danger);
            color: #ffffff;
        }

        .btn-global.success {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.4);
            color: #6ee7b7;
        }

        .btn-global.success:hover {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
        }

        /* Pasek statusu podsumowania */
        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 10px 18px;
            font-size: 0.84rem;
            color: var(--text-muted);
            box-shadow: var(--shadow);
            flex-wrap: wrap;
            gap: 8px;
        }

        .status-summary-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #64748b;
            margin-right: 6px;
        }

        .status-summary-dot.active {
            background: var(--success);
            box-shadow: 0 0 8px var(--success);
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.15); }
        }

        /* Lista kart urządzeń */
        .devices-grid {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .device-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            box-shadow: var(--shadow);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .device-card:hover {
            border-color: var(--border-color-light);
            background: var(--bg-card-hover);
        }

        .device-card.is-active {
            border-color: rgba(16, 185, 129, 0.4);
            box-shadow: 0 4px 20px -2px var(--success-glow);
        }

        .device-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #334155;
            transition: background 0.3s ease;
        }

        .device-card.is-active::before {
            background: var(--success);
            box-shadow: 0 0 10px var(--success);
        }

        .device-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            min-width: 0;
        }

        .device-icon-box {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .device-card.is-active .device-icon-box {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.35);
        }

        .device-icon-box svg {
            width: 26px;
            height: 26px;
            stroke-width: 2;
            fill: none;
            transition: transform 0.3s ease;
        }

        .device-card.is-active .device-icon-box svg {
            transform: scale(1.08);
        }

        .device-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .device-name-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .device-name {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.01em;
        }

        .device-badge-gpio {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-muted);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .device-desc {
            font-size: 0.84rem;
            color: var(--text-muted);
        }

        /* Status badge */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 9999px;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            transition: all 0.25s ease;
        }

        .status-pill.badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-pill.badge-secondary {
            background: rgba(148, 163, 184, 0.1);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .status-pill.badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-pill-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-pill.badge-success .status-pill-dot {
            animation: pulse-dot 2s infinite;
        }

        /* Action buttons */
        .device-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 96px;
            padding: 10px 16px;
            border-radius: var(--radius-md);
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s ease;
            user-select: none;
        }

        .btn-action svg {
            width: 16px;
            height: 16px;
            stroke-width: 2.2;
            fill: none;
        }

        .btn-on {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.35);
            color: #6ee7b7;
        }

        .btn-on:hover:not(:disabled) {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
            box-shadow: 0 4px 12px var(--success-glow);
            transform: translateY(-1px);
        }

        .btn-on.is-active-btn {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
            box-shadow: 0 0 12px var(--success-glow);
        }

        .btn-off {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .btn-off:hover:not(:disabled) {
            background: var(--danger);
            border-color: var(--danger);
            color: #ffffff;
            box-shadow: 0 4px 12px var(--danger-glow);
            transform: translateY(-1px);
        }

        .btn-off.is-active-btn {
            background: #1e293b;
            border-color: #334155;
            color: #94a3b8;
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Spinner */
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: currentColor;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Toast notifications */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 9999;
            pointer-events: none;
        }

        .toast {
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #334155;
            padding: 12px 18px;
            border-radius: var(--radius-md);
            font-size: 0.88rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 10px;
            pointer-events: auto;
            transform: translateY(20px);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            border-left: 4px solid var(--success);
        }

        .toast.danger {
            border-left: 4px solid var(--danger);
        }

        /* Responsive */
        @media (max-width: 640px) {
            .device-card {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }

            .device-actions {
                justify-content: flex-end;
                width: 100%;
            }

            .btn-action {
                flex: 1;
            }

            header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <!-- Pasek linków zewnętrznych / serwerów domowych -->
    <div class="server-links">
        <span class="server-link active-host">PI0 (Kotłownia: Piec CWU / Piec CO / Pompa) • 10.0.0.118</span>
        <a href="http://10.0.0.4/" class="server-link">PI1 (Rolety: Salon / Piwnica / Dach / Bramy) &rarr;</a>
        <a href="http://10.0.0.5/" class="server-link">PI2 (Rolety: Piętro 2 / Parter) &rarr;</a>
        <a href="http://10.0.0.2/" class="server-link">Cubie (Dom / Piece) &rarr;</a>
        <a href="wentylacja.php" class="server-link">Wentylacja (2x) &rarr;</a>
    </div>

    <!-- Belka przełączania serwerów -->
    <nav class="server-nav">
        <div class="server-nav-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <span>Serwery:</span>
        </div>
        <div class="server-nav-links">
            <a href="http://10.0.0.4/" class="server-nav-link" title="Przejdź do sterowania roletami na serwerze PI1">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
                <span>PI1: Rolety Salon / Piwnica / Bramy</span>
                <span class="badge-ip">10.0.0.4</span>
            </a>
            <a href="http://10.0.0.5/" class="server-nav-link" title="Przejdź do sterowania roletami na serwerze PI2">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>PI2: Rolety Piętro 2 / Parter</span>
                <span class="badge-ip">10.0.0.5</span>
            </a>
            <a href="http://10.0.0.2/" class="server-nav-link" title="Przejdź do sterowania piecem, CWU i pompami na serwerze Cubie">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Cubie: Kotłownia</span>
                <span class="badge-ip">10.0.0.2</span>
            </a>
            <a href="wentylacja.php" class="server-nav-link" title="Przejdź do sterowania wentylacją (10.0.0.8, 10.0.0.9)">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                <span>Wentylacja (2x)</span>
                <span class="badge-ip">10.0.0.8 &bull; 10.0.0.9</span>
            </a>
        </div>
    </nav>

    <!-- Header -->
    <header>
        <div class="header-title">
            <h1>
                <svg width="28" height="28" viewBox="0 0 24 24"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                Sterowanie Kotłownią • PI0
            </h1>
            <p>Piec Gazowy CWU &bull; Piec Gazowy CO &bull; Pompa obiegowa &bull; Serwer PI0 (10.0.0.118)</p>
        </div>
        <div class="header-actions">
            <button type="button" class="btn-global danger" onclick="triggerAction('ALL', 'off')" title="Wyłącz wszystkie przekaźniki">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6"/></svg>
                Wyłącz wszystko
            </button>
            <button type="button" class="btn-global success" onclick="triggerAction('ALL', 'on')" title="Włącz wszystkie urządzenia">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                Włącz wszystko
            </button>
            <button type="button" class="btn-global" onclick="refreshStatus(true)" title="Odśwież status natychmiast">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Odśwież
            </button>
        </div>
    </header>

    <!-- Pasek statusu podsumowania -->
    <div class="status-bar">
        <div>
            <span class="status-summary-dot <?= $activeCount > 0 ? 'active' : '' ?>" id="summaryDot"></span>
            <span id="summaryText">Aktywne obwody: <strong><?= $activeCount ?></strong> z <?= count($DEVICES) ?></span>
        </div>
        <div style="font-family: monospace; font-size: 0.78rem;" id="lastUpdateText">
            Aktualizacja: <?= date('H:i:s') ?>
        </div>
    </div>

    <!-- Lista urządzeń -->
    <div class="devices-grid">
        <?php foreach ($DEVICES as $id => $dev):
            $st = $devicesStatus[$id] ?? ['is_on' => false, 'label' => 'WYŁĄCZONY', 'badge' => 'badge-secondary'];
            $isOn = !empty($st['is_on']);
        ?>
        <div class="device-card <?= $isOn ? 'is-active' : '' ?>" id="card-<?= htmlspecialchars($id) ?>">
            <div class="device-info">
                <div class="device-icon-box" style="color: <?= htmlspecialchars($dev['accent']) ?>;">
                    <?php if ($dev['icon'] === 'flame_water'): ?>
                        <!-- Ikona Płomień + Woda CWU -->
                        <svg viewBox="0 0 24 24" stroke="currentColor">
                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                        </svg>
                    <?php elseif ($dev['icon'] === 'flame'): ?>
                        <!-- Ikona Płomień CO -->
                        <svg viewBox="0 0 24 24" stroke="currentColor">
                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                        </svg>
                    <?php else: ?>
                        <!-- Ikona Pompa Obiegowa -->
                        <svg viewBox="0 0 24 24" stroke="currentColor">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3 3"/>
                            <path d="M7 12a5 5 0 0 1 8.54-3.54"/>
                            <path d="M17 12a5 5 0 0 1-8.54 3.54"/>
                        </svg>
                    <?php endif; ?>
                </div>

                <div class="device-text">
                    <div class="device-name-row">
                        <span class="device-name"><?= htmlspecialchars($dev['name']) ?></span>
                        <span class="device-badge-gpio">GPIO <?= htmlspecialchars($dev['gpio']) ?> (pin <?= htmlspecialchars($dev['pin']) ?>)</span>
                        <span class="status-pill <?= $st['badge'] ?>" id="badge-<?= htmlspecialchars($id) ?>">
                            <span class="status-pill-dot"></span>
                            <span class="status-pill-label"><?= htmlspecialchars($st['label']) ?></span>
                        </span>
                    </div>
                    <div class="device-desc"><?= htmlspecialchars($dev['desc']) ?></div>
                </div>
            </div>

            <div class="device-actions">
                <button type="button" 
                        class="btn-action btn-on <?= $isOn ? 'is-active-btn' : '' ?>" 
                        id="btn-on-<?= htmlspecialchars($id) ?>"
                        onclick="triggerAction('<?= htmlspecialchars($id) ?>', 'on')"
                        title="Włącz <?= htmlspecialchars($dev['name']) ?>">
                    <svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    <span>Włącz</span>
                </button>

                <button type="button" 
                        class="btn-action btn-off <?= !$isOn ? 'is-active-btn' : '' ?>" 
                        id="btn-off-<?= htmlspecialchars($id) ?>"
                        onclick="triggerAction('<?= htmlspecialchars($id) ?>', 'off')"
                        title="Wyłącz <?= htmlspecialchars($dev['name']) ?>">
                    <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                    <span>Wyłącz</span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    // Flaga blokująca nakładanie się zapytań o status
    let isPolling = false;
    let pollTimer = null;

    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const iconSvg = type === 'success' 
            ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
            : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            
        toast.innerHTML = `${iconSvg}<span>${message}</span>`;
        container.appendChild(toast);

        requestAnimationFrame(() => toast.classList.add('show'));

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }

    // Aktualizacja widoku pojedynczego urządzenia w DOM
    function updateDeviceDOM(deviceId, info) {
        const card = document.getElementById(`card-${deviceId}`);
        const badge = document.getElementById(`badge-${deviceId}`);
        const btnOn = document.getElementById(`btn-on-${deviceId}`);
        const btnOff = document.getElementById(`btn-off-${deviceId}`);

        if (!card || !badge) return;

        const isOn = !!info.is_on;

        // Karta
        if (isOn) {
            card.classList.add('is-active');
        } else {
            card.classList.remove('is-active');
        }

        // Plakietka statusu
        badge.className = `status-pill ${info.badge || (isOn ? 'badge-success' : 'badge-secondary')}`;
        const labelSpan = badge.querySelector('.status-pill-label');
        if (labelSpan) {
            labelSpan.textContent = info.label || (isOn ? 'WŁĄCZONY' : 'WYŁĄCZONY');
        }

        // Przyciski
        if (btnOn) {
            btnOn.disabled = false;
            btnOn.innerHTML = `<svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg><span>Włącz</span>`;
            if (isOn) {
                btnOn.classList.add('is-active-btn');
            } else {
                btnOn.classList.remove('is-active-btn');
            }
        }

        if (btnOff) {
            btnOff.disabled = false;
            btnOff.innerHTML = `<svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg><span>Wyłącz</span>`;
            if (!isOn) {
                btnOff.classList.add('is-active-btn');
            } else {
                btnOff.classList.remove('is-active-btn');
            }
        }
    }

    // Natychmiastowa reakcja interfejsu (Optimistic UI: 0 ms)
    function applyOptimisticAction(deviceId, action) {
        if (deviceId === 'ALL') {
            const allDevices = ['piec_cwu', 'piec_co', 'pompa'];
            allDevices.forEach(d => applyOptimisticAction(d, action));
            return;
        }

        const card = document.getElementById(`card-${deviceId}`);
        const badge = document.getElementById(`badge-${deviceId}`);
        const btnOn = document.getElementById(`btn-on-${deviceId}`);
        const btnOff = document.getElementById(`btn-off-${deviceId}`);

        const isActivating = (action === 'on' || action === '1' || action === 'start');

        if (card) {
            if (isActivating) card.classList.add('is-active');
            else card.classList.remove('is-active');
        }

        if (badge) {
            badge.className = `status-pill ${isActivating ? 'badge-success' : 'badge-secondary'}`;
            const labelSpan = badge.querySelector('.status-pill-label');
            if (labelSpan) {
                labelSpan.textContent = isActivating ? 'WŁĄCZANIE...' : 'WYŁĄCZANIE...';
            }
        }

        if (isActivating && btnOn) {
            btnOn.disabled = true;
            btnOn.innerHTML = `<span class="spinner"></span><span>Włączam...</span>`;
        } else if (!isActivating && btnOff) {
            btnOff.disabled = true;
            btnOff.innerHTML = `<span class="spinner"></span><span>Wyłączam...</span>`;
        }
    }

    // Wywołanie akcji przełączenia stanu (POST AJAX)
    async function triggerAction(deviceId, action) {
        // Natychmiastowy feedback Optimistic UI
        applyOptimisticAction(deviceId, action);

        try {
            const formData = new URLSearchParams();
            formData.append('device', deviceId);
            formData.append('action', action);
            formData.append('ajax', '1');

            const res = await fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData.toString()
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();
            
            if (data.success) {
                showToast(data.message || 'Zaktualizowano stan');
                if (deviceId === 'ALL' && data.devices) {
                    for (const [id, devResult] of Object.entries(data.devices)) {
                        updateDeviceDOM(id, devResult);
                    }
                } else {
                    updateDeviceDOM(deviceId, data);
                }
            } else {
                showToast(data.error || 'Wystąpił błąd', 'danger');
            }
        } catch (err) {
            console.error('Błąd zapytania POST:', err);
            showToast('Błąd połączenia z serwerem: ' + err.message, 'danger');
        } finally {
            // Weryfikacja ze stanem faktycznym
            setTimeout(() => refreshStatus(false), 400);
        }
    }

    // Cykliczne odpytywanie o stan urządzeń
    async function refreshStatus(showFeedback = false) {
        if (isPolling) return;
        isPolling = true;

        try {
            const res = await fetch('index.php?api=status', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) return;

            const data = await res.json();
            if (data && data.devices) {
                for (const [id, info] of Object.entries(data.devices)) {
                    updateDeviceDOM(id, info);
                }

                // Aktualizacja podsumowania
                const summaryDot = document.getElementById('summaryDot');
                const summaryText = document.getElementById('summaryText');
                const lastUpdateText = document.getElementById('lastUpdateText');

                if (summaryDot) {
                    if (data.active_count > 0) summaryDot.classList.add('active');
                    else summaryDot.classList.remove('active');
                }

                if (summaryText) {
                    summaryText.innerHTML = `Aktywne obwody: <strong>${data.active_count}</strong> z ${data.total_count}`;
                }

                if (lastUpdateText) {
                    const now = new Date();
                    lastUpdateText.textContent = `Aktualizacja: ${now.toLocaleTimeString()}`;
                }

                if (showFeedback) {
                    showToast('Zaktualizowano stany urządzeń');
                }
            }
        } catch (e) {
            console.warn('Polling błąd:', e);
        } finally {
            isPolling = false;
        }
    }

    // Uruchomienie cyklicznego odpytywania co 2.5 sekundy
    document.addEventListener('DOMContentLoaded', () => {
        pollTimer = setInterval(() => refreshStatus(false), 2500);
    });
</script>

</body>
</html>
