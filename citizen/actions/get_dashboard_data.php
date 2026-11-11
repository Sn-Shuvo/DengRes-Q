<?php
session_start();
require_once '../../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$bcn = $_SESSION['user_id'];

// Get citizen details
$citizen = $conn->query("
    SELECT c.full_name, c.latitude, c.longitude, c.location_label,
           bg.group_name AS blood_group, c.age, c.points, c.trust_score
    FROM citizens c
    LEFT JOIN blood_groups bg ON bg.blood_group_id = c.blood_group_id
    WHERE c.bcn='$bcn'
")->fetch_assoc();

$lat = floatval($citizen['latitude']  ?? 23.8103);
$lng = floatval($citizen['longitude'] ?? 90.4125);

// ── STAT CARDS ────────────────────────────────────────────────────────────────

// Active cases nearby (within 5km using Haversine)
$nearby_cases = $conn->query("
    SELECT COUNT(*) AS c FROM symptom_checks sc
    LEFT JOIN citizens cit ON sc.reporter_bcn = cit.bcn
    WHERE sc.risk_level IN ('Suspected','High Risk')
    AND sc.case_status NOT IN ('Recovered')
    AND (
        6371 * ACOS(
            COS(RADIANS($lat)) * COS(RADIANS(
                CASE WHEN sc.latitude != 0 THEN sc.latitude ELSE cit.latitude END
            )) *
            COS(RADIANS(
                CASE WHEN sc.longitude != 0 THEN sc.longitude ELSE cit.longitude END
            ) - RADIANS($lng)) +
            SIN(RADIANS($lat)) * SIN(RADIANS(
                CASE WHEN sc.latitude != 0 THEN sc.latitude ELSE cit.latitude END
            ))
        )
    ) <= 5
")->fetch_assoc()['c'];

// Breeding sites pending near citizen
$nearby_sites = $conn->query("
    SELECT COUNT(*) AS c FROM breeding_sites
    WHERE verify_status = 'Pending'
")->fetch_assoc()['c'];

// Active missions (field teams working)
$active_missions = $conn->query("
    SELECT COUNT(*) AS c FROM ho_missions
    WHERE status = 'On-Mission'
")->fetch_assoc()['c'];

// Total confirmed dengue cases
$confirmed_cases = $conn->query("
    SELECT COUNT(*) AS c FROM symptom_checks
    WHERE case_status = 'Confirmed'
")->fetch_assoc()['c'];

// Risk level logic
$risk_level = 'LOW';
$risk_color = '#10b981';
if ($nearby_cases >= 5 || $nearby_sites >= 3) {
    $risk_level = 'MODERATE';
    $risk_color = '#f59e0b';
}
if ($nearby_cases >= 10 || $confirmed_cases >= 5) {
    $risk_level = 'HIGH';
    $risk_color = '#ef4444';
}

// ── CHECK IF CITIZEN IS AFFECTED ──────────────────────────────────────────────
// Now also pulls live hospital_reports data so the citizen card shows
// real-time admission status written by the hospital.
$my_active_case = $conn->query("
    SELECT sc.check_id, sc.risk_level, sc.risk_score, sc.case_status,
           sc.fever_f, sc.fever_days, sc.test_date,
           sc.ho_notes, sc.referred_hospital,
           sc.hospital_status,
           h.h_name                AS hospital_name,
           hr.report_id            AS hr_report_id,
           hr.patient_status       AS hr_patient_status,
           hr.is_active            AS hr_is_active,
           hr.reported_at          AS hr_reported_at,
           h_hr.h_name             AS hr_hospital_name
    FROM symptom_checks sc
    LEFT JOIN hospitals h       ON sc.referred_hospital = h.h_system_id
    LEFT JOIN hospital_reports hr
           ON hr.patient_bcn  = sc.reporter_bcn
          AND hr.is_active    = 1
    LEFT JOIN hospitals h_hr   ON h_hr.h_system_id = hr.h_system_id
    WHERE sc.reporter_bcn = '$bcn'
      AND sc.case_status NOT IN ('Recovered')
    ORDER BY sc.test_date DESC
    LIMIT 1
")->fetch_assoc();

// ── WEEKLY TREND ──────────────────────────────────────────────────────────────
$weekly_trend = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime("-$i days"));

    $suspected = $conn->query("
        SELECT COUNT(*) AS c FROM symptom_checks
        WHERE DATE(test_date) = '$date'
        AND risk_level = 'Suspected'
    ")->fetch_assoc()['c'];

    $high_risk = $conn->query("
        SELECT COUNT(*) AS c FROM symptom_checks
        WHERE DATE(test_date) = '$date'
        AND risk_level = 'High Risk'
    ")->fetch_assoc()['c'];

    $weekly_trend[] = [
        'day'       => $label,
        'suspected' => intval($suspected),
        'high_risk' => intval($high_risk),
    ];
}

// ── LIVE FEED ─────────────────────────────────────────────────────────────────
$feed_items = [];

// Recent breeding site reports
$sites = $conn->query("
    SELECT location_text, severity, reported_at, verify_status
    FROM breeding_sites
    ORDER BY reported_at DESC LIMIT 4
");
while ($s = $sites->fetch_assoc()) {
    $time = date('g:i A', strtotime($s['reported_at']));
    $type = $s['verify_status'] === 'Verified' ? 'success' : 'alert';
    $msg  = $s['verify_status'] === 'Verified'
        ? "✅ Verified: Breeding site cleared near " . substr($s['location_text'],0,30) . "..."
        : "🟠 New breeding site reported — " . strtoupper($s['severity']) . " severity.";
    $feed_items[] = ['type'=>$type, 'msg'=>$msg, 'time'=>$time];
}

// Recent symptom reports
$cases = $conn->query("
    SELECT sc.risk_level, sc.test_date, cit.location_label
    FROM symptom_checks sc
    LEFT JOIN citizens cit ON sc.reporter_bcn = cit.bcn
    WHERE sc.risk_level IN ('Suspected','High Risk')
    ORDER BY sc.test_date DESC LIMIT 3
");
while ($c = $cases->fetch_assoc()) {
    $time = date('g:i A', strtotime($c['test_date']));
    $feed_items[] = [
        'type' => $c['risk_level'] === 'High Risk' ? 'alert' : 'warning',
        'msg'  => "🤒 " . $c['risk_level'] . " dengue case reported nearby.",
        'time' => $time
    ];
}

// Active campaigns
$campaigns = $conn->query("
    SELECT title, start_time FROM campaigns
    WHERE start_time >= NOW()
    ORDER BY start_time ASC LIMIT 2
");
while ($camp = $campaigns->fetch_assoc()) {
    $feed_items[] = [
        'type' => 'info',
        'msg'  => "📢 Campaign: " . $camp['title'],
        'time' => date('M j', strtotime($camp['start_time']))
    ];
}

// ── MAP DATA ──────────────────────────────────────────────────────────────────

// Nearby breeding sites for map
$map_sites = [];
$mSites = $conn->query("
    SELECT bs.site_id, bs.severity, bs.verify_status, bs.latitude, bs.longitude,
           bs.location_text, bs.is_verified,
           COALESCE(bsc.ho_expert_correction, bsc.ho_type_guess) AS site_type
    FROM breeding_sites bs
    LEFT JOIN breeding_site_classifications bsc ON bsc.site_id = bs.site_id
    WHERE bs.latitude != 0 AND bs.longitude != 0
    ORDER BY bs.reported_at DESC LIMIT 30
");
while ($r = $mSites->fetch_assoc()) $map_sites[] = $r;

// Nearby cases for map
$map_cases = [];
$mCases = $conn->query("
    SELECT sc.check_id, sc.risk_level, sc.risk_score, sc.case_status,
        CASE WHEN sc.latitude != 0 THEN sc.latitude ELSE cit.latitude END AS latitude,
        CASE WHEN sc.longitude != 0 THEN sc.longitude ELSE cit.longitude END AS longitude
    FROM symptom_checks sc
    LEFT JOIN citizens cit ON sc.reporter_bcn = cit.bcn
    WHERE sc.risk_level IN ('Suspected','High Risk')
    AND sc.case_status != 'Recovered'
    ORDER BY sc.test_date DESC LIMIT 30
");
while ($r = $mCases->fetch_assoc()) $map_cases[] = $r;

// Upcoming campaigns for map
$map_campaigns = [];
$mCamp = $conn->query("
    SELECT campaign_id, title, latitude, longitude, coverage_range
    FROM campaigns
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    LIMIT 10
");
while ($r = $mCamp->fetch_assoc()) $map_campaigns[] = $r;

// ── MAP: Active hospital reports (admitted patients with known location) ───────
// These render as hospital pins on the Leaflet map so citizens can see
// which hospitals currently have active dengue admissions nearby.
$map_hospital_reports = [];
$mHR = $conn->query("
    SELECT hr.latitude, hr.longitude, hr.patient_status, hr.reported_at,
           h.h_name, h.h_system_id
    FROM hospital_reports hr
    JOIN hospitals h ON h.h_system_id = hr.h_system_id
    WHERE hr.is_active  = 1
      AND hr.latitude  != 0
      AND hr.longitude != 0
    ORDER BY hr.reported_at DESC
    LIMIT 50
");
while ($r = $mHR->fetch_assoc()) $map_hospital_reports[] = $r;

// ── MY REPORTS STRIP ──────────────────────────────────────────────────────────
$my_sites = [];
$mySites = $conn->query("
    SELECT bs.site_id, bs.severity, bs.verify_status,
           bs.reported_at, bs.location_text,
           COALESCE(bsc.ho_expert_correction, bsc.ho_type_guess) AS site_type,
           m.status AS mission_status
    FROM breeding_sites bs
    LEFT JOIN breeding_site_classifications bsc ON bsc.site_id = bs.site_id
    LEFT JOIN ho_missions m ON m.site_id = bs.site_id AND m.status != 'Expired'
    WHERE bs.reporter_bcn = '$bcn'
    ORDER BY bs.reported_at DESC LIMIT 5
");
while ($r = $mySites->fetch_assoc()) $my_sites[] = $r;

$my_symptoms = [];
$mySymps = $conn->query("
    SELECT check_id, risk_level, risk_score, fever_f, fever_days,
           case_status, ho_notes, referred_hospital, hospital_status, test_date
    FROM symptom_checks
    WHERE reporter_bcn = '$bcn'
    ORDER BY test_date DESC LIMIT 3
");
while ($r = $mySymps->fetch_assoc()) $my_symptoms[] = $r;

// ── MY TESTS ──────────────────────────────────────────────────────────────────
$my_tests = [];
$myTests = $conn->query("
    SELECT tr.id, tr.status, tr.created_at,
           tc.test_name, tc.cost,
           h.h_name AS hospital_name,
           ta.appointment_date, ta.appointment_time
    FROM test_recommendations tr
    JOIN test_catalog tc ON tr.test_catalog_id = tc.id
    JOIN hospitals h ON tr.hospital_id = h.h_system_id
    LEFT JOIN test_appointments ta ON ta.recommendation_id = tr.id
    WHERE tr.citizen_id = '$bcn'
    AND tr.status NOT IN ('completed','cancelled')
    ORDER BY tr.created_at DESC LIMIT 3
");
while ($r = $myTests->fetch_assoc()) $my_tests[] = $r;

echo json_encode([
    'citizen'               => $citizen,
    'risk_level'            => $risk_level,
    'risk_color'            => $risk_color,
    'nearby_cases'          => intval($nearby_cases),
    'nearby_sites'          => intval($nearby_sites),
    'active_missions'       => intval($active_missions),
    'confirmed_cases'       => intval($confirmed_cases),
    'my_active_case'        => $my_active_case,
    'weekly_trend'          => $weekly_trend,
    'feed_items'            => $feed_items,
    'map_sites'             => $map_sites,
    'map_cases'             => $map_cases,
    'map_campaigns'         => $map_campaigns,
    'map_hospital_reports'  => $map_hospital_reports,   // NEW
    'my_sites'              => $my_sites,
    'my_symptoms'           => $my_symptoms,
    'my_tests'              => $my_tests,
]);
?>
