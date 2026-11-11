<?php
/**
 * Panel Sterowania Kotłownią - PI0 (10.0.0.2)
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

// 1. Odpytanie o temperatury z czujników 1-Wire DS18B20 (?api=temperatures lub ?api=temp)
if (isset($_GET['api']) && in_array(strtolower($_GET['api']), ['temperatures', 'temp', 'temps'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode(getAllTemperatures(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Odpytanie o aktualny status urządzeń i temperatury (?api=status)
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $statuses = getAllDevicesStatus();
    $temperatures = getAllTemperatures();
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
        'devices'      => $statuses,
        'temperatures' => $temperatures
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
$temperaturesData = getAllTemperatures();
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

        .server-nav-link.active {
            background: rgba(59, 130, 246, 0.15);
            border-color: #3b82f6;
            color: #93c5fd;
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

        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            vertical-align: middle;
            line-height: 1.2;
        }

        .btn-global {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            vertical-align: middle;
            line-height: 1.2;
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

        .btn-global span,
        .btn-action span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            line-height: 1.2;
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

        .device-card.is-poweroff {
            border-color: rgba(239, 68, 68, 0.35);
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

        .device-card.is-poweroff::before {
            background: var(--danger);
            box-shadow: 0 0 8px rgba(239, 68, 68, 0.6);
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
            justify-content: center;
            gap: 8px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            vertical-align: middle;
            line-height: 1.2;
            gap: 7px;
            min-width: 125px;
            padding: 10px 16px;
            border-radius: var(--radius-md);
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.2s ease;
            user-select: none;
        }

        .btn-action svg {
            width: 16px;
            height: 16px;
            stroke-width: 2.2;
            fill: none;
            flex-shrink: 0;
        }

        /* Styl klawisza sterowania piecem CWU / CO:
           - stan nieaktywny (brak pracy / wyłączony): niebieski przycisk "Włącz" (jak na Grzegorzka 8)
           - stan aktywny (praca / włączony): czerwony przycisk "Wyłącz" (jak na Grzegorzka 8)
        */
        .btn-toggle {
            background: #2563eb;
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.15);
        }

        .btn-toggle:hover:not(:disabled) {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }

        .btn-toggle.is-active-btn {
            background: #ef4444;
            border-color: #f87171;
            color: #ffffff;
            box-shadow: 0 0 14px rgba(239, 68, 68, 0.5);
        }

        .btn-toggle.is-active-btn:hover:not(:disabled) {
            background: #dc2626;
        }

        /* Styl klawisza Zasilanie Wyłącz (odcięcie 230V z potwierdzeniem) */
        .btn-poweroff {
            background: rgba(220, 38, 38, 0.2);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.45);
        }

        .btn-poweroff:hover:not(:disabled) {
            background: #dc2626;
            color: #ffffff;
            border-color: #ef4444;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
            transform: translateY(-1px);
        }

        .btn-poweroff.is-active-btn {
            background: #991b1b;
            color: #fecaca;
            border-color: #f87171;
            box-shadow: 0 0 14px rgba(220, 38, 38, 0.6);
        }

        /* Włącz: Niebieski (jak na Grzegorzka 8) */
        .btn-on {
            background: #2563eb;
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.15);
        }

        .btn-on:hover:not(:disabled) {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }

        .btn-on.is-active-btn {
            background: #1d4ed8;
            border-color: #60a5fa;
            box-shadow: 0 0 14px rgba(37, 99, 235, 0.6);
        }

        /* Wyłącz: Czerwony (jak na Grzegorzka 8) */
        .btn-off {
            background: #ef4444;
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.15);
        }

        .btn-off:hover:not(:disabled) {
            background: #dc2626;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
            transform: translateY(-1px);
        }

        .btn-off.is-active-btn {
            background: #dc2626;
            border-color: #f87171;
            box-shadow: 0 0 14px rgba(239, 68, 68, 0.6);
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

        /* Sekcje i nagłówki sekcji */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 22px 0 12px 0;
            padding: 0 4px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.08rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.01em;
        }

        .section-title svg {
            color: var(--primary);
            stroke-width: 2.2;
            flex-shrink: 0;
        }

        .section-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            flex-wrap: wrap;
        }

        .badge-tech {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-muted);
            border: 1px solid rgba(255, 255, 255, 0.09);
            padding: 3px 8px;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge-active-sensors {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(16, 185, 129, 0.1);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: 600;
        }

        .sensor-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #64748b;
        }

        .sensor-dot.online {
            background: #10b981;
            box-shadow: 0 0 6px #10b981;
            animation: pulse-dot 2s infinite;
        }

        /* Siatka 3 grup temperatur: CO, Gaz, CWU */
        .temp-groups-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 8px;
        }

        .temp-group-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            overflow: hidden;
            transition: all 0.25s ease;
        }

        .temp-group-card:hover {
            border-color: var(--border-color-light);
            background: var(--bg-card-hover);
        }

        .temp-group-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #334155;
            transition: background 0.3s ease;
        }

        .temp-group-card.group-co::before {
            background: #f97316;
            box-shadow: 0 0 10px rgba(249, 115, 22, 0.5);
        }

        .temp-group-card.group-gaz::before {
            background: #ef4444;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
        }

        .temp-group-card.group-cwu::before {
            background: #06b6d4;
            box-shadow: 0 0 10px rgba(6, 182, 212, 0.5);
        }

        .temp-group-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .temp-group-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .temp-group-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .temp-group-icon svg {
            width: 20px;
            height: 20px;
            stroke-width: 2;
            fill: none;
        }

        .group-co .temp-group-icon {
            color: #f97316;
            background: rgba(249, 115, 22, 0.12);
            border-color: rgba(249, 115, 22, 0.28);
        }

        .group-gaz .temp-group-icon {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.28);
        }

        .group-cwu .temp-group-icon {
            color: #06b6d4;
            background: rgba(6, 182, 212, 0.12);
            border-color: rgba(6, 182, 212, 0.28);
        }

        .temp-group-titles {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 2px;
        }

        .temp-group-name {
            font-size: 0.98rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .temp-group-desc {
            font-size: 0.74rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .delta-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 9999px;
            font-size: 0.76rem;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            letter-spacing: 0.02em;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .badge-cwu-tag {
            background: rgba(6, 182, 212, 0.15);
            color: #67e8f9;
            border-color: rgba(6, 182, 212, 0.3);
        }

        /* Wewnętrzne kafelki czujników */
        .temp-sub-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .temp-sub-grid.single-sensor {
            grid-template-columns: 1fr;
        }

        .temp-item {
            background: #0d1424;
            border: 1px solid rgba(51, 65, 85, 0.65);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 86px;
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .temp-item:hover {
            background: #111a2f;
            border-color: rgba(100, 116, 139, 0.8);
            transform: translateY(-1px);
        }

        .temp-item-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
        }

        .temp-item-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--text-muted);
        }

        .temp-item-label svg {
            width: 13px;
            height: 13px;
            stroke-width: 2.4;
            flex-shrink: 0;
        }

        .temp-item.item-supply .temp-item-label {
            color: #fca5a5;
        }

        .temp-item.item-return .temp-item-label {
            color: #93c5fd;
        }

        .temp-item.item-tank .temp-item-label {
            color: #67e8f9;
        }

        .sensor-id-pill {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.65rem;
            color: #64748b;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
        }

        .temp-val {
            font-size: 1.65rem;
            font-weight: 700;
            color: #ffffff;
            margin: 4px 0 2px 0;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            letter-spacing: -0.02em;
            line-height: 1.1;
            display: flex;
            align-items: baseline;
            gap: 4px;
        }

        .temp-val small {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        .temp-val.temp-supply {
            color: #f87171;
        }

        .temp-val.temp-return {
            color: #60a5fa;
        }

        .temp-val.temp-cwu {
            color: #22d3ee;
        }

        .temp-val.temp-error {
            color: #94a3b8;
            opacity: 0.7;
        }

        .temp-item-sub {
            font-size: 0.68rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (max-width: 980px) {
            .temp-groups-grid {
                grid-template-columns: 1fr;
            }
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
    <!-- Belka przełączania paneli -->
    <nav class="server-nav">
        <div class="server-nav-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <span>Panele:</span>
        </div>
        <div class="server-nav-links">
            <a href="index.php" class="server-nav-link active" title="Sterowanie kotłownią">
                <svg viewBox="0 0 24 24"><path d="M12 2c1.1 0 2 .9 2 2 0 .74-.4 1.38-1 1.72v1.56a5 5 0 0 1 4 4.9v2.82l1.7 1.7A1 1 0 0 1 18 18H6a1 1 0 0 1-.7-1.7l1.7-1.7v-2.82a5 5 0 0 1 4-4.9V5.72C10.4 5.38 10 4.74 10 4c0-1.1.9-2 2-2z"/><path d="M12 9a3 3 0 0 0-3 3v3h6v-3a3 3 0 0 0-3-3z"/></svg>
                <span>Kotłownia</span>
            </a>
            <a href="http://10.0.0.4/" class="server-nav-link" title="Sterowanie roletami i bramami - Grzegorzka 8">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
                <span>Grzegorzka 8</span>
            </a>
            <a href="http://10.0.0.5/" class="server-nav-link" title="Sterowanie roletami - Grzegorzka 8A">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Grzegorzka 8A</span>
            </a>
            <a href="wentylacja.php" class="server-nav-link" title="Sterowanie wentylacją">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                <span>Wentylacja</span>
            </a>
            <a href="http://10.0.0.4/brama.php" class="server-nav-link" title="Sterowanie bramami posesji">
                <svg viewBox="0 0 24 24"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>
                <span>Brama</span>
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
            <p>Piec Gazowy CWU &bull; Piec Gazowy CO &bull; Pompa obiegowa &bull; Serwer PI0 (10.0.0.2)</p>
        </div>
        <div class="header-actions">
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

    <?php
    $coGroup = $temperaturesData['groups']['co'] ?? [];
    $gazGroup = $temperaturesData['groups']['gaz'] ?? [];
    $cwuGroup = $temperaturesData['groups']['cwu'] ?? [];
    ?>

    <!-- Sekcja 1: Temperatury w kotłowni (1-Wire) -->
    <div class="section-header">
        <div class="section-title">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>
            </svg>
            <span>Temperatury w kotłowni</span>
        </div>
        <div class="section-meta">
            <span class="badge-tech">1-Wire &bull; DS18B20</span>
            <span class="badge-active-sensors" id="activeSensorsBadge">
                <span class="sensor-dot <?= (!empty($temperaturesData['active_count'])) ? 'online' : '' ?>"></span>
                <span id="activeSensorsText"><?= $temperaturesData['active_count'] ?? 0 ?> / <?= $temperaturesData['total_count'] ?? 5 ?> czujników</span>
            </span>
        </div>
    </div>

    <!-- Blok z wartościami temperatur podzielonymi na CO, Gaz i CWU -->
    <div class="temp-groups-grid">
        <!-- 1. Centralne Ogrzewanie (CO) -->
        <div class="temp-group-card group-co">
            <div class="temp-group-head">
                <div class="temp-group-title-row">
                    <div class="temp-group-icon">
                        <svg viewBox="0 0 24 24" stroke="currentColor">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <line x1="7" y1="5" x2="7" y2="19"/>
                            <line x1="11" y1="5" x2="11" y2="19"/>
                            <line x1="15" y1="5" x2="15" y2="19"/>
                            <line x1="7" y1="2" x2="7" y2="5"/>
                            <line x1="17" y1="2" x2="17" y2="5"/>
                            <line x1="7" y1="19" x2="7" y2="22"/>
                            <line x1="17" y1="19" x2="17" y2="22"/>
                        </svg>
                    </div>
                    <div class="temp-group-titles">
                        <span class="temp-group-name">Centralne Ogrzewanie (CO)</span>
                        <span class="temp-group-desc">Obieg grzejnikowy &bull; Zasilanie i Powrót</span>
                    </div>
                </div>
                <span class="delta-badge" id="delta-co" title="Różnica temperatur: Zasilanie CO - Powrót CO" <?= empty($coGroup['delta_formatted']) ? 'style="display:none;"' : '' ?>>
                    ΔT: <strong id="delta-val-co"><?= htmlspecialchars($coGroup['delta_formatted'] ?? '--.-') ?></strong>°C
                </span>
            </div>

            <div class="temp-sub-grid">
                <!-- CO + (Zasilanie) -->
                <?php $coP = $coGroup['sensor_items']['co_plus'] ?? []; ?>
                <div class="temp-item item-supply" id="temp-item-co_plus" title="<?= htmlspecialchars($coP['full_name'] ?? 'CO +') ?> (<?= htmlspecialchars($coP['sensor_id'] ?? '') ?>)">
                    <div class="temp-item-head">
                        <span class="temp-item-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                            CO + (Zasilanie)
                        </span>
                        <span class="sensor-id-pill" title="ID czujnika DS18B20"><?= htmlspecialchars($coP['sensor_id'] ?? '') ?></span>
                    </div>
                    <div class="temp-val temp-supply <?= empty($coP['success']) ? 'temp-error' : '' ?>" id="temp-val-co_plus">
                        <?= htmlspecialchars($coP['formatted'] ?? '--.-') ?> <small>°C</small>
                    </div>
                    <div class="temp-item-sub" id="temp-sub-co_plus"><?= !empty($coP['success']) ? htmlspecialchars($coP['desc'] ?? '') : 'Brak odczytu / rozłączony' ?></div>
                </div>

                <!-- CO - (Powrót) -->
                <?php $coM = $coGroup['sensor_items']['co_minus'] ?? []; ?>
                <div class="temp-item item-return" id="temp-item-co_minus" title="<?= htmlspecialchars($coM['full_name'] ?? 'CO -') ?> (<?= htmlspecialchars($coM['sensor_id'] ?? '') ?>)">
                    <div class="temp-item-head">
                        <span class="temp-item-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                            CO - (Powrót)
                        </span>
                        <span class="sensor-id-pill" title="ID czujnika DS18B20"><?= htmlspecialchars($coM['sensor_id'] ?? '') ?></span>
                    </div>
                    <div class="temp-val temp-return <?= empty($coM['success']) ? 'temp-error' : '' ?>" id="temp-val-co_minus">
                        <?= htmlspecialchars($coM['formatted'] ?? '--.-') ?> <small>°C</small>
                    </div>
                    <div class="temp-item-sub" id="temp-sub-co_minus"><?= !empty($coM['success']) ? htmlspecialchars($coM['desc'] ?? '') : 'Brak odczytu / rozłączony' ?></div>
                </div>
            </div>
        </div>

        <!-- 2. Kocioł Gazowy (Gaz) -->
        <div class="temp-group-card group-gaz">
            <div class="temp-group-head">
                <div class="temp-group-title-row">
                    <div class="temp-group-icon">
                        <svg viewBox="0 0 24 24" stroke="currentColor">
                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                        </svg>
                    </div>
                    <div class="temp-group-titles">
                        <span class="temp-group-name">Kocioł Gazowy (Gaz)</span>
                        <span class="temp-group-desc">Obieg kotła gazowego &bull; Zasilanie i Powrót</span>
                    </div>
                </div>
                <span class="delta-badge" id="delta-gaz" title="Różnica temperatur: Zasilanie Gaz - Powrót Gaz" <?= empty($gazGroup['delta_formatted']) ? 'style="display:none;"' : '' ?>>
                    ΔT: <strong id="delta-val-gaz"><?= htmlspecialchars($gazGroup['delta_formatted'] ?? '--.-') ?></strong>°C
                </span>
            </div>

            <div class="temp-sub-grid">
                <!-- Gaz + (Zasilanie) -->
                <?php $gazP = $gazGroup['sensor_items']['gaz_plus'] ?? []; ?>
                <div class="temp-item item-supply" id="temp-item-gaz_plus" title="<?= htmlspecialchars($gazP['full_name'] ?? 'Gaz +') ?> (<?= htmlspecialchars($gazP['sensor_id'] ?? '') ?>)">
                    <div class="temp-item-head">
                        <span class="temp-item-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                            Gaz + (Zasilanie)
                        </span>
                        <span class="sensor-id-pill" title="ID czujnika DS18B20"><?= htmlspecialchars($gazP['sensor_id'] ?? '') ?></span>
                    </div>
                    <div class="temp-val temp-supply <?= empty($gazP['success']) ? 'temp-error' : '' ?>" id="temp-val-gaz_plus">
                        <?= htmlspecialchars($gazP['formatted'] ?? '--.-') ?> <small>°C</small>
                    </div>
                    <div class="temp-item-sub" id="temp-sub-gaz_plus"><?= !empty($gazP['success']) ? htmlspecialchars($gazP['desc'] ?? '') : 'Brak odczytu / rozłączony' ?></div>
                </div>

                <!-- Gaz - (Powrót) -->
                <?php $gazM = $gazGroup['sensor_items']['gaz_minus'] ?? []; ?>
                <div class="temp-item item-return" id="temp-item-gaz_minus" title="<?= htmlspecialchars($gazM['full_name'] ?? 'Gaz -') ?> (<?= htmlspecialchars($gazM['sensor_id'] ?? '') ?>)">
                    <div class="temp-item-head">
                        <span class="temp-item-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                            Gaz - (Powrót)
                        </span>
                        <span class="sensor-id-pill" title="ID czujnika DS18B20"><?= htmlspecialchars($gazM['sensor_id'] ?? '') ?></span>
                    </div>
                    <div class="temp-val temp-return <?= empty($gazM['success']) ? 'temp-error' : '' ?>" id="temp-val-gaz_minus">
                        <?= htmlspecialchars($gazM['formatted'] ?? '--.-') ?> <small>°C</small>
                    </div>
                    <div class="temp-item-sub" id="temp-sub-gaz_minus"><?= !empty($gazM['success']) ? htmlspecialchars($gazM['desc'] ?? '') : 'Brak odczytu / rozłączony' ?></div>
                </div>
            </div>
        </div>

        <!-- 3. Ciepła Woda Użytkowa (CWU) -->
        <div class="temp-group-card group-cwu">
            <div class="temp-group-head">
                <div class="temp-group-title-row">
                    <div class="temp-group-icon">
                        <svg viewBox="0 0 24 24" stroke="currentColor">
                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                        </svg>
                    </div>
                    <div class="temp-group-titles">
                        <span class="temp-group-name">Ciepła Woda Użytkowa (CWU)</span>
                        <span class="temp-group-desc">Zasobnik ciepłej wody użytkowej</span>
                    </div>
                </div>
                <span class="delta-badge badge-cwu-tag" id="tag-cwu">
                    Zasobnik CWU
                </span>
            </div>

            <div class="temp-sub-grid single-sensor">
                <!-- CWU (Zasobnik) -->
                <?php $cwuS = $cwuGroup['sensor_items']['cwu'] ?? []; ?>
                <div class="temp-item item-tank" id="temp-item-cwu" title="<?= htmlspecialchars($cwuS['full_name'] ?? 'CWU') ?> (<?= htmlspecialchars($cwuS['sensor_id'] ?? '') ?>)">
                    <div class="temp-item-head">
                        <span class="temp-item-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                            CWU (Zasobnik)
                        </span>
                        <span class="sensor-id-pill" title="ID czujnika DS18B20"><?= htmlspecialchars($cwuS['sensor_id'] ?? '') ?></span>
                    </div>
                    <div class="temp-val temp-cwu <?= empty($cwuS['success']) ? 'temp-error' : '' ?>" id="temp-val-cwu">
                        <?= htmlspecialchars($cwuS['formatted'] ?? '--.-') ?> <small>°C</small>
                    </div>
                    <div class="temp-item-sub" id="temp-sub-cwu"><?= !empty($cwuS['success']) ? htmlspecialchars($cwuS['desc'] ?? '') : 'Brak odczytu / rozłączony' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sekcja 2: Sterowanie obwodami kotłowni -->
    <div class="section-header" style="margin-top: 26px;">
        <div class="section-title">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                <line x1="6" y1="6" x2="6.01" y2="6"/>
                <line x1="6" y1="18" x2="6.01" y2="18"/>
            </svg>
            <span>Sterowanie obwodami kotłowni</span>
        </div>
        <div class="section-meta">
            <span class="badge-tech">Przekaźniki GPIO</span>
        </div>
    </div>

    <!-- Lista urządzeń -->
    <div class="devices-grid">
        <?php foreach ($DEVICES as $id => $dev):
            $st = $devicesStatus[$id] ?? ['is_on' => false, 'label' => 'WYŁĄCZONY', 'badge' => 'badge-secondary'];
            $isOn = !empty($st['is_on']);
        ?>
        <div class="device-card <?= $isOn ? 'is-active' : ((($st['state'] ?? '') === 'stop' || ($st['raw'] ?? '') === '2') ? 'is-poweroff' : '') ?>" id="card-<?= htmlspecialchars($id) ?>">
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
                        <span class="device-badge-gpio"><?= !empty($dev['script']) ? (htmlspecialchars($dev['script']) . ' &bull; GPIO ' . htmlspecialchars($dev['gpio']) . ' (pin ' . htmlspecialchars($dev['pin']) . ')') : ('GPIO ' . htmlspecialchars($dev['gpio']) . ' (pin ' . htmlspecialchars($dev['pin']) . ')') ?></span>
                        <span class="status-pill <?= $st['badge'] ?>" id="badge-<?= htmlspecialchars($id) ?>">
                            <span class="status-pill-dot"></span>
                            <span class="status-pill-label"><?= htmlspecialchars($st['label']) ?></span>
                        </span>
                    </div>
                    <div class="device-desc"><?= htmlspecialchars($dev['desc']) ?></div>
                </div>
            </div>

            <div class="device-actions">
                <?php if ($id === 'piec_cwu' || $id === 'piec_co'): ?>
                    <!-- Klawisz 1: Włącz gdy wyłączony / Wyłącz gdy włączony (open / close: praca / brak pracy) -->
                    <button type="button" 
                            class="btn-action btn-toggle <?= $isOn ? 'is-active-btn' : '' ?>" 
                            id="btn-toggle-<?= htmlspecialchars($id) ?>"
                            onclick="triggerAction('<?= htmlspecialchars($id) ?>', 'toggle')"
                            title="<?= $isOn ? ('Wyłącz ' . htmlspecialchars($dev['name'])) : ('Włącz ' . htmlspecialchars($dev['name'])) ?>">
                        <?php if ($isOn): ?>
                            <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                            <span>Wyłącz</span>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                            <span>Włącz</span>
                        <?php endif; ?>
                    </button>

                    <!-- Klawisz 2: Zasilanie Wyłącz (stop: wyłączenie zasilania z prośbą o potwierdzenie) -->
                    <button type="button" 
                            class="btn-action btn-poweroff <?= (($st['state'] ?? '') === 'stop' || ($st['raw'] ?? '') === '2') ? 'is-active-btn' : '' ?>" 
                            id="btn-poweroff-<?= htmlspecialchars($id) ?>"
                            onclick="triggerPowerOff('<?= htmlspecialchars($id) ?>')"
                            title="Wyłącz zasilanie dla <?= htmlspecialchars($dev['name']) ?> (wymaga potwierdzenia)">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="3" x2="12" y2="12"/></svg>
                        <span>Zasilanie Wyłącz</span>
                    </button>
                <?php else: ?>
                    <!-- Standardowe klawisze dla pompy: Włącz / Wyłącz -->
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
                <?php endif; ?>
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

    // Obsługa wyłączenia zasilania z prośbą o potwierdzenie
    function triggerPowerOff(deviceId) {
        const devNames = {
            'piec_cwu': 'Piec Gazowy CWU',
            'piec_co': 'Piec Gazowy CO',
            'pompa': 'Pompa obiegowa'
        };
        const devName = devNames[deviceId] || deviceId;
        const msg = `Potwierdzenie: Wyłączenie zasilania\n\n`
            + `Czy na pewno chcesz wyłączyć zasilanie dla "${devName}"?\n\n`
            + `Oznacza to całkowite wyłączenie zasilania kotła gazowego (po ok. 200s schłodzenia palnika i elektroniki).`;

        if (confirm(msg)) {
            triggerAction(deviceId, 'stop');
        }
    }

    // Aktualizacja widoku pojedynczego urządzenia w DOM
    function updateDeviceDOM(deviceId, info) {
        const card = document.getElementById(`card-${deviceId}`);
        const badge = document.getElementById(`badge-${deviceId}`);
        const btnToggle = document.getElementById(`btn-toggle-${deviceId}`);
        const btnPowerOff = document.getElementById(`btn-poweroff-${deviceId}`);
        const btnOn = document.getElementById(`btn-on-${deviceId}`);
        const btnOff = document.getElementById(`btn-off-${deviceId}`);

        if (!card || !badge) return;

        const isOn = !!info.is_on;
        const isStop = (info.state === 'stop' || info.raw === '2');

        // Karta
        if (isOn) {
            card.classList.add('is-active');
            card.classList.remove('is-poweroff');
        } else if (isStop) {
            card.classList.remove('is-active');
            card.classList.add('is-poweroff');
        } else {
            card.classList.remove('is-active', 'is-poweroff');
        }

        // Plakietka statusu
        badge.className = `status-pill ${info.badge || (isOn ? 'badge-success' : (isStop ? 'badge-danger' : 'badge-secondary'))}`;
        const labelSpan = badge.querySelector('.status-pill-label');
        if (labelSpan) {
            labelSpan.textContent = info.label || (isOn ? 'WŁĄCZONY' : (isStop ? 'ZASILANIE WYŁ.' : 'WYŁĄCZONY'));
        }

        // Klawisze dedykowane CWU / CO: Włącz gdy wyłączony, Wyłącz gdy włączony
        if (btnToggle) {
            btnToggle.disabled = false;
            const devNames = {
                'piec_cwu': 'Piec Gazowy CWU',
                'piec_co': 'Piec Gazowy CO'
            };
            const dName = devNames[deviceId] || '';
            if (isOn) {
                btnToggle.classList.add('is-active-btn');
                btnToggle.innerHTML = `<svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg><span>Wyłącz</span>`;
                btnToggle.title = dName ? `Wyłącz ${dName}` : 'Wyłącz';
            } else {
                btnToggle.classList.remove('is-active-btn');
                btnToggle.innerHTML = `<svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg><span>Włącz</span>`;
                btnToggle.title = dName ? `Włącz ${dName}` : 'Włącz';
            }
        }

        if (btnPowerOff) {
            btnPowerOff.disabled = false;
            btnPowerOff.innerHTML = `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="3" x2="12" y2="12"/></svg><span>Zasilanie Wyłącz</span>`;
            if (isStop) {
                btnPowerOff.classList.add('is-active-btn');
            } else {
                btnPowerOff.classList.remove('is-active-btn');
            }
        }

        // Standardowe przyciski (np. Pompa)
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
            if (!isOn && !isStop) {
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
        const btnToggle = document.getElementById(`btn-toggle-${deviceId}`);
        const btnPowerOff = document.getElementById(`btn-poweroff-${deviceId}`);
        const btnOn = document.getElementById(`btn-on-${deviceId}`);
        const btnOff = document.getElementById(`btn-off-${deviceId}`);

        const isActivating = (action === 'on' || action === '1' || action === 'start' || action === 'open');
        const isStopping = (action === 'stop' || action === 'power_off');
        const isToggling = (action === 'toggle');
        const currentlyActive = card ? card.classList.contains('is-active') : false;

        if (card) {
            if (isActivating) {
                card.classList.add('is-active');
                card.classList.remove('is-poweroff');
            } else if (isStopping) {
                card.classList.remove('is-active');
                card.classList.add('is-poweroff');
            } else if (isToggling) {
                if (currentlyActive) {
                    card.classList.remove('is-active');
                } else {
                    card.classList.add('is-active');
                    card.classList.remove('is-poweroff');
                }
            }
        }

        if (badge) {
            const labelSpan = badge.querySelector('.status-pill-label');
            if (isStopping) {
                badge.className = 'status-pill badge-danger';
                if (labelSpan) labelSpan.textContent = 'WYŁĄCZANIE ZASILANIA...';
            } else if (isActivating) {
                badge.className = 'status-pill badge-success';
                if (labelSpan) labelSpan.textContent = 'WŁĄCZANIE...';
            } else if (isToggling) {
                if (currentlyActive) {
                    badge.className = 'status-pill badge-secondary';
                    if (labelSpan) labelSpan.textContent = 'WYŁĄCZANIE...';
                } else {
                    badge.className = 'status-pill badge-success';
                    if (labelSpan) labelSpan.textContent = 'WŁĄCZANIE...';
                }
            } else {
                badge.className = 'status-pill badge-secondary';
                if (labelSpan) labelSpan.textContent = 'WYŁĄCZANIE...';
            }
        }

        if (btnToggle) {
            btnToggle.disabled = true;
            if (isActivating || (isToggling && !currentlyActive)) {
                btnToggle.innerHTML = `<span class="spinner"></span><span>Włączam...</span>`;
            } else {
                btnToggle.innerHTML = `<span class="spinner"></span><span>Wyłączam...</span>`;
            }
        }
        if (btnPowerOff && isStopping) {
            btnPowerOff.disabled = true;
            btnPowerOff.innerHTML = `<span class="spinner"></span><span>Wyłączam...</span>`;
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

    // Aktualizacja bloku temperatur w DOM
    function updateTemperaturesDOM(tempData) {
        if (!tempData || !tempData.groups) return;

        // Aktualizacja plakietki aktywnych czujników
        const activeSensorsBadge = document.getElementById('activeSensorsBadge');
        const activeSensorsText = document.getElementById('activeSensorsText');
        if (activeSensorsText && tempData.active_count !== undefined) {
            activeSensorsText.textContent = `${tempData.active_count} / ${tempData.total_count} czujników`;
        }
        if (activeSensorsBadge) {
            const dot = activeSensorsBadge.querySelector('.sensor-dot');
            if (dot) {
                if (tempData.active_count > 0) dot.classList.add('online');
                else dot.classList.remove('online');
            }
        }

        // Aktualizacja grup (CO, Gaz, CWU)
        for (const [groupId, group] of Object.entries(tempData.groups)) {
            // Różnica temperatur Delta T (dla CO i Gaz)
            const deltaBadge = document.getElementById(`delta-${groupId}`);
            const deltaVal = document.getElementById(`delta-val-${groupId}`);
            if (deltaBadge) {
                if (group.delta_formatted !== null && group.delta_formatted !== undefined) {
                    if (deltaVal) deltaVal.textContent = group.delta_formatted;
                    deltaBadge.style.display = 'inline-flex';
                } else if (!group.show_delta) {
                    // CWU nie posiada delty
                } else {
                    if (deltaVal) deltaVal.textContent = '--.-';
                    deltaBadge.style.display = 'none';
                }
            }

            // Czujniki w danej grupie
            if (group.sensor_items) {
                for (const [sensorKey, sData] of Object.entries(group.sensor_items)) {
                    const valEl = document.getElementById(`temp-val-${sensorKey}`);
                    const subEl = document.getElementById(`temp-sub-${sensorKey}`);

                    if (valEl) {
                        if (sData.success && sData.formatted !== null && sData.formatted !== '--.-') {
                            valEl.innerHTML = `${sData.formatted} <small>°C</small>`;
                            valEl.classList.remove('temp-error');
                            if (subEl && sData.desc) subEl.textContent = sData.desc;
                        } else {
                            valEl.innerHTML = `--.- <small>°C</small>`;
                            valEl.classList.add('temp-error');
                            if (subEl) subEl.textContent = 'Brak odczytu / rozłączony';
                        }
                    }
                }
            }
        }
    }

    // Cykliczne odpytywanie o stan urządzeń i temperatury
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

                // Aktualizacja temperatur 1-Wire
                if (data.temperatures) {
                    updateTemperaturesDOM(data.temperatures);
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
                    showToast('Zaktualizowano stany urządzeń i temperatury');
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
