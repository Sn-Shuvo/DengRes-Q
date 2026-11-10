<?php
session_start();
require_once '../../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'citizen') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$bcn    = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// LOYALTY HELPER: resolve the citizen's current tier and compute discount
// Returns array: [discount_pct, free_checkup_available, tier_id, tier_name]
// ─────────────────────────────────────────────────────────────────────────────
function getLoyaltyDiscount($conn, $bcn) {
    $bcn_e = $conn->real_escape_string($bcn);

    // Read total points from citizen_gamification (single source of truth)
    $cg_row = $conn->query("
        SELECT COALESCE(total_points, 0) AS total_points
        FROM citizen_gamification
        WHERE citizen_bcn = '$bcn_e'
    ")->fetch_assoc();

    $total_points = (int)($cg_row['total_points'] ?? 0);

    $tiers = $conn->query("
        SELECT * FROM loyalty_tiers ORDER BY points_required ASC
    ");

    $current_tier = null;
    while ($t = $tiers->fetch_assoc()) {
        if ($total_points >= (int)$t['points_required']) {
            $current_tier = $t;
        }
    }

    if (!$current_tier) {
        return ['discount_pct' => 0, 'free_available' => false,
                'tier_id' => 'tier_0', 'tier_name' => 'Newcomer'];
    }

    $free_available = false;
    if ((int)$current_tier['free_checkup_monthly'] === 1) {
        $period_key = date('Y-m');
        $used = $conn->query("
            SELECT id FROM citizen_free_checkups
            WHERE citizen_bcn = '$bcn_e' AND period_key = '$period_key'
        ")->fetch_assoc();
        $free_available = !$used;
    }

    return [
        'discount_pct'   => (int)$current_tier['test_discount_pct'],
        'free_available' => $free_available,
        'tier_id'        => $current_tier['tier_id'],
        'tier_name'      => $current_tier['tier_name'],
    ];
}

// ── GET MY TESTS ──────────────────────────────────────────────────────────────
if ($action === 'get_my_tests') {
    $bcn_e = $conn->real_escape_string($bcn);
    $result = $conn->query("
        SELECT
            tr.id,
            tr.batch_id,
            tr.status,
            tr.message,
            tr.created_at,
            tc.test_name,
            tc.cost,
            tc.turnaround_days,
            h.h_name AS hospital_name,
            ta.appointment_date,
            ta.appointment_time,
            ta.slot_notes,
            tres.result_summary,
            tres.result_values,
            tres.diagnosis_note
        FROM test_recommendations tr
        JOIN test_catalog tc ON tr.test_catalog_id = tc.id
        JOIN hospitals h     ON tr.hospital_id = h.h_system_id
        LEFT JOIN test_appointments ta ON ta.recommendation_id = tr.id
        LEFT JOIN test_results tres    ON tres.recommendation_id = tr.id
        WHERE tr.citizen_id = '$bcn_e'
        ORDER BY tr.created_at DESC
    ");
    $rows = [];
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
}

// ── APPLY FOR TEST ─────────────────────────────────────────────────────────────
elseif ($action === 'apply') {
    $bcn_e  = $conn->real_escape_string($bcn);
    $rec_id = intval($_POST['rec_id'] ?? 0);

    $check = $conn->query("
        SELECT id FROM test_recommendations
        WHERE id=$rec_id AND citizen_id='$bcn_e' AND status='pending'
    ")->fetch_assoc();

    if (!$check) {
        echo json_encode(['success' => false, 'message' => 'Recommendation not found.']);
        exit();
    }

    $ok = $conn->query("UPDATE test_recommendations SET status='applied' WHERE id=$rec_id");
    echo json_encode([
        'success' => (bool)$ok,
        'message' => $ok ? 'Applied successfully. Please proceed to payment.' : $conn->error
    ]);
}

// ── SUBMIT PAYMENT (single — backward compat) ─────────────────────────────────
elseif ($action === 'submit_payment') {
    $bcn_e  = $conn->real_escape_string($bcn);
    $rec_id = intval($_POST['rec_id'] ?? 0);
    $method = $conn->real_escape_string($_POST['method'] ?? '');
    $trxid  = $conn->real_escape_string($_POST['trxid']  ?? '');
    $amount = floatval($_POST['amount'] ?? 0);

    if (!in_array($method, ['bkash','nagad','rocket'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment method.']); exit();
    }
    if (!$trxid) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID required.']); exit();
    }

    $rec = $conn->query("
        SELECT id, hospital_id FROM test_recommendations
        WHERE id=$rec_id AND citizen_id='$bcn_e' AND status='applied'
    ")->fetch_assoc();

    if (!$rec) {
        echo json_encode(['success' => false, 'message' => 'Recommendation not found or not in applied status.']); exit();
    }

    $dupCheck = $conn->query("SELECT id FROM test_payments WHERE trxid='$trxid'")->fetch_assoc();
    if ($dupCheck) {
        echo json_encode(['success' => false, 'message' => 'This Transaction ID has already been used.']); exit();
    }

    // ── Apply loyalty discount ─────────────────────────────────────────────
    $loyalty       = getLoyaltyDiscount($conn, $bcn);
    $discount_pct  = $loyalty['discount_pct'];
    $original      = $amount;
    $final_amount  = $amount;
    $is_free       = 0;
    $discount_src  = null;

    if ($loyalty['free_available']) {
        // Free monthly checkup — waive entire amount
        $final_amount = 0;
        $is_free      = 1;
        $discount_pct = 100;
        $discount_src = $conn->real_escape_string($loyalty['tier_id']);
    } elseif ($discount_pct > 0) {
        $final_amount = round($amount * (1 - $discount_pct / 100), 2);
        $discount_src = $conn->real_escape_string($loyalty['tier_id']);
    }

    $hospital_id = $conn->real_escape_string($rec['hospital_id']);

    $ok = $conn->query("
        INSERT INTO test_payments
        (recommendation_id, citizen_id, hospital_id, amount, total_amount,
         original_amount, discount_pct, discount_source, is_free_checkup,
         payment_method, trxid, status)
        VALUES ($rec_id, '$bcn_e', '$hospital_id', $final_amount, $final_amount,
                " . ($discount_pct > 0 ? $original : 'NULL') . ",
                $discount_pct,
                " . ($discount_src ? "'$discount_src'" : 'NULL') . ",
                $is_free, '$method', '$trxid', 'pending_verification')
    ");

    if ($ok) {
        $conn->query("UPDATE test_recommendations SET status='paid' WHERE id=$rec_id");

        // Record free checkup usage
        if ($is_free) {
            $period_key = date('Y-m');
            $payment_id = $conn->insert_id;
            $conn->query("
                INSERT IGNORE INTO citizen_free_checkups (citizen_bcn, period_key, payment_id)
                VALUES ('$bcn_e', '$period_key', $payment_id)
            ");
        }
    }

    $msg = $ok ? 'Payment submitted! The hospital will verify your transaction shortly.' : $conn->error;
    if ($ok && $is_free)         $msg = '🎉 Free monthly checkup applied! Submitted for verification.';
    elseif ($ok && $discount_pct > 0) $msg = "✅ {$discount_pct}% loyalty discount applied! Payment submitted.";

    echo json_encode(['success' => (bool)$ok, 'message' => $msg,
                      'final_amount' => $final_amount, 'discount_pct' => $discount_pct]);
}

// ── SUBMIT BATCH PAYMENT ──────────────────────────────────────────────────────
elseif ($action === 'submit_batch_payment') {
    $bcn_e       = $conn->real_escape_string($bcn);
    $batch_id    = $conn->real_escape_string($_POST['batch_id'] ?? '');
    $rec_ids_raw = $_POST['rec_ids'] ?? '[]';
    $rec_ids     = json_decode($rec_ids_raw, true);
    $total       = floatval($_POST['total']  ?? 0);
    $method      = $conn->real_escape_string($_POST['method'] ?? '');
    $trxid       = $conn->real_escape_string($_POST['trxid']  ?? '');

    if (!in_array($method, ['bkash','nagad','rocket'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment method.']); exit();
    }
    if (!$trxid) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID required.']); exit();
    }
    if (empty($rec_ids) || $total <= 0) {
        echo json_encode(['success' => false, 'message' => 'No tests selected or invalid total.']); exit();
    }

    $dupCheck = $conn->query("SELECT id FROM test_payments WHERE trxid='$trxid'")->fetch_assoc();
    if ($dupCheck) {
        echo json_encode(['success' => false, 'message' => 'This Transaction ID has already been used.']); exit();
    }

    $safe_ids  = array_map('intval', $rec_ids);
    $id_list   = implode(',', $safe_ids);
    $validRecs = $conn->query("
        SELECT id, hospital_id FROM test_recommendations
        WHERE id IN ($id_list)
          AND citizen_id='$bcn_e'
          AND status IN ('pending','applied')
    ");

    if ($validRecs->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No valid recommendations found.']); exit();
    }

    $firstRec    = $validRecs->fetch_assoc();
    $hospital_id = $conn->real_escape_string($firstRec['hospital_id']);
    $esc_ids     = implode(',', $safe_ids);

    // Mark pending → applied
    $conn->query("
        UPDATE test_recommendations
        SET status='applied'
        WHERE id IN ($esc_ids) AND citizen_id='$bcn_e' AND status='pending'
    ");

    // ── Apply loyalty discount ─────────────────────────────────────────────
    $loyalty      = getLoyaltyDiscount($conn, $bcn);
    $discount_pct = $loyalty['discount_pct'];
    $original     = $total;
    $final_amount = $total;
    $is_free      = 0;
    $discount_src = null;

    if ($loyalty['free_available']) {
        $final_amount = 0;
        $is_free      = 1;
        $discount_pct = 100;
        $discount_src = $conn->real_escape_string($loyalty['tier_id']);
    } elseif ($discount_pct > 0) {
        $final_amount = round($total * (1 - $discount_pct / 100), 2);
        $discount_src = $conn->real_escape_string($loyalty['tier_id']);
    }

 $first_rec_id = $safe_ids[0];
// 3NF FIX: removed $selected_ids — now stored in test_payment_items

$ok = $conn->query("
    INSERT INTO test_payments
    (recommendation_id, batch_id, citizen_id, hospital_id,
     amount, total_amount, original_amount, discount_pct, discount_source,
     is_free_checkup, payment_method, trxid, status)
    VALUES ($first_rec_id, '$batch_id', '$bcn_e', '$hospital_id',
            $final_amount, $final_amount,
            " . ($discount_pct > 0 ? $original : 'NULL') . ",
            $discount_pct,
            " . ($discount_src ? "'$discount_src'" : 'NULL') . ",
            $is_free, '$method', '$trxid', 'pending_verification')
");

if ($ok) {
    $payment_id = $conn->insert_id;

    // 3NF FIX: insert each rec_id into test_payment_items
    foreach ($safe_ids as $rid) {
        $conn->query("
            INSERT IGNORE INTO test_payment_items (payment_id, recommendation_id)
            VALUES ($payment_id, $rid)
        ");
    }

    $conn->query("
        UPDATE test_recommendations
        SET status='paid'
        WHERE id IN ($esc_ids) AND citizen_id='$bcn_e'
    ");

    if ($is_free) {
        $period_key = date('Y-m');
        $conn->query("
            INSERT IGNORE INTO citizen_free_checkups (citizen_bcn, period_key, payment_id)
            VALUES ('$bcn_e', '$period_key', $payment_id)
        ");
    }
}

    $msg = $ok ? 'Payment submitted! The hospital will verify your transaction shortly.' : $conn->error;
    if ($ok && $is_free)               $msg = '🎉 Free monthly checkup applied! Submitted for verification.';
    elseif ($ok && $discount_pct > 0)  $msg = "✅ {$discount_pct}% loyalty discount applied! Payment submitted.";

    echo json_encode([
        'success'       => (bool)$ok,
        'message'       => $msg,
        'final_amount'  => $final_amount,
        'discount_pct'  => $discount_pct,
        'original'      => $original,
    ]);
}

// ── GET LOYALTY DISCOUNT PREVIEW (called before payment UI renders) ───────────
// Returns what discount/free-checkup the citizen currently has so the
// test_results.php payment UI can show the discounted total before submit.
elseif ($action === 'get_discount_preview') {
    $loyalty = getLoyaltyDiscount($conn, $bcn);
    echo json_encode([
        'success'        => true,
        'discount_pct'   => $loyalty['discount_pct'],
        'free_available' => $loyalty['free_available'],
        'tier_id'        => $loyalty['tier_id'],
        'tier_name'      => $loyalty['tier_name'],
    ]);
}

// ── GET MY SPENDING ───────────────────────────────────────────────────────────
elseif ($action === 'get_my_spending') {
    $bcn_e = $conn->real_escape_string($bcn);

    $totals = $conn->query("
        SELECT
            COUNT(*)    AS total_transactions,
            SUM(COALESCE(NULLIF(tp.total_amount,0), tp.amount)) AS total_spent,
            SUM(tp.test_count) AS total_tests
        FROM test_payments tp
        WHERE tp.citizen_id='$bcn_e' AND tp.status='verified'
    ")->fetch_assoc();

    $pending_row = $conn->query("
        SELECT COALESCE(SUM(COALESCE(NULLIF(total_amount,0), amount)), 0) AS pending_amount
        FROM test_payments
        WHERE citizen_id='$bcn_e' AND status='pending_verification'
    ")->fetch_assoc();

    $by_hospital = [];
    $bh = $conn->query("
        SELECT h.h_name AS hospital_name,
               COUNT(tp.id)  AS tx_count,
               SUM(COALESCE(NULLIF(tp.total_amount,0), tp.amount)) AS spent
        FROM test_payments tp
        JOIN hospitals h ON h.h_system_id = tp.hospital_id
        WHERE tp.citizen_id='$bcn_e' AND tp.status='verified'
        GROUP BY tp.hospital_id
        ORDER BY spent DESC
    ");
    while ($r = $bh->fetch_assoc()) $by_hospital[] = $r;

    $transactions = [];
    $th = $conn->query("
        SELECT tp.id, tp.payment_method, tp.trxid, tp.status,
               tp.citizen_submitted_at, tp.verified_at,
               COALESCE(NULLIF(tp.total_amount,0), tp.amount) AS amount,
               tp.original_amount, tp.discount_pct, tp.is_free_checkup,
               h.h_name AS hospital_name,
               tc.test_name
        FROM test_payments tp
        JOIN test_recommendations tr ON tr.id = tp.recommendation_id
        JOIN test_catalog tc         ON tc.id = tr.test_catalog_id
        JOIN hospitals h             ON h.h_system_id = tp.hospital_id
        WHERE tp.citizen_id='$bcn_e'
        ORDER BY tp.citizen_submitted_at DESC
        LIMIT 50
    ");
    while ($r = $th->fetch_assoc()) $transactions[] = $r;

    echo json_encode([
        'success'           => true,
        'total_spent'       => floatval($totals['total_spent'] ?? 0),
        'total_transactions'=> intval($totals['total_transactions'] ?? 0),
        'total_tests'       => intval($totals['total_tests'] ?? 0),
        'pending_amount'    => floatval($pending_row['pending_amount'] ?? 0),
        'by_hospital'       => $by_hospital,
        'transactions'      => $transactions,
    ]);
}

else {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
?>
