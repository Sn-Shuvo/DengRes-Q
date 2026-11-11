<?php
session_start();
require_once '../../config/db_connect.php';
header('Content-Type: application/json');

// Allow fetching any citizen's public badges by bcn
$bcn = $conn->real_escape_string($_GET['bcn'] ?? '');
if (!$bcn) { echo json_encode(['badges' => []]); exit(); }

$res = $conn->query("
    SELECT b.badge_id, b.name, b.icon, b.description, b.rarity, b.category,
           cb.earned_at
    FROM citizen_badges cb
    JOIN badges b ON b.badge_id = cb.badge_id
    WHERE cb.citizen_bcn = '$bcn'
    ORDER BY
        FIELD(b.rarity,'Legendary','Epic','Rare','Common'),
        cb.earned_at DESC
");

$badges = [];
while ($r = $res->fetch_assoc()) {
    $badges[] = [
        'badge_id'    => $r['badge_id'],
        'name'        => $r['name'],
        'icon'        => $r['icon'],
        'description' => $r['description'],
        'rarity'      => $r['rarity'],
        'category'    => $r['category'],
        'earned_at'   => $r['earned_at'],
    ];
}

echo json_encode(['badges' => $badges]);
