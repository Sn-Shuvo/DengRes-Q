<?php
session_start();
require_once '../../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'citizen') {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit();
}

$bcn         = $_SESSION['user_id'];
$campaign_id = intval($_POST['campaign_id'] ?? 0);

if (!$campaign_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid campaign.']);
    exit();
}

// ── FETCH CAMPAIGN ────────────────────────────────────────────────────────────
$campaign = $conn->query("
    SELECT campaign_id, title, start_time, end_time, status
    FROM campaigns WHERE campaign_id = $campaign_id
")->fetch_assoc();

if (!$campaign) {
    echo json_encode(['success' => false, 'message' => 'Campaign not found.']);
    exit();
}

// ── BLOCK IF CAMPAIGN IS CURRENTLY IN PROGRESS ───────────────────────────────
// Allow cancel before start OR after it has fully ended.
// Only lock during the active window: started but not yet finished.
if ($campaign['start_time'] && $campaign['end_time']) {
    $start = new DateTime($campaign['start_time']);
    $end   = new DateTime($campaign['end_time']);
    $now   = new DateTime();
    if ($now >= $start && $now <= $end) {
        echo json_encode([
            'success' => false,
            'message' => '⚠️ Campaign is currently in progress — cancellation is not allowed while it\'s ongoing.'
        ]);
        exit();
    }
}

// ── BLOCK IF ATTENDANCE ALREADY MARKED ───────────────────────────────────────
$reg = $conn->query("
    SELECT reg_id, token_used, status
    FROM campaigns_reg
    WHERE campaign_id = $campaign_id
      AND citizen_bcn = '$bcn'
      AND status = 'Active'
    LIMIT 1
")->fetch_assoc();

if (!$reg) {
    echo json_encode(['success' => false, 'message' => 'No active registration found.']);
    exit();
}

if ((int)$reg['token_used'] === 1) {
    echo json_encode([
        'success' => false,
        'message' => '✅ Your attendance has already been recorded — cannot cancel.'
    ]);
    exit();
}

// ── SOFT-DELETE: mark cancelled ───────────────────────────────────────────────
$ok = $conn->query("
    UPDATE campaigns_reg
    SET status       = 'Cancelled',
        cancelled_at = NOW()
    WHERE reg_id = {$reg['reg_id']}
");

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    exit();
}

// ── REVERSE campaign_join points (if awarded) ─────────────────────────────────
// Remove the ledger entry only if it exists and hasn't contributed to a
// completed mission — simple deduction via negative ledger entry
$ledger = $conn->query("
    SELECT ledger_id, points FROM points_ledger
    WHERE citizen_bcn = '$bcn'
      AND action_id   = 'campaign_join'
      AND reference_id = $campaign_id
      AND status = 'Awarded'
    ORDER BY earned_at DESC LIMIT 1
")->fetch_assoc();

if ($ledger) {
    $pts_back = (int)$ledger['points'];
    $conn->query("
        UPDATE points_ledger SET status = 'Reversed'
        WHERE ledger_id = {$ledger['ledger_id']}
    ");
    $conn->query("
        UPDATE citizen_gamification
        SET total_points   = GREATEST(0, total_points   - $pts_back),
            weekly_points  = GREATEST(0, weekly_points  - $pts_back),
            monthly_points = GREATEST(0, monthly_points - $pts_back)
        WHERE citizen_bcn = '$bcn'
    ");
    $conn->query("
        UPDATE citizens SET points = GREATEST(0, points - $pts_back)
        WHERE bcn = '$bcn'
    ");
}

echo json_encode([
    'success'  => true,
    'message'  => "Registration for \"{$campaign['title']}\" has been cancelled.",
    'campaign_id' => $campaign_id
]);
?>
