<?php
/**
 * DengResQ | Get Area Alerts for Citizen
 * File: DengResQ/citizen/actions/get_alerts.php
 *
 * Returns active alerts within citizen's 5km radius.
 * Also runs auto-alert engine and marks new alerts for toast.
 */
session_start();
require_once '../../config/db_connect.php';
require_once '../../includes/auto_alerts.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'alerts'=>[]]); exit();
}

$bcn = $conn->real_escape_string($_SESSION['user_id']);

// Get citizen's coordinates
$me = $conn->query("
    SELECT latitude, longitude FROM citizens WHERE bcn='$bcn'
")->fetch_assoc();

if (!$me || !$me['latitude'] || !$me['longitude']) {
    echo json_encode(['success'=>true,'alerts'=>[],'new_count'=>0]);
    exit();
}

$lat    = floatval($me['latitude']);
$lng    = floatval($me['longitude']);
$radius = 5;

// Run auto-alert engine
AutoAlerts::check($conn, $lat, $lng, $radius);

// Fetch all active alerts within citizen's radius
$alerts_result = $conn->query("
    SELECT
        aa.alert_id,
        aa.title,
        aa.message,
        aa.severity,
        aa.alert_type,
        aa.trigger_type,
        aa.radius_km,
        aa.expires_at,
        aa.created_at,
        ho.name AS ho_name,
        ho.division,
        (
            6371 * 2 * ASIN(SQRT(
                POW(SIN(RADIANS(aa.latitude  - $lat) / 2), 2) +
                COS(RADIANS($lat)) * COS(RADIANS(aa.latitude)) *
                POW(SIN(RADIANS(aa.longitude - $lng) / 2), 2)
            ))
        ) AS distance_km,
        CASE WHEN cav.id IS NULL THEN 1 ELSE 0 END AS is_new
    FROM area_alerts aa
    LEFT JOIN health_officers ho ON ho.badge_number = aa.ho_badge
    LEFT JOIN citizen_alert_views cav
           ON cav.alert_id = aa.alert_id AND cav.citizen_bcn = '$bcn'
    WHERE aa.is_active = 1
      AND aa.expires_at > NOW()
      AND aa.latitude IS NOT NULL
    HAVING distance_km <= $radius
    ORDER BY
        CASE aa.severity WHEN 'Critical' THEN 1 WHEN 'Warning' THEN 2 ELSE 3 END ASC,
        aa.created_at DESC
    LIMIT 10
");

$alerts  = [];
$new_ids = [];

while ($row = $alerts_result->fetch_assoc()) {
    $alerts[] = [
        'alert_id'    => (int)$row['alert_id'],
        'title'       => $row['title'],
        'message'     => $row['message'],
        'severity'    => $row['severity'],
        'alert_type'  => $row['alert_type'],
        'trigger_type'=> $row['trigger_type'],
        'distance_km' => round((float)$row['distance_km'], 1),
        'expires_at'  => $row['expires_at'],
        'ho_name'     => $row['ho_name'] ?? null,
        'division'    => $row['division'] ?? null,
        'is_new'      => (int)$row['is_new'],
    ];
    if ((int)$row['is_new'] === 1) {
        $new_ids[] = (int)$row['alert_id'];
    }
}

// Mark all as viewed
if (!empty($new_ids)) {
    foreach ($new_ids as $aid) {
        $conn->query("
            INSERT IGNORE INTO citizen_alert_views (citizen_bcn, alert_id)
            VALUES ('$bcn', $aid)
        ");
    }
}

echo json_encode([
    'success'   => true,
    'alerts'    => $alerts,
    'new_count' => count($new_ids),
]);
?>
