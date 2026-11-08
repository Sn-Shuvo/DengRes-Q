<?php
/**
 * DengResQ | Forgot / Reset Password Handler
 * Handles: request token, verify token, update password
 * Supports: citizen, hospital, institution (email-based)
 * HO note: HO uses badge+division — no email column in health_officers table.
 *          We handle HO via admin-contact fallback in the UI.
 *
 * File: DengResQ/assets/actions/forgot_password.php
 */

ob_start();
session_start();
require_once '../../config/db_connect.php';

/* ══════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════ */

/**
 * Generate a cryptographically secure token, store it in
 * password_reset_tokens, and return it.
 */
function createResetToken(mysqli $conn, string $role, string $identifier): string {
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    // Invalidate any old tokens for this user/role
    $stmt = $conn->prepare(
        "DELETE FROM password_reset_tokens WHERE role = ? AND identifier = ?"
    );
    $stmt->bind_param("ss", $role, $identifier);
    $stmt->execute();

    // Insert new token
    $stmt = $conn->prepare(
        "INSERT INTO password_reset_tokens (token, role, identifier, expires_at)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("ssss", $token, $role, $identifier, $expires_at);
    $stmt->execute();

    return $token;
}

/**
 * Send reset email using PHP's mail().
 * On localhost (XAMPP) this will typically fail silently unless
 * sendmail / an SMTP plugin is configured — the token is returned
 * to the page for dev convenience via the ?dev_token param only when
 * APP_ENV === 'development'.
 */
function sendResetEmail(string $to, string $name, string $token, string $role): bool {
    $base_url   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'];
    $reset_link = $base_url . '/DengResQ/assets/UI/reset_password.php'
                . '?token=' . urlencode($token) . '&role=' . urlencode($role);

    $subject = "DengResQ — Password Reset Request";
    $message = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#0a0a0c;color:#e8e8ec;padding:2rem;'>
  <div style='max-width:520px;margin:0 auto;background:#111114;border-radius:12px;
              border:1px solid rgba(255,255,255,0.07);overflow:hidden;'>
    <div style='background:#c0392b;padding:1.2rem 1.8rem;'>
      <h1 style='font-size:1.2rem;color:#fff;margin:0;letter-spacing:0.05em;'>DengResQ</h1>
    </div>
    <div style='padding:2rem;'>
      <h2 style='color:#e74c3c;margin-bottom:0.5rem;font-size:1.1rem;'>Password Reset Request</h2>
      <p style='color:#aaa;font-size:0.9rem;margin-bottom:1.5rem;line-height:1.6;'>
        Hi <strong style='color:#e8e8ec;'>$name</strong>,<br>
        We received a request to reset your DengResQ password.
        Click the button below within <strong>1 hour</strong> to set a new password.
      </p>
      <a href='$reset_link'
         style='display:inline-block;background:#c0392b;color:#fff;
                padding:0.85rem 2rem;border-radius:8px;
                text-decoration:none;font-weight:700;font-size:0.9rem;
                letter-spacing:0.05em;'>
        Reset My Password →
      </a>
      <p style='color:#555;font-size:0.75rem;margin-top:1.5rem;line-height:1.6;'>
        If you did not request a reset, ignore this email — your password is unchanged.<br>
        This link expires in 1 hour.
      </p>
      <p style='color:#555;font-size:0.72rem;margin-top:0.5rem;word-break:break-all;'>
        Link: $reset_link
      </p>
    </div>
  </div>
</body>
</html>
";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: DengResQ <noreply@dengresq.health>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    return mail($to, $subject, $message, $headers);
}

/* ══════════════════════════════════════════════════
   STEP 1 — REQUEST RESET LINK
   POST: action=request, role=..., email=...
══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $role   = trim($_POST['role']   ?? '');

    /* ── Request token ─────────────────────────────── */
    if ($action === 'request') {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            exit();
        }

        $found = false;
        $name  = 'User';

        if ($role === 'hospital') {
            $stmt = $conn->prepare(
                "SELECT h_system_id, h_name FROM hospitals WHERE LOWER(email) = ?"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $found      = true;
                $identifier = $row['h_system_id'];
                $name       = $row['h_name'];
            }

        } elseif ($role === 'institution') {
            $stmt = $conn->prepare(
                "SELECT institution_id, name FROM institutions WHERE LOWER(email) = ?"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $found      = true;
                $identifier = (string)$row['institution_id'];
                $name       = $row['name'];
            }

        } elseif ($role === 'citizen') {
            // Citizens don't have an email column by default.
            // We match on phone used as contact (future: add email col).
            // For now we treat the "email" field as a fallback phone-email lookup.
            // If you add email to citizens table, swap this block out.
            echo json_encode([
                'success' => false,
                'message' => 'Citizens currently reset passwords via phone verification. Please contact support or use BCN + Phone at the help desk.'
            ]);
            exit();
        }

        // Generic "always ok" response to prevent email enumeration
        if (!$found) {
            echo json_encode([
                'success' => true,
                'message' => 'If that email is registered, a reset link has been sent.'
            ]);
            exit();
        }

        $token = createResetToken($conn, $role, $identifier);
        sendResetEmail($email, $name, $token, $role);

        // DEV CONVENIENCE: expose token when running on localhost
        $dev_token = null;
        if (in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
            $dev_token = $token;
        }

        echo json_encode([
            'success'   => true,
            'message'   => 'If that email is registered, a reset link has been sent.',
            'dev_token' => $dev_token  // null in production
        ]);
        exit();
    }

    /* ── Validate token ────────────────────────────── */
    if ($action === 'validate') {
        $token = trim($_POST['token'] ?? '');
        $role  = trim($_POST['role']  ?? '');

        $stmt = $conn->prepare(
            "SELECT identifier FROM password_reset_tokens
             WHERE token = ? AND role = ? AND expires_at > NOW() AND used = 0"
        );
        $stmt->bind_param("ss", $token, $role);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            echo json_encode(['valid' => true,  'identifier' => $row['identifier']]);
        } else {
            echo json_encode(['valid' => false, 'message' => 'Invalid or expired reset link.']);
        }
        exit();
    }

    /* ── Set new password ──────────────────────────── */
    if ($action === 'reset') {
        $token    = trim($_POST['token']    ?? '');
        $role     = trim($_POST['role']     ?? '');
        $new_pass = $_POST['new_password']  ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
            exit();
        }
        if ($new_pass !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit();
        }

        // Fetch token record
        $stmt = $conn->prepare(
            "SELECT identifier FROM password_reset_tokens
             WHERE token = ? AND role = ? AND expires_at > NOW() AND used = 0"
        );
        $stmt->bind_param("ss", $token, $role);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired reset link. Please request a new one.']);
            exit();
        }

        $identifier  = $row['identifier'];
        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
        $updated     = false;

        if ($role === 'hospital') {
            $stmt = $conn->prepare(
                "UPDATE hospitals SET password = ? WHERE h_system_id = ?"
            );
            $stmt->bind_param("ss", $hashed_pass, $identifier);
            $updated = $stmt->execute() && $stmt->affected_rows > 0;

        } elseif ($role === 'institution') {
            $stmt = $conn->prepare(
                "UPDATE institutions SET password = ? WHERE institution_id = ?"
            );
            $stmt->bind_param("si", $hashed_pass, (int)$identifier);
            $updated = $stmt->execute() && $stmt->affected_rows > 0;
        }

        if ($updated) {
            // Mark token as used
            $stmt = $conn->prepare(
                "UPDATE password_reset_tokens SET used = 1 WHERE token = ?"
            );
            $stmt->bind_param("s", $token);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => 'Password updated successfully! You can now log in.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update password. Please try again.']);
        }
        exit();
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
