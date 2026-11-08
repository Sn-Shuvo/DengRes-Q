<?php
// Issue 3 fixed: declare must be the VERY first thing — no comments above it
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
/**
 * DengResQ — otp_handler.php
 * Unified OTP password recovery backend for all 4 roles.
 *
 * Actions (POST, expects JSON response):
 *   action=request  → generates OTP, emails it
 *   action=verify   → checks OTP validity
 *   action=reset    → validates OTP again + updates password
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/Mailer.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

// Issue 4 fixed: return type changed from `never` to `void` for PHP 8.0 compatibility
function jsonOut(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

function roleConfig(string $role): array
{
    $map = [
        'citizen' => ['citizens', 'email', 'full_name', 'bcn', 'password'],
        'ho'          => ['health_officers', 'email', 'badge_number',     'badge_number', 'pin'],
        'hospital'    => ['hospitals',       'email', 'h_name',           'h_system_id', 'password'],
        'institution' => ['institutions',    'email', 'coordinator_name', 'institution_id', 'password'],
    ];
    return $map[$role] ?? [];
}

function generateOtp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// ── Input validation ──────────────────────────────────────────────────────────

$action = trim($_POST['action'] ?? '');
$role   = trim($_POST['role']   ?? '');
$email  = strtolower(trim($_POST['email'] ?? ''));

$allowedRoles   = ['citizen', 'ho', 'hospital', 'institution'];
$allowedActions = ['request', 'verify', 'reset'];

if (!in_array($action, $allowedActions, true)) {
    jsonOut(false, 'Invalid action.');
}
if (!in_array($role, $allowedRoles, true)) {
    jsonOut(false, 'Invalid role.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOut(false, 'Please enter a valid email address.');
}

$cfg = roleConfig($role);
if (empty($cfg)) {
    jsonOut(false, 'Role configuration error.');
}

[$table, $emailCol, $nameCol, $idCol, $passCol] = $cfg;

// ── Rate limiting — max 3 OTP requests per email per 15 min ──────────────────
if ($action === 'request') {
    $rateLimitKey = "otp_req_{$role}_{$email}";
    $rateData = $_SESSION[$rateLimitKey] ?? ['count' => 0, 'since' => time()];
    if (time() - $rateData['since'] > 900) {
        $rateData = ['count' => 0, 'since' => time()];
    }
    $rateData['count']++;
    $_SESSION[$rateLimitKey] = $rateData;
    if ($rateData['count'] > 3) {
        jsonOut(false, 'Too many OTP requests. Please wait 15 minutes before trying again.');
    }
}

// ── Fetch user ────────────────────────────────────────────────────────────────
// Issue 5 fixed: initialise $user to false so it is always defined even if
// the try block throws before the fetch completes
$user = false;
try {
    $stmt = $pdo->prepare(
        "SELECT {$idCol} AS uid, {$nameCol} AS name
         FROM {$table}
         WHERE {$emailCol} = ?
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    jsonOut(false, 'DB Error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: request
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'request') {
    if (!$user) {
        // Generic message prevents email enumeration
        jsonOut(true, 'If that email is registered, an OTP has been sent.');
    }

    $otp       = generateOtp();
    $otpHashed = password_hash($otp, PASSWORD_BCRYPT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    try {
        $upd = $pdo->prepare(
            "UPDATE {$table}
             SET otp_code = ?, otp_expires_at = ?
             WHERE {$idCol} = ?"
        );
        $upd->execute([$otpHashed, $expiresAt, $user['uid']]);
    } catch (\PDOException $e) {
        error_log('[DengResQ OTP] Failed to store OTP: ' . $e->getMessage());
        jsonOut(false, 'Could not generate OTP. Please try again.');
    }

    $sent = sendOtpEmail($email, $user['name'], $otp, $role);

    if (!$sent) {
        jsonOut(false, 'Failed to send email. Please check your SMTP configuration.');
    }

    jsonOut(true, 'OTP sent! Check your inbox. It expires in 10 minutes.');
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: verify
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'verify') {
    $otp = trim($_POST['otp'] ?? '');

    // Issue 6 fixed: check $user before accessing $user['uid']
    if (!$user) {
        jsonOut(false, 'Invalid email address.');
    }

    $otpRow = false;
    try {
        $q = $pdo->prepare(
            "SELECT otp_code, otp_expires_at
             FROM {$table}
             WHERE {$idCol} = ?"
        );
        $q->execute([$user['uid']]);
        $otpRow = $q->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // Issue 7 fixed: log the error instead of silently swallowing it
        error_log('[DengResQ OTP] Verify DB error: ' . $e->getMessage());
        jsonOut(false, 'Database error. Please try again.');
    }

    if (!$otpRow || !$otpRow['otp_code']) {
        jsonOut(false, 'No OTP found. Please request a new one.');
    }
    if (strtotime($otpRow['otp_expires_at']) < time()) {
        jsonOut(false, 'OTP has expired. Please request a new one.');
    }
    if (!password_verify($otp, $otpRow['otp_code'])) {
        jsonOut(false, 'Incorrect OTP. Please check your email and try again.');
    }

    jsonOut(true, 'OTP verified.');
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: reset
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'reset') {
    $otp             = trim($_POST['otp']             ?? '');
    $newPassword     = $_POST['new_password']         ?? '';
    $confirmPassword = $_POST['confirm_password']     ?? '';

    // Issue 6 fixed: check $user before accessing $user['uid']
    if (!$user) {
        jsonOut(false, 'Invalid email address.');
    }

    if (strlen($newPassword) < 8) {
        jsonOut(false, 'Password must be at least 8 characters.');
    }
    if ($newPassword !== $confirmPassword) {
        jsonOut(false, 'Passwords do not match.');
    }

    $otpRow = false;
    try {
        $q = $pdo->prepare(
            "SELECT otp_code, otp_expires_at
             FROM {$table}
             WHERE {$idCol} = ?"
        );
        $q->execute([$user['uid']]);
        $otpRow = $q->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('[DengResQ OTP] Reset DB error: ' . $e->getMessage());
        jsonOut(false, 'Database error. Please try again.');
    }

    if (!$otpRow || !$otpRow['otp_code']) {
        jsonOut(false, 'No OTP found. Please start the process again.');
    }
    if (strtotime($otpRow['otp_expires_at']) < time()) {
        jsonOut(false, 'OTP has expired. Please request a new one.');
    }
    if (!password_verify($otp, $otpRow['otp_code'])) {
        jsonOut(false, 'Incorrect OTP. Cannot reset password.');
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

    try {
        $upd = $pdo->prepare(
            "UPDATE {$table}
             SET {$passCol} = ?, otp_code = NULL, otp_expires_at = NULL
             WHERE {$idCol} = ?"
        );
        $upd->execute([$newHash, $user['uid']]);
    } catch (\PDOException $e) {
        error_log('[DengResQ OTP] Password update failed: ' . $e->getMessage());
        jsonOut(false, 'Failed to update password. Please try again.');
    }

    jsonOut(true, 'Password reset successfully! You can now log in with your new password.');
}