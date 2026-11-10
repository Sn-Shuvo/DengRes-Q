<?php
/**
 * DengResQ | Phase 2 API
 * File: DengResQ/citizen/actions/chest_actions.php
 *
 * Actions:
 *   GET  ?action=get_data      — returns chests + decay notifs + freeze count
 *   POST action=open_chest     — opens a specific chest
 *   POST action=use_freeze     — manually uses a streak freeze
 *   POST action=mark_read      — marks decay notifs as read
 */

session_start();
require_once '../../config/db_connect.php';
require_once '../../includes/chest_engine.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$bcn    = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_data';

switch ($action) {

    // ── GET DATA ─────────────────────────────────────────────────────────────
    case 'get_data':
        // Auto-check freeze (login hook)
        $freeze_used = ChestEngine::useFreeze($conn, $bcn);

        // Sync chests
        $new_chests  = ChestEngine::syncChests($conn, $bcn);

        // BUG FIX: if syncChests found nothing new (chests were already created by
        // Gamification::award on another page), still return recently-unlocked Ready
        // chests (within last 10 min) so the dashboard toast fires on first load
        // after the citizen earns points on a different page.
        if (empty($new_chests)) {
            $bcn_e      = $conn->real_escape_string($bcn);
            $recent_res = $conn->query("
                SELECT chest_number, pts_threshold
                FROM citizen_chests
                WHERE citizen_bcn = '$bcn_e'
                  AND status      = 'Ready'
                  AND unlocked_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                ORDER BY chest_number ASC
            ");
            while ($r = $recent_res->fetch_assoc()) {
                $new_chests[] = [
                    'chest_number'  => (int)$r['chest_number'],
                    'pts_threshold' => (int)$r['pts_threshold'],
                ];
            }
        }

        // Get all chest data
        $chest_data  = ChestEngine::getChests($conn, $bcn);

        // Get decay notifications
        $decay_notifs = ChestEngine::getDecayNotifs($conn, $bcn);
        $decay_check  = ChestEngine::checkDecay($conn, $bcn);

        // Merge decay_check results with stored notifs (dedupe by type)
        $notif_types_shown = array_column($decay_notifs, 'notif_type');
        foreach ($decay_check as $dc) {
            if (!in_array($dc['type'], $notif_types_shown)) {
                $decay_notifs[] = ['notif_type' => $dc['type'], 'message' => $dc['message'], 'is_read' => 0];
            }
        }

        // Streak info
        $gam = $conn->query("
            SELECT current_streak, streak_freezes, last_active_date,
                   COALESCE(xp_multiplier,1.0) AS xp_multiplier
            FROM citizen_gamification WHERE citizen_bcn='$bcn'
        ")->fetch_assoc();

        echo json_encode([
            'chests'       => $chest_data,
            'decay_notifs' => $decay_notifs,
            'streak'       => [
                'current'  => (int)($gam['current_streak']  ?? 0),
                'freezes'  => (int)($gam['streak_freezes']  ?? 0),
                'last_active' => $gam['last_active_date'] ?? null,
            ],
            'freeze_auto_used' => $freeze_used,
            'new_chests'   => $new_chests,
        ]);
        break;

    // ── OPEN CHEST ───────────────────────────────────────────────────────────
    case 'open_chest':
        $chest_id = intval($_POST['chest_id'] ?? 0);
        if (!$chest_id) { echo json_encode(['error' => 'Missing chest_id']); break; }
        $result = ChestEngine::openChest($conn, $bcn, $chest_id);
        echo json_encode($result);
        break;

    // ── USE FREEZE MANUALLY ──────────────────────────────────────────────────
    case 'use_freeze':
        $used = ChestEngine::useFreeze($conn, $bcn);
        $gam  = $conn->query("SELECT streak_freezes, current_streak FROM citizen_gamification WHERE citizen_bcn='$bcn'")->fetch_assoc();
        echo json_encode([
            'success'       => $used,
            'freezes_left'  => (int)($gam['streak_freezes'] ?? 0),
            'streak'        => (int)($gam['current_streak'] ?? 0),
            'message'       => $used ? 'Freeze used! Your streak is safe.' : 'No freeze needed or no freezes available.',
        ]);
        break;

    // ── MARK NOTIFICATIONS READ ──────────────────────────────────────────────
    case 'mark_read':
        ChestEngine::markDecayRead($conn, $bcn);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
