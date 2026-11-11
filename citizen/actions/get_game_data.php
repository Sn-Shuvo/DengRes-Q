<?php
/**
 * DengResQ | Phase 1 API Endpoint
 * File: DengResQ/citizen/actions/get_game_data.php
 *
 * Returns quest board + milestone wall + streak multiplier info
 * for the citizen's game panel on the dashboard.
 */

session_start();
require_once '../../config/db_connect.php';
require_once '../../includes/quest_engine.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$bcn = $_SESSION['user_id'];

// Quest board (daily + weekly)
$board = QuestEngine::getQuestBoard($conn, $bcn);

// Milestone wall
$milestone_data = QuestEngine::getMilestoneWall($conn, $bcn);

// Streak + multiplier info
$gam_row = $conn->query("
    SELECT current_streak, best_streak, streak_freezes,
           COALESCE(xp_multiplier, 1.0) AS xp_multiplier
    FROM citizen_gamification WHERE citizen_bcn='$bcn'
")->fetch_assoc();

$streak       = (int)($gam_row['current_streak']  ?? 0);
$best_streak  = (int)($gam_row['best_streak']     ?? 0);
$freezes      = (int)($gam_row['streak_freezes']  ?? 0);
$mult         = floatval($gam_row['xp_multiplier'] ?? 1.0);
$mult_info    = QuestEngine::getMultiplierLabel($streak);

// Daily quest completion count (for today)
$today  = date('Y-m-d');
$d_done = (int)$conn->query("
    SELECT COUNT(*) AS c FROM citizen_quests
    WHERE citizen_bcn='$bcn' AND completed=1
      AND period_key='$today'
")->fetch_assoc()['c'];
$d_total = count($board['daily']);

// Weekly quest completion count
$week_key = date('Y-W');
$w_done   = (int)$conn->query("
    SELECT COUNT(*) AS c FROM citizen_quests
    WHERE citizen_bcn='$bcn' AND completed=1
      AND period_key='$week_key'
")->fetch_assoc()['c'];
$w_total  = count($board['weekly']);

echo json_encode([
    'quests'     => $board,
    'milestones' => $milestone_data,
    'streak'     => [
        'current'       => $streak,
        'best'          => $best_streak,
        'freezes'       => $freezes,
        'multiplier'    => $mult,
        'multiplier_info' => $mult_info,
    ],
    'summary'    => [
        'daily_done'    => $d_done,
        'daily_total'   => $d_total,
        'weekly_done'   => $w_done,
        'weekly_total'  => $w_total,
    ],
]);
