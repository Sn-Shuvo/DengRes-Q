<?php
session_start();
require_once '../../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'citizen') {
    echo json_encode(['points' => 0]); exit();
}

$bcn    = $_SESSION['user_id'];
$result = $conn->query("SELECT points FROM citizens WHERE bcn='$bcn'")->fetch_assoc();
echo json_encode(['points' => $result['points'] ?? 0]);
?>
