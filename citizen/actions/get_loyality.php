<?php
/**
 * DengResQ | Loyalty Reward System — Data Endpoint
 * File: DengResQ/citizen/actions/get_loyalty.php
 *
 * GET → returns full loyalty profile for the logged-in citizen:
 *   - current tier (name, discount %, free checkup entitlement)
 *   - next tier (points needed, what they unlock)
 *   - progress % toward next tier
 *   - all tiers list (for the "unlock path" display)
 *   - free checkup availability this month
 */

session_start();
require_once '../../config/db_connect.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'citizen') {
    echo json_encode(['success' => false]); exit();
}

try {

$bcn = $conn->real_escape_string($_SESSION['user_id']);

// ── Get gamification meta (streak, decay state) ───────────────────────────────
$cg_row = $conn->query("
    SELECT COALESCE(total_points, 0) AS cg_points,
           last_active_date,
           decay_warned_at
    FROM citizen_gamification
    WHERE citizen_bcn = '$bcn'
")->fetch_assoc();

$last_active     = $cg_row ? ($cg_row['last_active_date'] ?? null) : null;
$decay_warned_at = $cg_row ? ($cg_row['decay_warned_at'] ?? null) : null;

// ── Combined points: sentinel pool (citizens.points) + donor pool ─────────────
// Matches the same formula used in get_contributions.php and the contributions page.
// citizens.points = all gamification/sentinel actions (sites, campaigns, streaks, tips, missions)
// donor_points_log = blood donations, financial aid donor actions
$citizen_row = $conn->query("
    SELECT COALESCE(points, 0) AS sentinel_points
    FROM citizens
    WHERE bcn = '$bcn'
")->fetch_assoc();
$sentinel_points = (int)(($citizen_row['sentinel_points'] ?? 0));

$donor_row = $conn->query("
    SELECT COALESCE(SUM(points), 0) AS donor_pts
    FROM donor_points_log
    WHERE citizen_bcn = '$bcn'
")->fetch_assoc();
$donor_points = (int)(($donor_row['donor_pts'] ?? 0));

$total_points = $sentinel_points + $donor_points;

// ── Compute inactivity ────────────────────────────────────────────────────────
$days_inactive = 0;
if ($last_active) {
    $days_inactive = (int)floor((time() - strtotime($last_active)) / 86400);
}

// ── Fetch all tiers ordered by threshold ─────────────────────────────────────
$tiers_result = $conn->query("
    SELECT * FROM loyalty_tiers ORDER BY points_required ASC
");
$all_tiers = [];
while ($t = $tiers_result->fetch_assoc()) {
    $all_tiers[] = $t;
}

// ── Determine current tier and next tier ─────────────────────────────────────
if (empty($all_tiers)) {
    echo json_encode(['success' => false, 'message' => 'No loyalty tiers configured.']); exit();
}
$current_tier = $all_tiers[0]; // default = Newcomer
$next_tier    = null;

foreach ($all_tiers as $i => $tier) {
    if ($total_points >= (int)$tier['points_required']) {
        $current_tier = $tier;
        $next_tier    = $all_tiers[$i + 1] ?? null; // null = max tier reached
    }
}

// ── Progress toward next tier ─────────────────────────────────────────────────
$progress_pct   = 100;
$points_needed  = 0;
$progress_label = 'Max tier reached!';

if ($next_tier) {
    $tier_start     = (int)$current_tier['points_required'];
    $tier_end       = (int)$next_tier['points_required'];
    $progress_pts   = $total_points - $tier_start;
    $range          = $tier_end - $tier_start;
    $progress_pct   = min(100, (int)round(($progress_pts / max(1, $range)) * 100));
    $points_needed  = $tier_end - $total_points;
    $progress_label = $points_needed . ' pts to ' . $next_tier['tier_name'];
}

// ── Free checkup availability this month ─────────────────────────────────────
$period_key      = date('Y-m');
$free_available  = false;
$free_used_this_month = false;

if ((int)$current_tier['free_checkup_monthly'] === 1) {
    $used = $conn->query("
        SELECT id FROM citizen_free_checkups
        WHERE citizen_bcn = '$bcn' AND period_key = '$period_key'
    ")->fetch_assoc();

    $free_used_this_month = (bool)$used;
    $free_available       = !$free_used_this_month;
}

// ── Build tiers array for the unlock-path display ─────────────────────────────
$tiers_display = [];
foreach ($all_tiers as $tier) {
    $unlocked = ($total_points >= (int)$tier['points_required']);
    $is_current = ($tier['tier_id'] === $current_tier['tier_id']);

    $perks = [];
    if ((int)$tier['test_discount_pct'] > 0) {
        $perks[] = (int)$tier['test_discount_pct'] . '% off all test payments';
    }
    if ((int)$tier['free_checkup_monthly'] === 1) {
        $perks[] = '1 free full checkup per month (always)';
    }
    if (empty($perks)) {
        $perks[] = 'Start earning — rewards unlock soon!';
    }

    $tiers_display[] = [
        'tier_id'          => $tier['tier_id'],
        'tier_name'        => $tier['tier_name'],
        'icon'             => $tier['icon'],
        'color_hex'        => $tier['color_hex'],
        'points_required'  => (int)$tier['points_required'],
        'discount_pct'     => (int)$tier['test_discount_pct'],
        'free_checkup'     => (bool)$tier['free_checkup_monthly'],
        'description'      => $tier['description'],
        'perks'            => $perks,
        'unlocked'         => $unlocked,
        'is_current'       => $is_current,
    ];
}

// ── Decay rule for current tier ───────────────────────────────────────────────
$decay_rules = [
    'tier_1000'  => 20,
    'tier_2500'  => 30,
    'tier_5000'  => 75,
    'tier_10000' => 150,
    'tier_25000' => 300,
];
$decay_deduct    = $decay_rules[$current_tier['tier_id']] ?? 0;
$decay_active    = ($decay_deduct > 0 && $days_inactive >= 5); // warn at 5 days
$decay_imminent  = ($decay_deduct > 0 && $days_inactive >= 7); // penalty already firing

// ── Recent decay log entries ──────────────────────────────────────────────────
$decay_log = [];
$dl_result = $conn->query("
    SELECT tier_id, points_deducted, days_inactive, total_after, applied_at
    FROM loyalty_decay_log
    WHERE citizen_bcn = '$bcn'
    ORDER BY applied_at DESC
    LIMIT 5
");
while ($dl = $dl_result->fetch_assoc()) {
    $decay_log[] = $dl;
}

// ── Recent decay notifications (read + unread — last 3) ──────────────────────
$decay_notifs = [];
$dn_result = $conn->query("
    SELECT id, message, days_inactive, created_at, is_read
    FROM decay_notifications
    WHERE citizen_bcn = '$bcn'
      AND notif_type = 'rank_drop'
    ORDER BY created_at DESC
    LIMIT 3
");
while ($dn = $dn_result->fetch_assoc()) {
    $decay_notifs[] = $dn;
}

echo json_encode([
    'success'              => true,
    'total_points'         => $total_points,
    'current_tier'         => [
        'tier_id'          => $current_tier['tier_id'],
        'tier_name'        => $current_tier['tier_name'],
        'icon'             => $current_tier['icon'],
        'color_hex'        => $current_tier['color_hex'],
        'discount_pct'     => (int)$current_tier['test_discount_pct'],
        'free_checkup'     => (bool)$current_tier['free_checkup_monthly'],
        'free_checkup_limit'=> (int)$current_tier['free_checkup_limit'],
    ],
    'next_tier'            => $next_tier ? [
        'tier_id'          => $next_tier['tier_id'],
        'tier_name'        => $next_tier['tier_name'],
        'icon'             => $next_tier['icon'],
        'points_required'  => (int)$next_tier['points_required'],
        'discount_pct'     => (int)$next_tier['test_discount_pct'],
        'free_checkup'     => (bool)$next_tier['free_checkup_monthly'],
    ] : null,
    'progress_pct'         => $progress_pct,
    'points_needed'        => $points_needed,
    'progress_label'       => $progress_label,
    'free_available'       => $free_available,
    'free_used_this_month' => $free_used_this_month,
    'period_key'           => $period_key,
    'tiers'                => $tiers_display,
    'days_inactive'        => $days_inactive,
    'decay_deduct'         => $decay_deduct,
    'decay_active'         => $decay_active,
    'decay_imminent'       => $decay_imminent,
    'decay_log'            => $decay_log,
    'decay_notifications'  => $decay_notifs,
]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
