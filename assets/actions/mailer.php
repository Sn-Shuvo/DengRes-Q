<?php
/**
 * DengResQ — Mailer.php
 * PHPMailer wrapper for sending OTP emails.
 *
 * Install PHPMailer:
 *   composer require phpmailer/phpmailer
 *
 * ── Gmail setup ──────────────────────────────────────────────────────────────
 * 1. Create a Gmail account e.g. dengresq.alerts@gmail.com
 * 2. Enable 2-Step Verification on it
 * 3. Go to: Google Account → Security → App Passwords
 * 4. Generate an App Password for "Mail"
 * 5. Paste the 16-character code into SMTP_PASS below
 * 6. Set SMTP_USER and SMTP_FROM to that Gmail address
 * ─────────────────────────────────────────────────────────────────────────────
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/../../vendor/autoload.php';

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST',      'smtp.gmail.com');
    define('SMTP_PORT',      465);
    define('SMTP_USER',      'noreplydengresq@gmail.com');       // ← replace
    define('SMTP_PASS',      'qijl plna txfu sgus');  // ← replace
    define('SMTP_FROM',      'noreplydengresq@gmail.com');       // ← replace
    define('SMTP_FROM_NAME', 'DengResQ');
}

/**
 * Send an OTP email to the given address.
 */
function sendOtpEmail(string $toEmail, string $toName, string $otp, string $role): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);
        $mail->isHTML(true);
        $mail->Subject = 'DengResQ: Your Password Reset Code';
        $mail->Body    = buildOtpEmailHtml($toName, $otp, $role);
        $mail->AltBody = buildOtpEmailPlain($toName, $otp, $role);

        $mail->send();
        return true;

    } catch (MailerException | \Exception $e) {
        error_log('[DengResQ Mailer] ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
        return false;
    }
}

/**
 * HTML email body — built with string concatenation to avoid heredoc
 * indentation restrictions.
 */
function buildOtpEmailHtml(string $name, string $otp, string $role): string
{
    $roleLabelMap = [
        'citizen'     => 'Citizen / Donor Portal',
        'ho'          => 'Health Officer Terminal',
        'hospital'    => 'Hospital Portal',
        'institution' => 'University / Institution Portal',
    ];
    $roleLabel = $roleLabelMap[$role] ?? ucfirst($role);
    $year      = date('Y');

    $html  = '<!DOCTYPE html>';
    $html .= '<html lang="en">';
    $html .= '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
    $html .= '<body style="margin:0;padding:0;background:#f0f4f0;font-family:\'Segoe UI\',Arial,sans-serif;">';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f0;padding:40px 0;">';
    $html .= '<tr><td align="center">';
    $html .= '<table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.07);">';

    // ── Header ────────────────────────────────────────────────────────────────
    $html .= '<tr>';
    $html .= '<td style="background:#080e08;padding:28px 40px;text-align:center;">';
    $html .= '<span style="font-size:22px;font-weight:700;color:#00e676;letter-spacing:-0.5px;">DengResQ</span>';
    $html .= '<div style="font-size:11px;color:#8aab8a;margin-top:4px;letter-spacing:0.08em;text-transform:uppercase;">' . htmlspecialchars($roleLabel) . '</div>';
    $html .= '</td>';
    $html .= '</tr>';

    // ── Body ──────────────────────────────────────────────────────────────────
    $html .= '<tr>';
    $html .= '<td style="padding:36px 40px 28px;">';
    $html .= '<p style="margin:0 0 12px;font-size:15px;color:#0a160e;">Hello, <strong>' . htmlspecialchars($name) . '</strong>,</p>';
    $html .= '<p style="margin:0 0 24px;font-size:14px;color:#444;line-height:1.6;">We received a request to reset your password. Use the one-time password (OTP) below. It expires in <strong>10 minutes</strong>.</p>';

    // OTP box
    $html .= '<div style="text-align:center;margin:0 0 28px;">';
    $html .= '<div style="display:inline-block;background:#f0f4f0;border:1.5px solid #00e676;border-radius:10px;padding:18px 40px;">';
    $html .= '<span style="font-size:38px;font-weight:700;letter-spacing:12px;color:#1a7a3a;font-family:\'Courier New\',monospace;">' . htmlspecialchars($otp) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<p style="margin:0 0 8px;font-size:13px;color:#666;line-height:1.6;">Enter this code on the password reset page when prompted. Do <strong>not</strong> share this code with anyone.</p>';
    $html .= '<p style="margin:0;font-size:13px;color:#666;line-height:1.6;">If you did not request a password reset, you can safely ignore this email. Your account remains secure.</p>';
    $html .= '</td>';
    $html .= '</tr>';

    // ── Footer ────────────────────────────────────────────────────────────────
    $html .= '<tr>';
    $html .= '<td style="background:#f8faf8;padding:18px 40px;border-top:1px solid #e8ede8;text-align:center;">';
    $html .= '<p style="margin:0;font-size:11px;color:#999;">&copy; ' . $year . ' DengResQ &mdash; Dengue Response &amp; Management System</p>';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= '</table>';
    $html .= '</td></tr>';
    $html .= '</table>';
    $html .= '</body>';
    $html .= '</html>';

    return $html;
}

/**
 * Plain-text fallback email body.
 */
function buildOtpEmailPlain(string $name, string $otp, string $role): string
{
    $text  = "DengResQ — Password Reset OTP\n";
    $text .= "==============================\n\n";
    $text .= "Hello, {$name}.\n\n";
    $text .= "Your one-time password (OTP) is:\n\n";
    $text .= "  {$otp}\n\n";
    $text .= "This code expires in 10 minutes. Do not share it with anyone.\n\n";
    $text .= "If you did not request a password reset, please ignore this email.\n\n";
    $text .= "— DengResQ Team\n";

    return $text;
}