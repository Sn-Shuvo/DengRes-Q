<?php
/**
 * DengResQ | Community Fund — Citizen Action Handler
 * File: citizen/actions/community_fund.php
 *
 * Actions:
 *   donate            — cleaning fund donation (auto-confirmed + points)
 *   get_summary       — global pool stats + citizen totals
 *   get_history       — cleaning fund donation history
 *   get_aid_campaigns — live aid requests with symptom data, notes, donor list
 *   donate_aid        — donate to aid campaign (auto-confirmed, TrxID required)
 *   get_aid_history   — this citizen's aid donations
 *   create_request    — citizen submits a new aid request
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'citizen') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit();
}

require_once '../../config/db_connect.php';
require_once '../../includes/gamification.php';
require_once '../../includes/quest_engine.php';
require_once '../../includes/chest_engine.php';

$bcn    = $conn->real_escape_string($_SESSION['user_id']);
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// ── GET SUMMARY ───────────────────────────────────────────────────────────────
if ($action === 'get_summary') {
    $pool = $conn->query("
        SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN amount ELSE 0 END),0) AS total_in,
               COUNT(CASE WHEN status='confirmed' THEN 1 END) AS donor_count
        FROM community_fund_donations
    ")->fetch_assoc();

    $spent = $conn->query("SELECT COALESCE(SUM(amount),0) AS total_spent FROM community_fund_spending")->fetch_assoc();

    $mine = $conn->query("
        SELECT COALESCE(SUM(amount),0) AS my_total, COUNT(*) AS my_count,
               COALESCE(SUM(points_awarded),0) AS my_points
        FROM community_fund_donations WHERE donor_bcn='$bcn' AND status='confirmed'
    ")->fetch_assoc();

    $aid_count = (int)$conn->query("
        SELECT COUNT(*) AS c FROM financial_donations WHERE donor_bcn='$bcn' AND status='confirmed'
    ")->fetch_assoc()['c'];

    echo json_encode([
        'success'        => true,
        'pool_total'     => (float)$pool['total_in'],
        'pool_spent'     => (float)$spent['total_spent'],
        'pool_available' => max(0, (float)$pool['total_in'] - (float)$spent['total_spent']),
        'donor_count'    => (int)$pool['donor_count'],
        'my_total'       => (float)$mine['my_total'],
        'my_count'       => (int)$mine['my_count'],
        'my_points'      => (int)$mine['my_points'],
        'aid_donations'  => $aid_count,
    ]);
    exit();
}

// ── GET HISTORY (cleaning fund) ───────────────────────────────────────────────
if ($action === 'get_history') {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $rows = $conn->query("
        SELECT id, amount, payment_method, trxid, note, status, points_awarded, created_at
        FROM community_fund_donations WHERE donor_bcn='$bcn'
        ORDER BY created_at DESC LIMIT $limit OFFSET $offset
    ");
    $donations = [];
    while ($r = $rows->fetch_assoc()) $donations[] = $r;

    $total = (int)$conn->query(
        "SELECT COUNT(*) AS c FROM community_fund_donations WHERE donor_bcn='$bcn'"
    )->fetch_assoc()['c'];

    echo json_encode([
        'success'     => true,
        'donations'   => $donations,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => max(1, ceil($total / $limit)),
    ]);
    exit();
}

// ── DONATE (cleaning fund, auto-confirmed) ────────────────────────────────────
if ($action === 'donate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = round(floatval($_POST['amount'] ?? 0), 2);
    $method = $conn->real_escape_string($_POST['payment_method'] ?? '');
    $trxid  = trim($conn->real_escape_string($_POST['trxid'] ?? ''));
    $note   = $conn->real_escape_string(substr($_POST['note'] ?? '', 0, 255));

    if ($amount < 10)    { echo json_encode(['success'=>false,'message'=>'Minimum donation is ৳10.']); exit(); }
    if ($amount > 100000){ echo json_encode(['success'=>false,'message'=>'Maximum single donation is ৳1,00,000.']); exit(); }
    if (!in_array($method, ['bkash','nagad','rocket','upay'])) { echo json_encode(['success'=>false,'message'=>'Invalid payment method.']); exit(); }
    if (strlen($trxid) < 6) { echo json_encode(['success'=>false,'message'=>'Transaction ID must be at least 6 characters.']); exit(); }

    if ($conn->query("SELECT id FROM community_fund_donations WHERE trxid='$trxid'")->fetch_assoc() ||
        $conn->query("SELECT id FROM financial_donations WHERE trxid='$trxid'")->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'This Transaction ID has already been used.']); exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO community_fund_donations (donor_bcn,amount,payment_method,trxid,note,status,points_awarded)
        VALUES (?,?,?,?,?,'confirmed',0)
    ");
    $stmt->bind_param("sdsss", $bcn, $amount, $method, $trxid, $note);
    if (!$stmt->execute()) { echo json_encode(['success'=>false,'message'=>'Database error: '.$stmt->error]); exit(); }
    $donation_id = $conn->insert_id;

    $result    = Gamification::award($conn, $bcn, 'community_fund_donate', $donation_id, [
        'note' => 'Community Fund donation ৳'.number_format($amount, 0)
    ]);
    QuestEngine::onAction($conn, $bcn, 'community_fund_donate');
    $base_pts  = $result['points'] ?? 20;
    $bonus_pts = floor($amount / 50);
    $total_pts = $base_pts;
    if ($bonus_pts > 0) {
        $safe_note = $conn->real_escape_string('Fund donation bonus (৳'.number_format($amount,0).')');
        $conn->query("INSERT INTO points_ledger (citizen_bcn,action_id,points,reference_id,note,status) VALUES ('$bcn','community_fund_donate',$bonus_pts,$donation_id,'$safe_note','Awarded')");
        $conn->query("UPDATE citizens SET points=points+$bonus_pts WHERE bcn='$bcn'");
        // Keep gamification total in sync so ChestEngine::syncChests() sees the correct total
        $conn->query("UPDATE citizen_gamification SET total_points=total_points+$bonus_pts, weekly_points=weekly_points+$bonus_pts, monthly_points=monthly_points+$bonus_pts WHERE citizen_bcn='$bcn'");
        $total_pts += $bonus_pts;
        // BUG FIX: sync chest unlocks for bonus pts that bypassed Gamification::award()
        ChestEngine::syncChests($conn, $bcn);
    }
    $conn->query("UPDATE community_fund_donations SET points_awarded=$total_pts WHERE id=$donation_id");
    $conn->query("INSERT INTO community_feed (event_type,reference_id,latitude,longitude) VALUES ('management',$donation_id,(SELECT latitude FROM citizens WHERE bcn='$bcn'),(SELECT longitude FROM citizens WHERE bcn='$bcn'))");

    echo json_encode([
        'success'       => true,
        'message'       => '✅ Donation confirmed! Thank you.',
        'points_earned' => $total_pts,
        'tier_up'       => $result['tier_up'] ?? false,
        'badges'        => $result['badges']  ?? [],
        'donation_id'   => $donation_id,
    ]);
    exit();
}

// ── CREATE AID REQUEST ────────────────────────────────────────────────────────
if ($action === 'create_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Must have an active Suspected or High Risk dengue case
    $has_case = $conn->query("
        SELECT check_id FROM symptom_checks
        WHERE reporter_bcn='$bcn'
          AND risk_level IN ('Suspected','High Risk')
          AND case_status NOT IN ('Recovered')
        LIMIT 1
    ")->fetch_assoc();

    if (!$has_case) {
        echo json_encode(['success'=>false,'message'=>'You must have an active Suspected or High Risk dengue case to request financial aid.']); exit();
    }

    // No existing pending or live request
    $existing = $conn->query("
        SELECT id FROM financial_aid_requests
        WHERE requester_bcn='$bcn' AND status IN ('Pending_Verification','Live')
        LIMIT 1
    ")->fetch_assoc();
    if ($existing) {
        echo json_encode(['success'=>false,'message'=>'You already have an active aid request. Please wait for it to be processed.']); exit();
    }

    $hospital     = $conn->real_escape_string($_POST['hospital_name']  ?? '');
    $amount       = round(floatval($_POST['amount_needed'] ?? 0), 2);
    $bkash        = $conn->real_escape_string($_POST['bkash_number']   ?? '');
    $description  = $conn->real_escape_string(substr($_POST['description']  ?? '', 0, 1000));
    $patient_note = $conn->real_escape_string(substr($_POST['patient_note'] ?? '', 0, 500));

    if (!$hospital)   { echo json_encode(['success'=>false,'message'=>'Hospital name is required.']); exit(); }
    if ($amount < 100){ echo json_encode(['success'=>false,'message'=>'Minimum aid amount is ৳100.']); exit(); }
    if ($amount > 500000){ echo json_encode(['success'=>false,'message'=>'Maximum aid request is ৳5,00,000.']); exit(); }
    if (!preg_match('/^01[3-9][0-9]{8}$/', $bkash)) {
        echo json_encode(['success'=>false,'message'=>'Please enter a valid bKash/Nagad/Rocket number (01XXXXXXXXX).']); exit();
    }

    $ok = $conn->query("
        INSERT INTO financial_aid_requests
            (requester_bcn, hospital_name, amount_needed, bkash_number, description, patient_note, status)
        VALUES ('$bcn','$hospital',$amount,'$bkash','$description','$patient_note','Pending_Verification')
    ");

    echo json_encode([
        'success' => (bool)$ok,
        'message' => $ok
            ? '✅ Aid request submitted! A Health Officer will review and verify your case shortly.'
            : 'Database error: '.$conn->error,
    ]);
    exit();
}

// ── GET AID CAMPAIGNS ─────────────────────────────────────────────────────────
if ($action === 'get_aid_campaigns') {
    $result = $conn->query("
        SELECT
            far.id,
            far.requester_bcn,
            far.hospital_name,
            far.amount_needed,
            far.amount_confirmed,
            far.bkash_number,
            far.description,
            far.patient_note,
            far.ho_note,
            far.created_at,
            far.status,
            cit.full_name  AS requester_name,
            cit.age,
           bg.group_name AS blood_group,
            COALESCE(SUM(CASE WHEN fd.status='confirmed' THEN fd.amount ELSE 0 END),0) AS total_confirmed
        FROM financial_aid_requests far
        LEFT JOIN citizens cit ON far.requester_bcn = cit.bcn
        LEFT JOIN blood_groups bg ON bg.blood_group_id = cit.blood_group_id
        LEFT JOIN financial_donations fd ON fd.request_id = far.id
        WHERE far.status IN ('Live','Goal_Reached')
        GROUP BY far.id
        ORDER BY far.status ASC, far.created_at DESC
    ");

    if (!$result) {
        echo json_encode(['success'=>false,'message'=>'Query error: '.$conn->error]); exit();
    }

    $campaigns = [];
    while ($row = $result->fetch_assoc()) {
        $rid = $conn->real_escape_string($row['requester_bcn']);

        // Latest dengue symptom check
        $case = $conn->query("
            SELECT risk_level, risk_score, case_status, test_date,
                   ho_notes, hospital_notes, hospital_report,
                   fever, headache, eye_pain, muscle_pain, rash,
                   nausea, bleeding, fatigue, fever_days, fever_f
            FROM symptom_checks
            WHERE reporter_bcn='$rid'
              AND risk_level IN ('Suspected','High Risk')
            ORDER BY test_date DESC LIMIT 1
        ")->fetch_assoc();

        $row['risk_level']     = $case['risk_level']     ?? null;
        $row['risk_score']     = $case['risk_score']     ?? null;
        $row['case_status']    = $case['case_status']    ?? null;
        $row['test_date']      = $case['test_date']      ?? null;
        $row['ho_notes']       = $case['ho_notes']       ?? null;
        $row['hospital_notes'] = $case['hospital_notes'] ?? null;
        $row['hospital_report']= $case['hospital_report']?? null;

        // Symptoms list
        $symptoms = [];
        if ($case) {
            if ($case['fever'])       $symptoms[] = 'Fever '.($case['fever_f'] ? $case['fever_f'].'°F' : $case['fever'].'°C');
            if ($case['headache'])    $symptoms[] = 'Headache';
            if ($case['eye_pain'])    $symptoms[] = 'Eye Pain';
            if ($case['muscle_pain']) $symptoms[] = 'Muscle Pain';
            if ($case['rash'])        $symptoms[] = 'Rash';
            if ($case['nausea'])      $symptoms[] = 'Nausea';
            if ($case['bleeding'])    $symptoms[] = 'Bleeding';
            if ($case['fatigue'])     $symptoms[] = 'Fatigue';
            if ($case['fever_days'])  $symptoms[] = $case['fever_days'].' days fever';
        }
        $row['symptoms'] = $symptoms;

        // Latest test result
        $test = $conn->query("
            SELECT tr.result_summary, tr.diagnosis_note, tr.ho_report_note,
                   tr.created_at AS test_result_date
            FROM test_results tr
            JOIN test_appointments ta ON ta.id = tr.appointment_id
            WHERE ta.citizen_id = '$rid'
            ORDER BY tr.created_at DESC LIMIT 1
        ")->fetch_assoc();
        $row['test_result']         = $test['result_summary']   ?? null;
        $row['test_diagnosis_note'] = $test['diagnosis_note']   ?? null;
        $row['test_ho_note']        = $test['ho_report_note']   ?? null;
        $row['test_result_date']    = $test['test_result_date'] ?? null;

        // Use the stored amount_confirmed (kept in sync on every donation)
        $confirmed = (float)$row['amount_confirmed'];
        $needed    = (float)$row['amount_needed'];
        $row['amount_confirmed'] = $confirmed;
        $row['remaining']        = max(0, $needed - $confirmed);
        $row['progress']         = $needed > 0 ? min(100, round(($confirmed / $needed) * 100)) : 0;

        // Has this citizen already donated?
        $already = $conn->query("
            SELECT id FROM financial_donations
            WHERE request_id={$row['id']} AND donor_bcn='$bcn' AND status='confirmed'
            LIMIT 1
        ")->fetch_assoc();
        $row['i_donated'] = $already ? 1 : 0;

        // Donor list (top 8 by amount, confirmed)
        $donors_res = $conn->query("
            SELECT c.full_name, fd.amount, fd.locked_at AS donated_at
            FROM financial_donations fd
            LEFT JOIN citizens c ON c.bcn = fd.donor_bcn
            WHERE fd.request_id = {$row['id']} AND fd.status = 'confirmed'
            ORDER BY fd.amount DESC LIMIT 8
        ");
        $donors = [];
        while ($d = $donors_res->fetch_assoc()) $donors[] = $d;
        $row['donors'] = $donors;

        $row['donor_count'] = (int)$conn->query("
            SELECT COUNT(*) AS c FROM financial_donations
            WHERE request_id={$row['id']} AND status='confirmed'
        ")->fetch_assoc()['c'];

        $campaigns[] = $row;
    }

    // Citizen's own latest request (for the request form section)
    $my_request = $conn->query("
        SELECT id, status, amount_needed, hospital_name, created_at,
               COALESCE(amount_confirmed,0) AS amount_confirmed
        FROM financial_aid_requests
        WHERE requester_bcn='$bcn'
        ORDER BY created_at DESC LIMIT 1
    ")->fetch_assoc();

    echo json_encode([
        'success'    => true,
        'campaigns'  => $campaigns,
        'my_request' => $my_request,
    ]);
    exit();
}

// ── DONATE AID (auto-confirmed) ───────────────────────────────────────────────
if ($action === 'donate_aid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $req_id = intval($_POST['request_id'] ?? 0);
    $amount = round(floatval($_POST['amount'] ?? 0), 2);
    $method = $conn->real_escape_string($_POST['payment_method'] ?? '');
    $trxid  = trim($conn->real_escape_string($_POST['trxid'] ?? ''));
    $note   = $conn->real_escape_string(substr($_POST['note'] ?? '', 0, 255));

    if (!$req_id)         { echo json_encode(['success'=>false,'message'=>'Invalid aid campaign.']); exit(); }
    if ($amount < 10)     { echo json_encode(['success'=>false,'message'=>'Minimum donation is ৳10.']); exit(); }
    if ($amount > 100000) { echo json_encode(['success'=>false,'message'=>'Maximum single donation is ৳1,00,000.']); exit(); }
    if (!in_array($method, ['bkash','nagad','rocket','upay'])) { echo json_encode(['success'=>false,'message'=>'Invalid payment method.']); exit(); }
    if (strlen($trxid) < 6) { echo json_encode(['success'=>false,'message'=>'Transaction ID must be at least 6 characters.']); exit(); }

    // ── Duplicate TrxID guard — check both donation tables ───────────────────
    if ($conn->query("SELECT id FROM community_fund_donations WHERE trxid='$trxid'")->fetch_assoc() ||
        $conn->query("SELECT id FROM financial_donations WHERE trxid='$trxid'")->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'This Transaction ID has already been used.']); exit();
    }

    // Fetch request — must be Live, check remaining
    $req = $conn->query("
        SELECT amount_needed, amount_confirmed, status
        FROM financial_aid_requests
        WHERE id=$req_id AND status='Live'
    ")->fetch_assoc();

    if (!$req) { echo json_encode(['success'=>false,'message'=>'This campaign is not currently accepting donations.']); exit(); }

    $remaining = (float)$req['amount_needed'] - (float)$req['amount_confirmed'];
    if ($amount > $remaining + 0.01) {
        echo json_encode(['success'=>false,'message'=>'Only ৳'.number_format($remaining,0).' remaining. Please adjust your amount.']); exit();
    }

    // ── Insert as confirmed immediately (prepared statement) ─────────────────
    $stmt = $conn->prepare("
        INSERT INTO financial_donations
            (request_id, donor_bcn, amount, payment_method, trxid, status, confirmed_at, points_awarded)
        VALUES (?, ?, ?, ?, ?, 'confirmed', NOW(), 0)
    ");
    $stmt->bind_param("isdss", $req_id, $bcn, $amount, $method, $trxid);

    if (!$stmt->execute()) {
        // errno 1062 = duplicate entry (UNIQUE constraint on trxid hit at DB level)
        if ($conn->errno === 1062) {
            echo json_encode(['success'=>false,'message'=>'Duplicate Transaction ID rejected. Already used.']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Database error: '.$stmt->error]);
        }
        exit();
    }
    $donation_id = $conn->insert_id;

    // Update confirmed total on parent (amount_confirmed column)
    $conn->query("
        UPDATE financial_aid_requests
        SET amount_confirmed = COALESCE(amount_confirmed,0) + $amount,
            amount_pending   = GREATEST(0, COALESCE(amount_pending,0))
        WHERE id=$req_id
    ");

    // Check if goal reached
    $updated = $conn->query("SELECT amount_needed, amount_confirmed FROM financial_aid_requests WHERE id=$req_id")->fetch_assoc();
    if ((float)$updated['amount_confirmed'] >= (float)$updated['amount_needed']) {
        $conn->query("UPDATE financial_aid_requests SET status='Goal_Reached' WHERE id=$req_id");
    }

    // Award points via Gamification engine
    $result    = Gamification::award($conn, $bcn, 'financial_donate', $donation_id, [
        'note' => 'Aid campaign donation ৳'.number_format($amount, 0)
    ]);
    QuestEngine::onAction($conn, $bcn, 'financial_donate');
    $base_pts  = $result['points'] ?? 20;
    $bonus_pts = floor($amount / 100); // 1pt per ৳100
    $total_pts = $base_pts;
    if ($bonus_pts > 0) {
        $safe_note = $conn->real_escape_string('Aid donation bonus (৳'.number_format($amount,0).')');
        $conn->query("INSERT INTO points_ledger (citizen_bcn,action_id,points,reference_id,note,status) VALUES ('$bcn','financial_donate',$bonus_pts,$donation_id,'$safe_note','Awarded')");
        $conn->query("UPDATE citizens SET points=points+$bonus_pts WHERE bcn='$bcn'");
        // Keep gamification total in sync so ChestEngine::syncChests() sees the correct total
        $conn->query("UPDATE citizen_gamification SET total_points=total_points+$bonus_pts, weekly_points=weekly_points+$bonus_pts, monthly_points=monthly_points+$bonus_pts WHERE citizen_bcn='$bcn'");
        $total_pts += $bonus_pts;
        // BUG FIX: sync chest unlocks for bonus pts that bypassed Gamification::award()
        ChestEngine::syncChests($conn, $bcn);
    }
    $conn->query("UPDATE financial_donations SET points_awarded=$total_pts WHERE id=$donation_id");

    echo json_encode([
        'success'       => true,
        'message'       => '✅ Donation confirmed! Thank you for helping this patient.',
        'points_earned' => $total_pts,
        'tier_up'       => $result['tier_up'] ?? false,
        'badges'        => $result['badges']  ?? [],
        'donation_id'   => $donation_id,
        'remaining'     => max(0, $remaining - $amount),
    ]);
    exit();
}

// ── GET AID HISTORY ───────────────────────────────────────────────────────────
if ($action === 'get_aid_history') {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $rows = $conn->query("
        SELECT fd.id, fd.request_id, fd.amount, fd.payment_method,
               fd.status, fd.locked_at, fd.confirmed_at, fd.points_awarded,
               far.hospital_name, far.amount_needed, far.status AS campaign_status,
               cit.full_name AS requester_name
        FROM financial_donations fd
        JOIN financial_aid_requests far ON far.id = fd.request_id
        LEFT JOIN citizens cit ON cit.bcn = far.requester_bcn
        WHERE fd.donor_bcn = '$bcn'
        ORDER BY fd.locked_at DESC
        LIMIT $limit OFFSET $offset
    ");

    $donations = [];
    while ($r = $rows->fetch_assoc()) $donations[] = $r;

    $total = (int)$conn->query(
        "SELECT COUNT(*) AS c FROM financial_donations WHERE donor_bcn='$bcn'"
    )->fetch_assoc()['c'];

    echo json_encode([
        'success'     => true,
        'donations'   => $donations,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => max(1, ceil($total / $limit)),
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
