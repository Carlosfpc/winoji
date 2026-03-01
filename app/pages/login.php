<?php
if (isset($_SESSION['user'])) {
    header('Location: ' . APP_URL . '?page=dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login — WINOJI</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/app/assets/css/main.css">
</head>
<body class="auth-page">
    <div class="auth-box">
        <h1>WINOJI</h1>
        <form id="login-form">
            <label>Email</label>
            <input type="email" id="email" required>
            <label>Password</label>
            <input type="password" id="password" required>
            <button type="submit">Login</button>
            <p id="error-msg" class="error hidden"></p>
        </form>
        <p class="text-center mt-3 text-base">
            <a href="#" id="forgot-link" class="text-primary-color">Forgot password?</a>
        </p>

        <!-- Forgot password form (hidden by default) -->
        <div id="forgot-form" style="display:none;">
            <h2 class="mb-4">Reset Password</h2>
            <label>Email</label>
            <input type="email" id="forgot-email" placeholder="your@email.com">
            <button id="forgot-btn" class="btn btn-primary w-full">Send Reset Link</button>
            <p id="forgot-msg" class="text-success mt-3 text-base hidden"></p>
            <p class="text-center mt-3 text-base">
                <a href="#" id="back-to-login" class="text-primary-color">Back to login</a>
            </p>
        </div>

        <!-- Reset password form (shown when URL has ?reset_token=...) -->
        <div id="reset-form" style="display:none;">
            <h2 class="mb-4">Set New Password</h2>
            <label>New Password</label>
            <input type="password" id="new-password" placeholder="Min. 6 characters">
            <button id="reset-btn" class="btn btn-primary w-full">Set Password</button>
            <p id="reset-error" class="error hidden"></p>
        </div>
    </div>
    <script>
    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const res = await fetch('<?= APP_URL ?>/app/api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: document.getElementById('email').value,
                password: document.getElementById('password').value
            })
        });
        const data = await res.json();
        if (data.success) {
            window.location.href = '<?= APP_URL ?>?page=dashboard';
        } else {
            const err = document.getElementById('error-msg');
            err.textContent = data.error;
            err.classList.remove('hidden');
        }
    });
    </script>
<script>
(function() {
    const APP_URL = '<?= APP_URL ?>';
    const params = new URLSearchParams(location.search);
    const resetToken = params.get('reset_token');

    // Elements
    const loginFields = document.querySelectorAll('.auth-box > *:not(#forgot-form):not(#reset-form)');
    const forgotForm = document.getElementById('forgot-form');
    const resetForm  = document.getElementById('reset-form');

    function showOnly(el) {
        loginFields.forEach(e => e.style.display = 'none');
        if (forgotForm) forgotForm.style.display = 'none';
        if (resetForm)  resetForm.style.display  = 'none';
        if (el) el.style.display = 'block';
    }

    // If reset token in URL, show reset form
    if (resetToken && resetForm) {
        showOnly(resetForm);
    }

    // Forgot password link
    document.getElementById('forgot-link')?.addEventListener('click', e => {
        e.preventDefault();
        showOnly(forgotForm);
    });

    // Back to login
    document.getElementById('back-to-login')?.addEventListener('click', e => {
        e.preventDefault();
        location.reload();
    });

    // Send reset link
    document.getElementById('forgot-btn')?.addEventListener('click', async () => {
        const email = document.getElementById('forgot-email')?.value.trim();
        if (!email) return;
        const btn = document.getElementById('forgot-btn');
        btn.disabled = true;
        const res = await fetch(`${APP_URL}/app/api/auth.php?action=forgot_password`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        const d = await res.json();
        const msg = document.getElementById('forgot-msg');
        if (msg) { msg.textContent = d.message || 'Reset link sent.'; msg.classList.remove('hidden'); }
        btn.disabled = false;
    });

    // Set new password
    document.getElementById('reset-btn')?.addEventListener('click', async () => {
        const password = document.getElementById('new-password')?.value || '';
        const err = document.getElementById('reset-error');
        if (password.length < 6) {
            if (err) { err.textContent = 'Password must be at least 6 characters'; err.classList.remove('hidden'); }
            return;
        }
        const res = await fetch(`${APP_URL}/app/api/auth.php?action=reset_password`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: resetToken, password })
        });
        const d = await res.json();
        if (d.success) {
            location.href = `${APP_URL}?page=login`;
        } else {
            if (err) { err.textContent = d.error || 'Invalid or expired token'; err.classList.remove('hidden'); }
        }
    });
})();
</script>
</body>
</html>
