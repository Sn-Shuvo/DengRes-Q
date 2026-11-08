
/**
 * DengResQ — otp-recovery.js
 * Drop this file in /assets/js/ and include it on every auth page.
 *
 * Usage:
 *   <script src="../../assets/js/otp-recovery.js"></script>
 *
 * Requires the following HTML structure inside your #panel-forgot:
 *   See the template in forgot_panel_template.html
 *
 * Call initOtpRecovery(role) once the DOM is ready.
 * The `role` string must match one of: citizen | ho | hospital | institution
 */

(function (global) {
    'use strict';

    const OTP_ENDPOINT = '/DengResQ/assets/actions/otp_handler.php';

    // ── State ─────────────────────────────────────────────────────────────────
    let _role          = '';
    let _verifiedEmail = '';
    let _resendTimer   = null;

    // ── DOM refs (populated on init) ─────────────────────────────────────────
    let elMsg, elEmail, elOtp, elNewPass, elConfPass, elStrength;
    let btnSend, btnVerify, btnReset, btnResend;
    let stepEmail, stepOtp, stepReset;

    // ── Public init ───────────────────────────────────────────────────────────
    function initOtpRecovery(role) {
        _role = role;

        elMsg     = document.getElementById('otp-msg');
        elEmail   = document.getElementById('otp-email');
        elOtp     = document.getElementById('otp-code');
        elNewPass = document.getElementById('otp-new-pass');
        elConfPass= document.getElementById('otp-conf-pass');
        elStrength= document.getElementById('otp-strength-fill');

        btnSend   = document.getElementById('otp-btn-send');
        btnVerify = document.getElementById('otp-btn-verify');
        btnReset  = document.getElementById('otp-btn-reset');
        btnResend = document.getElementById('otp-btn-resend');

        stepEmail = document.getElementById('otp-step-email');
        stepOtp   = document.getElementById('otp-step-otp');
        stepReset = document.getElementById('otp-step-reset');

        if (btnSend)   btnSend.addEventListener('click',   handleSend);
        if (btnVerify) btnVerify.addEventListener('click', handleVerify);
        if (btnReset)  btnReset.addEventListener('click',  handleReset);
        if (btnResend) btnResend.addEventListener('click', handleResend);

        if (elNewPass) {
            elNewPass.addEventListener('input', function () {
                checkStrength(this.value);
            });
        }

        showStep('email');
    }

    // ── Step renderer ─────────────────────────────────────────────────────────
    function showStep(step) {
        if (stepEmail) stepEmail.style.display = step === 'email' ? '' : 'none';
        if (stepOtp)   stepOtp.style.display   = step === 'otp'   ? '' : 'none';
        if (stepReset) stepReset.style.display  = step === 'reset' ? '' : 'none';
        clearMsg();
    }

    // ── Message helper ────────────────────────────────────────────────────────
    function showMsg(type, text) {
        if (!elMsg) return;
        elMsg.className   = 'fp-msg ' + type + ' show';
        elMsg.textContent = text;
    }

    function clearMsg() {
        if (!elMsg) return;
        elMsg.className   = 'fp-msg';
        elMsg.textContent = '';
    }

    // ── Loading state ─────────────────────────────────────────────────────────
    function setLoading(btn, loading, label) {
        if (!btn) return;
        btn.disabled   = loading;
        btn.innerHTML  = loading
            ? '<span class="spinner-sm"></span> ' + label
            : btn.dataset.label || label;
        if (!loading && btn.dataset.label === undefined) {
            btn.dataset.label = label;
        }
    }

    // ── POST helper ───────────────────────────────────────────────────────────
    async function post(fields) {
        const fd = new FormData();
        fd.append('role', _role);
        Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(OTP_ENDPOINT, { method: 'POST', body: fd });
        return r.json();
    }

    // ── Step 1: Send OTP ──────────────────────────────────────────────────────
    async function handleSend() {
        const email = (elEmail?.value || '').trim();
        if (!email) { showMsg('error', 'Please enter your email address.'); return; }

        setLoading(btnSend, true, 'Sending…');
        try {
            const data = await post({ action: 'request', email });
            if (data.success) {
                _verifiedEmail = email;
                showStep('otp');
                showMsg('success', data.message);
                startResendCooldown();
            } else {
                showMsg('error', data.message);
            }
        } catch {
            showMsg('error', 'Network error. Please try again.');
        } finally {
            setLoading(btnSend, false, 'Send OTP');
        }
    }

    // ── Step 2: Verify OTP ────────────────────────────────────────────────────
    async function handleVerify() {
        const otp = (elOtp?.value || '').replace(/\s/g, '');
        if (otp.length !== 6) { showMsg('error', 'Please enter the 6-digit OTP.'); return; }

        setLoading(btnVerify, true, 'Verifying…');
        try {
            const data = await post({ action: 'verify', email: _verifiedEmail, otp });
            if (data.success) {
                showStep('reset');
                showMsg('success', 'OTP verified! Set your new password below.');
            } else {
                showMsg('error', data.message);
            }
        } catch {
            showMsg('error', 'Network error. Please try again.');
        } finally {
            setLoading(btnVerify, false, 'Verify OTP');
        }
    }

    // ── Step 3: Reset password ────────────────────────────────────────────────
    async function handleReset() {
        const otp      = (elOtp?.value || '').replace(/\s/g, '');
        const newPass  = elNewPass?.value  || '';
        const confPass = elConfPass?.value || '';

        if (newPass.length < 8) { showMsg('error', 'Password must be at least 8 characters.'); return; }
        if (newPass !== confPass) { showMsg('error', 'Passwords do not match.'); return; }

        setLoading(btnReset, true, 'Resetting…');
        try {
            const data = await post({
                action:           'reset',
                email:            _verifiedEmail,
                otp,
                new_password:     newPass,
                confirm_password: confPass,
            });
            if (data.success) {
                showMsg('success', data.message);
                // Disable form fields after success
                if (elNewPass)  elNewPass.disabled  = true;
                if (elConfPass) elConfPass.disabled  = true;
                if (btnReset)   btnReset.disabled    = true;
                // Redirect to login after 2.5s
                setTimeout(() => {
                    const loginTab = document.getElementById('tab-login');
                    if (loginTab) loginTab.click();
                    if (typeof switchTab === 'function') switchTab('login');
                }, 2500);
            } else {
                showMsg('error', data.message);
            }
        } catch {
            showMsg('error', 'Network error. Please try again.');
        } finally {
            setLoading(btnReset, false, 'Reset password');
        }
    }

    // ── Resend OTP ────────────────────────────────────────────────────────────
    async function handleResend() {
        if (!_verifiedEmail) return;
        setLoading(btnResend, true, 'Resending…');
        try {
            const data = await post({ action: 'request', email: _verifiedEmail });
            showMsg(data.success ? 'success' : 'error', data.message);
            if (data.success) startResendCooldown();
        } catch {
            showMsg('error', 'Network error.');
        } finally {
            setLoading(btnResend, false, 'Resend OTP');
        }
    }

    // ── Resend cooldown (60 seconds) ──────────────────────────────────────────
    function startResendCooldown() {
        if (!btnResend) return;
        let secs = 60;
        btnResend.disabled = true;
        btnResend.textContent = `Resend OTP (${secs}s)`;
        clearInterval(_resendTimer);
        _resendTimer = setInterval(() => {
            secs--;
            btnResend.textContent = secs > 0 ? `Resend OTP (${secs}s)` : 'Resend OTP';
            if (secs <= 0) {
                clearInterval(_resendTimer);
                btnResend.disabled = false;
            }
        }, 1000);
    }

    // ── Password strength ─────────────────────────────────────────────────────
    function checkStrength(val) {
        if (!elStrength) return;
        let s = 0;
        if (val.length >= 8)           s++;
        if (/[A-Z]/.test(val))         s++;
        if (/[0-9]/.test(val))         s++;
        if (/[^A-Za-z0-9]/.test(val))  s++;
        elStrength.style.width      = (s * 25) + '%';
        elStrength.style.background = ['#444', '#e74c3c', '#e67e22', '#2ecc71', '#27ae60'][s];
    }

    // ── Expose public API ──────────────────────────────────────────────────────
    global.initOtpRecovery = initOtpRecovery;
    global.otpGoBack        = function () { showStep('email'); };

}(window));