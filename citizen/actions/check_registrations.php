<?php
session_start();
require_once '../../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'citizen') {
    echo json_encode([]); exit();
}

$bcn = $_SESSION['user_id'];

// Return only ACTIVE registrations — cancelled ones no longer block UI
$result = $conn->query("
    SELECT campaign_id FROM campaigns_reg
    WHERE citizen_bcn = '$bcn'
      AND status = 'Active'
");

$joined = [];
while ($r = $result->fetch_assoc()) {
    $joined[] = intval($r['campaign_id']);
}

echo json_encode($joined);
?>
