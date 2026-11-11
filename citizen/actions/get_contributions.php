<?php
session_start();
require_once '../../config/db_connect.php';
header('Content-Type: application/json');

if (!empty($_SESSION['user_id'])) {
    $bcn = $conn->real_escape_string($_SESSION['user_id']);
} elseif (!empty($_GET['bcn'])) {
    $bcn = $conn->real_escape_string($_GET['bcn']);
} else {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

// ── Citizen base ─────────────────────────────────────────────────────────────
$citizen = $conn->query("
    SELECT c.full_name, c.points, c.trust_score, bg.group_name AS blood_group
    FROM citizens c
    LEFT JOIN blood_groups bg ON bg.blood_group_id = c.blood_group_id
    WHERE c.bcn='$bcn'
")->fetch_assoc();
if (!$citizen) { echo json_encode(['error' => 'Not found']); exit(); }

// ── Two separate point pools ──────────────────────────────────────────────────
// Pool A: citizens.points  ← Gamification engine (sentinel, campaign, streak, tips, missions)
// Pool B: donor_points_log ← Donor system (blood commits/donations, financial aid)
// Grand total shown on page = Pool A + Pool B

$citizen_points = (int)($citizen['points'] ?? 0);

$donor_pool_row = $conn->query("
    SELECT COALESCE(SUM(points), 0) AS total
    FROM donor_points_log
    WHERE citizen_bcn='$bcn'
")->fetch_assoc();
$donor_points_total = (int)($donor_pool_row['total'] ?? 0);

$total_points = $citizen_points + $donor_points_total;

// ── Gamification row (for tier/streak) ───────────────────────────────────────
$gamif = $conn->query("
    SELECT tier, current_streak, best_streak, weekly_points, monthly_points
    FROM citizen_gamification WHERE citizen_bcn='$bcn'
")->fetch_assoc();
$tier   = $gamif['tier']           ?? 'Bronze';
$streak = (int)($gamif['current_streak'] ?? 0);

// ── Sentinel breakdown from points_ledger ────────────────────────────────────
$ledger = [];
$lr = $conn->query("
    SELECT action_id, COALESCE(SUM(points),0) AS pts, COUNT(*) AS cnt
    FROM points_ledger
    WHERE citizen_bcn='$bcn' AND status='Awarded'
    GROUP BY action_id
");
while ($r = $lr->fetch_assoc()) {
    $ledger[$r['action_id']] = ['pts' => (int)$r['pts'], 'cnt' => (int)$r['cnt']];
}

function ledgerSum(array $actions, array $ledger): int {
    $t = 0;
    foreach ($actions as $a) $t += $ledger[$a]['pts'] ?? 0;
    return $t;
}

$site_points     = ledgerSum(['site_report','site_verified','site_cleared','site_high_risk','site_multi_trip'], $ledger);
$case_points     = ledgerSum(['symptom_report'], $ledger);
$campaign_points = ledgerSum(['campaign_attend','campaign_join','campaign_awareness'], $ledger);
$streak_pts      = ledgerSum(['streak_weekly'], $ledger);
$mission_pts     = ledgerSum(['mission_complete'], $ledger);
$tip_pts         = ledgerSum(['tip_read','tip_helpful','tip_share'], $ledger);
$cf_ledger_pts   = ledgerSum(['community_fund_donate'], $ledger);
$other_pts       = $streak_pts + $mission_pts + $tip_pts
                 + ledgerSum(['refer_active','site_cleared'], $ledger);

$ledger_total    = $site_points + $case_points + $campaign_points + $other_pts + $cf_ledger_pts;
// Points awarded before ledger was fully implemented (missing from ledger but in citizens.points)
$unlogged_points = max(0, $citizen_points - $ledger_total);

// ── Donor breakdown from donor_points_log ────────────────────────────────────
$blood_pts_donor = 0;
$fin_pts_donor   = 0;
$dpl = $conn->query("
    SELECT points, reason FROM donor_points_log
    WHERE citizen_bcn='$bcn'
");
while ($r = $dpl->fetch_assoc()) {
    $pts    = (int)$r['points'];
    $reason = strtolower($r['reason']);
    if (strpos($reason, 'financial') !== false || strpos($reason, 'donation of') !== false) {
        $fin_pts_donor += $pts;
    } else {
        $blood_pts_donor += $pts;
    }
}

// ── Sentinel source-table stats (counts + accuracy) ──────────────────────────
$total_sites    = (int)$conn->query("SELECT COUNT(*) AS c FROM breeding_sites WHERE reporter_bcn='$bcn'")->fetch_assoc()['c'];
$verified_sites = (int)$conn->query("SELECT COUNT(*) AS c FROM breeding_sites WHERE reporter_bcn='$bcn' AND verify_status='Verified'")->fetch_assoc()['c'];
$rejected_sites = (int)$conn->query("SELECT COUNT(*) AS c FROM breeding_sites WHERE reporter_bcn='$bcn' AND verify_status='Rejected'")->fetch_assoc()['c'];
$site_accuracy  = ($verified_sites + $rejected_sites) > 0
    ? round($verified_sites / ($verified_sites + $rejected_sites) * 100) : 0;

$total_cases     = (int)$conn->query("SELECT COUNT(*) AS c FROM symptom_checks WHERE reporter_bcn='$bcn'")->fetch_assoc()['c'];
$confirmed_cases = (int)$conn->query("SELECT COUNT(*) AS c FROM symptom_checks WHERE reporter_bcn='$bcn' AND case_status='Confirmed'")->fetch_assoc()['c'];
$recovered_cases = (int)$conn->query("SELECT COUNT(*) AS c FROM symptom_checks WHERE reporter_bcn='$bcn' AND case_status='Recovered'")->fetch_assoc()['c'];
$case_accuracy   = $total_cases > 0
    ? round(($confirmed_cases + $recovered_cases) / $total_cases * 100) : 0;

$total_campaigns = (int)$conn->query("SELECT COUNT(*) AS c FROM campaigns_reg WHERE citizen_bcn='$bcn'")->fetch_assoc()['c'];

// ── Donor profile ─────────────────────────────────────────────────────────────
$donor_profile  = $conn->query("SELECT * FROM donor_profiles WHERE citizen_bcn='$bcn'")->fetch_assoc();
$is_donor       = !empty($donor_profile);
$reliability    = (int)($donor_profile['reliability_score'] ?? 0);
// 3NF FIX: blood_group derived from blood_groups via blood_group_id
$bg_name_row = $donor_profile['blood_group_id']
    ? $conn->query("SELECT group_name FROM blood_groups WHERE blood_group_id=" . (int)$donor_profile['blood_group_id'])->fetch_assoc()
    : null;
$blood_group = $bg_name_row['group_name'] ?? ($citizen['blood_group'] ?? null);
$donor_label    = $reliability >= 90 ? 'Elite Donor'
                : ($reliability >= 75 ? 'Trusted Donor'
                : ($reliability >= 50 ? 'Active Donor' : 'New Donor'));

$blood_donations = $is_donor
    ? (int)$conn->query("SELECT COUNT(*) AS c FROM blood_donations WHERE donor_bcn='$bcn' AND status='Donated'")->fetch_assoc()['c'] : 0;
$blood_committed = $is_donor
    ? (int)$conn->query("SELECT COUNT(*) AS c FROM blood_donations WHERE donor_bcn='$bcn' AND status IN ('Committed','Donated')")->fetch_assoc()['c'] : 0;
$fin_row = $is_donor
    ? $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS total FROM financial_donations WHERE donor_bcn='$bcn' AND status='confirmed'")->fetch_assoc()
    : ['c' => 0, 'total' => 0];

// ── Community Fund donations (separate from donor financial aid) ──────────────
$cf_row = $conn->query("
    SELECT COUNT(*) AS c,
           COALESCE(SUM(amount),0) AS total,
           COALESCE(SUM(points_awarded),0) AS pts
    FROM community_fund_donations
    WHERE donor_bcn='$bcn' AND status='confirmed'
")->fetch_assoc();
$cf_count  = (int)($cf_row['c']     ?? 0);
$cf_total  = (float)($cf_row['total'] ?? 0);
$cf_points = (int)($cf_row['pts']   ?? 0);

// ── Trust ─────────────────────────────────────────────────────────────────────
$trust_score = (int)($citizen['trust_score'] ?? 100);
$trust_label = $trust_score >= 90 ? 'Elite Reporter'
             : ($trust_score >= 75 ? 'Trusted Reporter'
             : ($trust_score >= 50 ? 'Active Citizen' : 'New Member'));

// ── Totals ────────────────────────────────────────────────────────────────────
$total_actions = $total_sites + $total_cases + $total_campaigns
               + $blood_donations + (int)$fin_row['c'] + $cf_count;
$acc_items     = ($verified_sites + $rejected_sites) + ($confirmed_cases + $recovered_cases);
$avg_accuracy  = $acc_items > 0
    ? round(($verified_sites + $confirmed_cases + $recovered_cases) / $acc_items * 100) : 0;

// ── History: points_ledger (sentinel) + donor_points_log (donor) ──────────────
$history = [];
$icon_map = [
    'site_report'           => ['🦟', 'pending'],
    'site_verified'         => ['🦟', 'verified'],
    'site_cleared'          => ['🦟', 'success'],
    'site_high_risk'        => ['🦟', 'success'],
    'site_multi_trip'       => ['🦟', 'success'],
    'symptom_report'        => ['🩺', 'pending'],
    'campaign_attend'       => ['📢', 'campaign'],
    'campaign_join'         => ['📢', 'campaign'],
    'campaign_awareness'    => ['📢', 'campaign'],
    'blood_donate'          => ['🩸', 'success'],
    'blood_commit'          => ['💉', 'info'],
    'financial_donate'      => ['💰', 'success'],
    'community_fund_donate' => ['🌿', 'success'],
    'streak_weekly'         => ['🔥', 'success'],
    'mission_complete'      => ['⚡', 'success'],
    'tip_read'              => ['💡', 'neutral'],
    'tip_helpful'           => ['💡', 'neutral'],
    'tip_share'             => ['💡', 'neutral'],
    'refer_active'          => ['🤝', 'success'],
];

// Sentinel history from points_ledger
$pl = $conn->query("
    SELECT pl.action_id, pl.points, pl.note, pl.status, pl.earned_at, pa.label
    FROM points_ledger pl
    LEFT JOIN point_actions pa ON pa.action_id = pl.action_id
    WHERE pl.citizen_bcn = '$bcn'
    ORDER BY pl.earned_at DESC
    LIMIT 60
");
while ($r = $pl->fetch_assoc()) {
    $map    = $icon_map[$r['action_id']] ?? ['📌', 'neutral'];
    $label  = $r['label'] ?? ucwords(str_replace('_', ' ', $r['action_id']));
    $note   = $r['note'] && $r['note'] !== $r['label'] ? " — {$r['note']}" : '';
    $status = $r['status'] === 'Reversed' ? 'rejected' : ($r['status'] === 'Pending' ? 'pending' : $map[1]);
    $pts    = $r['status'] === 'Reversed' ? 0 : (int)$r['points'];
    $history[] = [
        'date'   => $r['earned_at'],
        'event'  => $label . $note,
        'type'   => $status,
        'points' => $pts,
        'icon'   => $map[0],
        'pool'   => 'sentinel',
    ];
}

// Donor history from donor_points_log
$dh = $conn->query("
    SELECT points, reason, created_at
    FROM donor_points_log
    WHERE citizen_bcn='$bcn'
    ORDER BY created_at DESC
    LIMIT 40
");
while ($r = $dh->fetch_assoc()) {
    $pts    = (int)$r['points'];
    $reason = strtolower($r['reason']);
    $isFinancial = strpos($reason, 'financial') !== false || strpos($reason, 'donation of') !== false;
    $history[] = [
        'date'   => $r['created_at'],
        'event'  => $r['reason'],
        'type'   => $pts > 0 ? 'success' : 'rejected',
        'points' => $pts,
        'icon'   => $isFinancial ? '💰' : ($pts < 0 ? '⚠️' : '🩸'),
        'pool'   => 'donor',
    ];
}

// Community Fund donation history
$cfh = $conn->query("
    SELECT amount, payment_method, trxid, note, points_awarded, created_at
    FROM community_fund_donations
    WHERE donor_bcn='$bcn' AND status='confirmed'
    ORDER BY created_at DESC
    LIMIT 40
");
while ($r = $cfh->fetch_assoc()) {
    $note_txt = $r['note'] ? " — {$r['note']}" : '';
    $method   = strtoupper($r['payment_method']);
    $history[] = [
        'date'   => $r['created_at'],
        'event'  => "Community Cleaning Fund — ৳" . number_format((float)$r['amount'], 0) . " via {$method}{$note_txt}",
        'type'   => 'success',
        'points' => (int)$r['points_awarded'],
        'icon'   => '🌿',
        'pool'   => 'community_fund',
    ];
}

// Sort combined history by date desc
usort($history, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
$history = array_slice($history, 0, 80);

echo json_encode([
    // ── Grand total
    'total_points'      => $total_points,
    'citizen_points'    => $citizen_points,       // sentinel pool
    'donor_points'      => $donor_points_total,   // donor pool
    'tier'              => $tier,
    'streak'            => $streak,
    // ── Sentinel breakdown (from points_ledger)
    'site_points'       => $site_points,
    'case_points'       => $case_points,
    'campaign_points'   => $campaign_points,
    'other_points'      => $other_pts,
    'unlogged_points'   => $unlogged_points,
    // ── Donor breakdown (from donor_points_log)
    'blood_points'      => $blood_pts_donor,
    'fin_points'        => $fin_pts_donor,
    // ── Trust
    'trust_score'       => $trust_score,
    'trust_label'       => $trust_label,
    'is_trusted'        => $trust_score >= 85 && $verified_sites >= 2,
    // ── Sentinel counts
    'total_sites'       => $total_sites,
    'verified_sites'    => $verified_sites,
    'rejected_sites'    => $rejected_sites,
    'site_accuracy'     => $site_accuracy,
    'total_cases'       => $total_cases,
    'confirmed_cases'   => $confirmed_cases,
    'case_accuracy'     => $case_accuracy,
    'total_campaigns'   => $total_campaigns,
    'avg_accuracy'      => $avg_accuracy,
    'total_actions'     => $total_actions,
    'stats'             => [],
    // ── Donor profile
    'is_donor'          => $is_donor,
    'reliability'       => $reliability,
    'donor_label'       => $donor_label,
    'blood_group'       => $blood_group,
    'citizen_name'      => $citizen['full_name'] ?? '',
    'blood_donations'   => $blood_donations,
    'blood_committed'   => $blood_committed,
    'fin_donations'     => (int)$fin_row['c'],
    'fin_total_amount'  => (float)$fin_row['total'],
    // ── Community Fund donations
    'cf_donations'      => $cf_count,
    'cf_total_amount'   => $cf_total,
    'cf_points'         => $cf_points,
    // ── History
    'history'           => $history,
]);
?>
