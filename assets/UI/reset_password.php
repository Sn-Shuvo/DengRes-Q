<?php
/**
 * DengResQ | Reset Password Page
 * Linked from the reset email: ?token=...&role=...
 * File: DengResQ/assets/UI/reset_password.php
 */
session_start();
$token = trim($_GET['token'] ?? '');
$role  = trim($_GET['role']  ?? '');
$valid_roles = ['hospital', 'institution', 'citizen'];
if (!$token || !in_array($role, $valid_roles)) {
    header("Location: login.html");
    exit();
}
$role_label = [
    'citizen'     => 'Citizen Portal',
    'hospital'    => 'Hospital Portal',
    'institution' => 'University Portal',
][$role] ?? 'Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DengResQ | Reset Password</title>
    <script>
    (function(){
        var t = localStorage.getItem('drq_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="stylesheet" href="../css/auth.css">
    <link rel="stylesheet" href="../css/theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .reset-state { display:none; }
        .reset-state.active { display:block; }
        .msg-box {
            padding: 0.9rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1rem;
            display: none;
        }
        .msg-box.error   { background:rgba(192,57,43,0.1);   border:1px solid rgba(192,57,43,0.35);   color:#e74c3c; }
        .msg-box.success { background:rgba(16,185,129,0.1);  border:1px solid rgba(16,185,129,0.35);  color:#10b981; }
        .msg-box.show    { display:block; }
        .spinner { display:inline-block; width:18px; height:18px;
            border:2px solid rgba(255,255,255,0.3); border-top-color:#fff;
            border-radius:50%; animation:spin 0.7s linear infinite; vertical-align:middle; }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
</head>
<body>

<nav>
    <div class="logo">DengResQ</div>
    <div class="nav-links">
        <a href="landingpage.html">Home</a>
        <a href="login.html" class="login-btn">Login</a>
        <button id="drq-theme-btn" onclick="drqToggleTheme()" title="Toggle theme"
            style="background:none;border:1px solid rgba(255,255,255,0.15);border-radius:8px;
                   width:34px;height:34px;cursor:pointer;font-size:1rem;
                   display:flex;align-items:center;justify-content:center;">☀️</button>
    </div>
</nav>

<main>
    <div class="auth-card">
        <div class="portal-badge">
            <span class="icon">🔑</span>
            <span class="label">Reset Password — <?= htmlspecialchars($role_label) ?></span>
        </div>

        <!-- VALIDATING STATE -->
        <div class="form-panel active reset-state" id="state-validating">
            <p style="text-align:center;color:var(--muted);padding:2rem 0;">
                <span class="spinner"></span>
                &nbsp; Verifying reset link…
            </p>
        </div>

        <!-- INVALID TOKEN STATE -->
        <div class="form-panel reset-state" id="state-invalid">
            <div class="msg-box error show">
                ⚠️ This reset link is <strong>invalid or has expired</strong>.<br>
                Reset links are valid for 1 hour and can only be used once.
            </div>
            <a href="login.html" class="submit-btn" style="display:block;text-align:center;text-decoration:none;margin-top:0;">
                ← Back to Login
            </a>
        </div>

        <!-- RESET FORM STATE -->
        <div class="form-panel reset-state" id="state-form">
            <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.6;">
                Enter your new password below. Choose something strong — at least 8 characters
                with uppercase, numbers, and symbols.
            </p>

            <div class="msg-box" id="reset-msg"></div>

            <div class="field-group">
                <label class="field-label">
                    New Password <span class="req-star">★</span>
                </label>
                <input type="password" id="new-pass" placeholder="Create a strong password"
                    oninput="checkStrength(this.value)">
                <div class="strength-bar">
                    <div class="strength-fill" id="strength-fill"></div>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label">
                    Confirm New Password <span class="req-star">★</span>
                </label>
                <input type="password" id="confirm-pass" placeholder="Repeat your password">
            </div>

            <button class="submit-btn" id="reset-btn" onclick="doReset()">
                Set New Password
            </button>
        </div>

        <!-- SUCCESS STATE -->
        <div class="form-panel reset-state" id="state-success">
            <div class="msg-box success show">
                ✅ <strong>Password updated successfully!</strong><br>
                You can now log in with your new password.
            </div>
            <a href="login.html" class="submit-btn"
               style="display:block;text-align:center;text-decoration:none;margin-top:0;">
                Go to Login →
            </a>
        </div>

    </div>
</main>

<script>
const TOKEN = <?= json_encode($token) ?>;
const ROLE  = <?= json_encode($role)  ?>;

/* ── Theme ── */
function drqApplyTheme(t){
    document.documentElement.setAttribute('data-theme',t);
    localStorage.setItem('drq_theme',t);
    var b=document.getElementById('drq-theme-btn');
    if(b) b.textContent = t==='dark'?'☀️':'🌙';
}
function drqToggleTheme(){
    drqApplyTheme(document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark');
}
(function(){ drqApplyTheme(localStorage.getItem('drq_theme')||'light'); })();

/* ── State helpers ── */
function showState(id){
    document.querySelectorAll('.reset-state').forEach(el => el.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

/* ── Validate token on load ── */
window.addEventListener('DOMContentLoaded', async () => {
    const fd = new FormData();
    fd.append('action','validate');
    fd.append('token', TOKEN);
    fd.append('role',  ROLE);
    try {
        const r    = await fetch('/DengResQ/assets/actions/forgot_password.php', { method:'POST', body:fd });
        const data = await r.json();
        showState(data.valid ? 'state-form' : 'state-invalid');
    } catch(e) {
        showState('state-invalid');
    }
});

/* ── Password strength ── */
function checkStrength(val){
    let s=0;
    if(val.length>=8)           s++;
    if(/[A-Z]/.test(val))       s++;
    if(/[0-9]/.test(val))       s++;
    if(/[^A-Za-z0-9]/.test(val))s++;
    const f=document.getElementById('strength-fill');
    f.style.width      = (s*25)+'%';
    f.style.background = ['#444','#e74c3c','#e67e22','#2ecc71','#27ae60'][s];
}

/* ── Submit reset ── */
async function doReset(){
    const p1  = document.getElementById('new-pass').value;
    const p2  = document.getElementById('confirm-pass').value;
    const msg = document.getElementById('reset-msg');
    const btn = document.getElementById('reset-btn');

    msg.className = 'msg-box';
    msg.textContent = '';

    if(p1.length < 8){ showMsg('error','Password must be at least 8 characters.'); return; }
    if(p1 !== p2)    { showMsg('error','Passwords do not match.'); return; }

    btn.disabled    = true;
    btn.innerHTML   = '<span class="spinner"></span> Updating…';

    const fd = new FormData();
    fd.append('action',           'reset');
    fd.append('token',            TOKEN);
    fd.append('role',             ROLE);
    fd.append('new_password',     p1);
    fd.append('confirm_password', p2);

    try {
        const r    = await fetch('/DengResQ/assets/actions/forgot_password.php', { method:'POST', body:fd });
        const data = await r.json();
        if(data.success){
            showState('state-success');
        } else {
            showMsg('error', data.message || 'Something went wrong. Please try again.');
            btn.disabled  = false;
            btn.innerHTML = 'Set New Password';
        }
    } catch(e) {
        showMsg('error','Network error. Please try again.');
        btn.disabled  = false;
        btn.innerHTML = 'Set New Password';
    }
}

function showMsg(type, text){
    const msg = document.getElementById('reset-msg');
    msg.className   = 'msg-box ' + type + ' show';
    msg.textContent = text;
}
</script>
</body>
</html>
