# Production Readiness Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Make the Team App production-ready with proper security hardening, missing features, and operational tooling.

**Architecture:** PHP 8.x vanilla + MySQL 8.x + Vanilla JS. No composer, no frameworks.

**Worktree:** `C:\Users\carlo\proyects\claude-skills\.worktrees\prod-readiness`
**Branch:** `feature/prod-readiness`

**Tech Stack:** PHP 8.x, PDO, vanilla JS, Apache (.htaccess)

---

## Phase 1 — Security (Critical)

### Task 1: Environment configuration (.env)

**Goal:** Move all hardcoded credentials out of `config/config.php` into a `.env` file.

**Files:**
- Modify: `config/config.php`
- Create: `.env.example`
- Create: `.env` (local, gitignored)
- Modify: `.gitignore`

**Step 1: Create `.env.example`**

```
APP_ENV=production
APP_URL=https://yourdomain.com/teamapp/public

DB_HOST=localhost
DB_NAME=teamapp
DB_USER=dbuser
DB_PASS=strongpassword

ENCRYPT_KEY=replace-with-32-random-chars-here

SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=noreply@example.com
SMTP_PASS=smtppassword
SMTP_FROM=noreply@example.com
SMTP_FROM_NAME=Team App
```

**Step 2: Create `.env` for local dev**

Copy `.env.example` to `.env` and fill with local values:
```
APP_ENV=development
APP_URL=http://localhost/teamapp/public
DB_HOST=localhost
DB_NAME=teamapp
DB_USER=root
DB_PASS=
ENCRYPT_KEY=dev-key-32-chars-padded-here-xx
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_USER=
SMTP_PASS=
SMTP_FROM=noreply@localhost
SMTP_FROM_NAME=Team App Dev
```

**Step 3: Add `.env` to `.gitignore`**

Check if `.gitignore` exists. If not, create it. Add:
```
.env
storage/logs/
```

**Step 4: Rewrite `config/config.php` to parse `.env`**

```php
<?php
// Parse .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

define('APP_ENV',    env('APP_ENV', 'production'));
define('APP_URL',    env('APP_URL', 'http://localhost/teamapp/public'));
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', 'teamapp'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    env('DB_PASS', ''));
define('ENCRYPT_KEY',env('ENCRYPT_KEY', ''));
define('SMTP_HOST',  env('SMTP_HOST', 'localhost'));
define('SMTP_PORT',  (int) env('SMTP_PORT', '25'));
define('SMTP_USER',  env('SMTP_USER', ''));
define('SMTP_PASS',  env('SMTP_PASS', ''));
define('SMTP_FROM',  env('SMTP_FROM', 'noreply@localhost'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'Team App'));
```

**Step 5: Verify PHP lint**

```bash
php -l config/config.php
```
Expected: No syntax errors.

**Step 6: Commit**

```bash
git add config/config.php .env.example .gitignore
git commit -m "feat: load config from .env file"
```

Note: Do NOT add `.env` to git. It stays local.

---

### Task 2: PHP production settings + error logging

**Goal:** Disable error display in production, log errors to file.

**Files:**
- Modify: `public/index.php`
- Create: `storage/logs/.gitkeep`
- Modify: `.gitignore` (add `storage/logs/*.log`)

**Step 1: Create `storage/logs/` directory**

```bash
mkdir -p storage/logs
touch storage/logs/.gitkeep
```

**Step 2: Add to `.gitignore`**

Add these lines to `.gitignore`:
```
storage/logs/*.log
```

**Step 3: Modify `public/index.php` — add at the very top, before everything**

```php
<?php
// Must be first lines
if (!defined('APP_ENV')) {
    require_once __DIR__ . '/../config/config.php';
}

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../storage/logs/php-errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
```

The existing `session_start()` and requires come after.

**Step 4: PHP lint**

```bash
php -l public/index.php
```

**Step 5: Commit**

```bash
git add public/index.php storage/logs/.gitkeep .gitignore
git commit -m "feat: configure PHP error logging for production"
```

---

### Task 3: Session security hardening

**Goal:** Add `httponly`, `samesite=Lax`, and `secure` (on HTTPS) flags to session cookies.

**Files:**
- Modify: `public/index.php`

**Step 1: Replace `session_start()` in `public/index.php`**

Find the line `session_start();` and replace it with:

```php
// Secure session configuration
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Regenerate session ID periodically to prevent fixation
if (!isset($_SESSION['_init'])) {
    session_regenerate_id(true);
    $_SESSION['_init'] = true;
}
```

**Step 2: PHP lint**

```bash
php -l public/index.php
```

**Step 3: Commit**

```bash
git add public/index.php
git commit -m "feat: harden session cookies (httponly, samesite, secure)"
```

---

### Task 4: Apache .htaccess security

**Goal:** Block direct access to sensitive directories, enforce document root, add security headers.

**Files:**
- Create: `public/.htaccess`
- Create: `.htaccess` (root)

**Step 1: Create `public/.htaccess`**

```apache
Options -Indexes
RewriteEngine On

# Force HTTPS (uncomment in production)
# RewriteCond %{HTTPS} off
# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Route all requests to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

# Security headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Block access to .env and hidden files
<FilesMatch "^\.|\.env">
    Order allow,deny
    Deny from all
</FilesMatch>
```

**Step 2: Create root `.htaccess`**

```apache
# Block all direct access — serve only through public/
Options -Indexes

<FilesMatch ".*">
    Order allow,deny
    Deny from all
</FilesMatch>

# Allow access to public/ directory
<Directory "public">
    Order deny,allow
    Allow from all
</Directory>
```

**Step 3: Commit**

```bash
git add public/.htaccess .htaccess
git commit -m "feat: add Apache .htaccess security rules and headers"
```

---

### Task 5: CSRF protection

**Goal:** Generate a CSRF token per session, embed it in all pages, validate on every POST API request.

**Files:**
- Modify: `app/includes/auth.php`
- Modify: `app/includes/layout_top.php`
- Modify: `app/assets/js/utils.js`
- Modify: all `app/api/*.php` files that handle POST

**Step 1: Add CSRF functions to `app/includes/auth.php`**

Add at the end of the file:

```php
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}
```

**Step 2: Embed token in `app/includes/layout_top.php`**

Add after the `<meta charset>` tag:
```html
<meta name="csrf-token" content="<?= csrf_token() ?>">
```

**Step 3: Update `app/assets/js/utils.js` — add CSRF header to all POST fetch calls**

Add this function and monkey-patch fetch to auto-add the header:

```js
// CSRF token from meta tag
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

// Wrap fetch to auto-inject CSRF header on POST/PUT/DELETE
const _origFetch = window.fetch.bind(window);
window.fetch = function(url, opts = {}) {
    const method = (opts.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        opts.headers = Object.assign({}, opts.headers, { 'X-CSRF-Token': CSRF_TOKEN });
    }
    return _origFetch(url, opts);
};
```

Add this code at the TOP of `utils.js`, before the existing `showToast` and `showConfirm` functions.

**Step 4: Add `verify_csrf()` to all POST-handling API files**

For each of these files, find where the POST action is handled (usually inside the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` block or the action-specific handler) and add `verify_csrf();` as the first call inside it:

Files to update:
- `app/api/issues.php` — in actions: create, update, delete
- `app/api/pages.php` — in actions: save, delete
- `app/api/comments.php` — in actions: create, delete
- `app/api/labels.php` — in actions: create, delete, add, remove
- `app/api/team.php` — in actions: invite, remove
- `app/api/github.php` — in actions: connect, create_branch, sync_pr_status
- `app/api/profile.php` — all actions (update_profile, change_password, update_github_token)
- `app/api/projects.php` — in action: create

For each file, read it first to understand the structure. The pattern is:
```php
// At the top of the POST-handling block:
require_once __DIR__ . '/../includes/auth.php';
// ... existing requires ...

// Inside each POST action handler:
verify_csrf();
```

Note: `app/api/auth.php` (login) should NOT require CSRF since the user isn't logged in yet (no session token).

**Step 5: PHP lint all modified files**

```bash
find app/api -name "*.php" -exec php -l {} \;
```

**Step 6: Commit**

```bash
git add app/includes/auth.php app/includes/layout_top.php app/assets/js/utils.js app/api/
git commit -m "feat: add CSRF token protection to all POST API endpoints"
```

---

## Phase 2 — Features

### Task 6: Full-text search (issues + wiki)

**Goal:** Search bar in sidebar that searches both issues and wiki pages within the active project.

**Files:**
- Create: `app/api/search.php`
- Modify: `app/includes/layout_top.php`
- Modify: `app/assets/css/main.css`

**Step 1: Create `app/api/search.php`**

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_auth();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$project_id = (int)($_GET['project_id'] ?? 0);

if (strlen($q) < 2 || !$project_id) {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $q . '%';

// Search issues
$stmt = $pdo->prepare('SELECT id, title, status, priority, "issue" as type FROM issues WHERE project_id = ? AND (title LIKE ? OR description LIKE ?) ORDER BY updated_at DESC LIMIT 10');
$stmt->execute([$project_id, $like, $like]);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search wiki pages
$stmt = $pdo->prepare('SELECT id, title, "page" as type FROM pages WHERE project_id = ? AND (title LIKE ? OR content LIKE ?) ORDER BY updated_at DESC LIMIT 10');
$stmt->execute([$project_id, $like, $like]);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['results' => array_merge($issues, $pages)]);
```

**Step 2: Add search UI to `app/includes/layout_top.php`**

Add after the `<ul>` nav links section (before `</nav>`), inside the sidebar:

```html
<div class="search-box" style="margin-top:1rem;">
    <input type="text" id="search-input" placeholder="Search..." style="width:100%;padding:0.4rem 0.5rem;border:1px solid #444;border-radius:4px;background:#2a2a4a;color:#ccc;font-size:0.85rem;">
    <div id="search-results" class="search-results hidden"></div>
</div>
```

Add the search JS after the project switcher script block:

```html
<script>
(function() {
    const input = document.getElementById('search-input');
    const results = document.getElementById('search-results');
    let timer;
    if (!input) return;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { results.classList.add('hidden'); return; }
        timer = setTimeout(async () => {
            const pid = localStorage.getItem('active_project_id') || 0;
            const res = await fetch(`${APP_URL}/app/api/search.php?q=${encodeURIComponent(q)}&project_id=${pid}`);
            const data = await res.json();
            if (!data.results.length) { results.innerHTML = '<div class="search-empty">No results</div>'; }
            else {
                results.innerHTML = data.results.map(r => {
                    const href = r.type === 'issue'
                        ? `${APP_URL}?page=issues`
                        : `${APP_URL}?page=wiki`;
                    const icon = r.type === 'issue' ? '🐛' : '📄';
                    return `<a class="search-result-item" href="${href}" data-id="${r.id}" data-type="${r.type}">${icon} ${r.title}</a>`;
                }).join('');
            }
            results.classList.remove('hidden');
        }, 300);
    });
    document.addEventListener('click', e => {
        if (!results.contains(e.target) && e.target !== input) results.classList.add('hidden');
    });
})();
</script>
```

**Step 3: Add search CSS to `app/assets/css/main.css`**

```css
/* Search */
.search-box { position: relative; }
.search-results {
    position: absolute;
    left: 0; right: 0;
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    z-index: 300;
    max-height: 280px;
    overflow-y: auto;
    margin-top: 0.25rem;
}
.search-result-item {
    display: block;
    padding: 0.5rem 0.75rem;
    color: #333;
    text-decoration: none;
    font-size: 0.85rem;
    border-bottom: 1px solid #f3f4f6;
}
.search-result-item:hover { background: #f5f5ff; }
.search-empty { padding: 0.75rem; color: #999; font-size: 0.85rem; }
```

**Step 4: PHP lint**

```bash
php -l app/api/search.php
```

**Step 5: Commit**

```bash
git add app/api/search.php app/includes/layout_top.php app/assets/css/main.css
git commit -m "feat: add full-text search for issues and wiki pages"
```

---

### Task 7: Email (SMTP mailer + invite emails)

**Goal:** Send invite email with temporary password when a team member is invited.

**Files:**
- Create: `app/includes/mailer.php`
- Modify: `app/api/team.php`

**Step 1: Create `app/includes/mailer.php`**

Simple SMTP mailer using PHP's `fsockopen` — no composer required:

```php
<?php
/**
 * Minimal SMTP mailer — no dependencies.
 * Supports STARTTLS (port 587) and plain (port 25/1025).
 */
function send_email(string $to, string $subject, string $htmlBody): bool {
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    // In development with no SMTP, just log and return true
    if (APP_ENV !== 'production' && empty($host)) {
        error_log("EMAIL to=$to subject=$subject");
        return true;
    }

    try {
        $errno = 0; $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$sock) {
            error_log("SMTP connect failed: $errstr");
            return false;
        }

        $read = function() use ($sock) { return fgets($sock, 512); };
        $send = function(string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

        $read(); // greeting
        $send("EHLO " . gethostname());
        while (($line = $read()) && substr($line, 3, 1) === '-') {}

        // STARTTLS
        if ($port == 587) {
            $send("STARTTLS");
            $read();
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send("EHLO " . gethostname());
            while (($line = $read()) && substr($line, 3, 1) === '-') {}
        }

        // Auth
        if ($user) {
            $send("AUTH LOGIN");
            $read();
            $send(base64_encode($user));
            $read();
            $send(base64_encode($pass));
            $read();
        }

        $boundary = md5(uniqid());
        $send("MAIL FROM:<$from>");  $read();
        $send("RCPT TO:<$to>");      $read();
        $send("DATA");               $read();

        $plainText = strip_tags($htmlBody);
        $headers = "From: $fromName <$from>\r\n"
                 . "To: $to\r\n"
                 . "Subject: $subject\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

        $body = "$headers\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
              . "$plainText\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
              . "$htmlBody\r\n"
              . "--$boundary--\r\n";

        $send($body . ".");
        $read();
        $send("QUIT");
        fclose($sock);
        return true;
    } catch (\Throwable $e) {
        error_log("Mailer error: " . $e->getMessage());
        return false;
    }
}
```

**Step 2: Modify `app/api/team.php` — send invite email**

Find the `invite_member` function. After the user is created and the temp password is set, add:

```php
require_once __DIR__ . '/../includes/mailer.php';

$loginUrl = APP_URL . '?page=login';
$html = "
<h2>You've been invited to Team App</h2>
<p>Your account has been created. Use these credentials to log in:</p>
<ul>
  <li><strong>Email:</strong> $email</li>
  <li><strong>Temporary password:</strong> $temp_password</li>
</ul>
<p><a href=\"$loginUrl\">Log in now</a></p>
<p>Please change your password after logging in.</p>
";
send_email($email, 'You have been invited to Team App', $html);
```

The `$email` and `$temp_password` variables should already be in scope where you add this — find the right spot in the existing invite flow.

**Step 3: PHP lint**

```bash
php -l app/includes/mailer.php
php -l app/api/team.php
```

**Step 4: Commit**

```bash
git add app/includes/mailer.php app/api/team.php
git commit -m "feat: add SMTP mailer and send invite email with temp password"
```

---

### Task 8: Password reset flow

**Goal:** "Forgot password" link on login page, sends reset link via email, allows setting new password.

**Files:**
- Modify: `db/schema.sql`
- Modify: `app/api/auth.php`
- Modify: `app/pages/login.php`

**Step 1: Add `password_resets` table to `db/schema.sql`**

Add after the `users` table definition:

```sql
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Step 2: Create the table in the running DB**

```bash
# Run only the new table creation (don't re-run whole schema)
# This will be done manually by the user, or can be done via:
mysql -u root teamapp -e "CREATE TABLE IF NOT EXISTS password_resets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token VARCHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB;"
```

If MySQL is not accessible, skip this step — the PHP code will fail gracefully.

**Step 3: Add `forgot_password` and `reset_password` actions to `app/api/auth.php`**

Read `app/api/auth.php` first to understand its structure, then add these action handlers:

```php
// Action: forgot_password
// POST body: { email }
if ($action === 'forgot_password') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    if (!$email) { echo json_encode(['success' => false, 'error' => 'Email required']); exit; }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    // Always return success to not leak user existence
    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE token=VALUES(token), expires_at=VALUES(expires_at), used=0');
        $stmt->execute([$user['id'], $token, $expires]);

        require_once __DIR__ . '/../includes/mailer.php';
        $resetUrl = APP_URL . '?page=login&reset_token=' . $token;
        $html = "<h2>Password Reset</h2><p>Click the link below to reset your password (expires in 1 hour):</p><p><a href=\"$resetUrl\">Reset Password</a></p>";
        send_email($email, 'Password Reset Request', $html);
    }
    echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link was sent.']);
    exit;
}

// Action: reset_password
// POST body: { token, password }
if ($action === 'reset_password') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = trim($data['token'] ?? '');
    $password = $data['password'] ?? '';
    if (!$token || strlen($password) < 6) { echo json_encode(['success' => false, 'error' => 'Invalid request']); exit; }

    $stmt = $pdo->prepare('SELECT pr.user_id FROM password_resets pr WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Invalid or expired token']); exit; }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')->execute([$token]);
    echo json_encode(['success' => true]);
    exit;
}
```

**Step 4: Update `app/pages/login.php`**

Read the file first. Then:
- Add "Forgot password?" link below the login form
- Add a forgot-password form (hidden by default, shown on link click)
- Add a reset-password form (shown when URL has `?reset_token=...`)
- Add the JS to handle these flows

The JS should:
1. On page load, check for `reset_token` in URL params. If present, show reset form, hide login form.
2. "Forgot password?" link shows the forgot-password form, hides login form.
3. Forgot form submits to `auth.php?action=forgot_password` and shows a success message.
4. Reset form submits to `auth.php?action=reset_password` with token + new password, on success redirects to login.

Add this HTML inside `app/pages/login.php` (after the existing login form):

```html
<!-- Forgot password link -->
<p style="text-align:center;margin-top:0.75rem;font-size:0.875rem;">
    <a href="#" id="forgot-link" style="color:#4f46e5;">Forgot password?</a>
</p>

<!-- Forgot password form -->
<div id="forgot-form" class="hidden">
    <h2 style="margin-bottom:1rem;">Reset Password</h2>
    <label>Email</label>
    <input type="email" id="forgot-email" placeholder="your@email.com">
    <button id="forgot-btn" class="btn btn-primary" style="width:100%;">Send Reset Link</button>
    <p id="forgot-msg" class="hidden" style="color:#16a34a;margin-top:0.75rem;"></p>
    <p style="text-align:center;margin-top:0.75rem;font-size:0.875rem;">
        <a href="#" id="back-to-login" style="color:#4f46e5;">Back to login</a>
    </p>
</div>

<!-- Reset password form -->
<div id="reset-form" class="hidden">
    <h2 style="margin-bottom:1rem;">Set New Password</h2>
    <label>New Password</label>
    <input type="password" id="new-password" placeholder="Min. 6 characters">
    <button id="reset-btn" class="btn btn-primary" style="width:100%;">Set Password</button>
    <p id="reset-error" class="error hidden"></p>
</div>
```

Add the JS at the bottom of `app/pages/login.php`:

```html
<script>
const APP_URL = '<?= APP_URL ?>';
const params = new URLSearchParams(location.search);
const resetToken = params.get('reset_token');

const loginForm = document.getElementById('login-form') || document.querySelector('.auth-box form');
const forgotSection = document.getElementById('forgot-form');
const resetSection = document.getElementById('reset-form');

if (resetToken && resetSection) {
    // Show reset form
    document.querySelectorAll('.auth-box > *:not(#reset-form)').forEach(el => el.classList.add('hidden'));
    resetSection.classList.remove('hidden');
}

document.getElementById('forgot-link')?.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('.auth-box > *:not(#forgot-form)').forEach(el => el.classList.add('hidden'));
    forgotSection.classList.remove('hidden');
});

document.getElementById('back-to-login')?.addEventListener('click', e => {
    e.preventDefault();
    location.reload();
});

document.getElementById('forgot-btn')?.addEventListener('click', async () => {
    const email = document.getElementById('forgot-email').value.trim();
    if (!email) return;
    const btn = document.getElementById('forgot-btn');
    btn.disabled = true;
    const res = await fetch(`${APP_URL}/app/api/auth.php?action=forgot_password`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ email })
    });
    const d = await res.json();
    const msg = document.getElementById('forgot-msg');
    msg.textContent = d.message || 'Reset link sent if email exists.';
    msg.classList.remove('hidden');
    btn.disabled = false;
});

document.getElementById('reset-btn')?.addEventListener('click', async () => {
    const password = document.getElementById('new-password').value;
    const err = document.getElementById('reset-error');
    if (password.length < 6) { err.textContent = 'Password must be at least 6 characters'; err.classList.remove('hidden'); return; }
    const res = await fetch(`${APP_URL}/app/api/auth.php?action=reset_password`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ token: resetToken, password })
    });
    const d = await res.json();
    if (d.success) {
        location.href = `${APP_URL}?page=login`;
    } else {
        err.textContent = d.error || 'Invalid or expired token';
        err.classList.remove('hidden');
    }
});
</script>
```

**Step 5: PHP lint**

```bash
php -l app/api/auth.php
php -l app/pages/login.php
```

**Step 6: Commit**

```bash
git add db/schema.sql app/api/auth.php app/pages/login.php
git commit -m "feat: add forgot password and password reset flow"
```

---

## Phase 3 — Operational

### Task 9: DB migrations infrastructure

**Goal:** A simple migration runner so DB schema changes can be applied incrementally in production without re-running the full schema.

**Files:**
- Create: `db/migrations/001_initial_schema.sql`
- Create: `db/migrations/002_add_password_resets.sql`
- Create: `db/migrations/003_add_pr_columns.sql`
- Create: `db/migrate.php`

**Step 1: Create `db/migrations/001_initial_schema.sql`**

This is just a reference — marks the baseline schema as migration 001. Content:
```sql
-- Migration 001: Initial schema
-- This represents the baseline schema in db/schema.sql
-- Applied: baseline
```

**Step 2: Create `db/migrations/002_add_password_resets.sql`**

```sql
-- Migration 002: Add password_resets table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Step 3: Create `db/migrations/003_add_pr_columns.sql`**

```sql
-- Migration 003: Add PR tracking columns to branches
ALTER TABLE branches
    ADD COLUMN IF NOT EXISTS pr_number INT NULL,
    ADD COLUMN IF NOT EXISTS pr_url VARCHAR(500) NULL;
```

**Step 4: Create `db/migrate.php`**

This script tracks which migrations have been run in a `_migrations` table and applies new ones:

```php
<?php
/**
 * DB Migration Runner
 * Usage: php db/migrate.php
 */
require_once __DIR__ . '/../config/config.php';

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Create migrations tracking table
$pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Get applied migrations
$applied = $pdo->query("SELECT filename FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);

// Find migration files
$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied)) {
        echo "  [skip] $name\n";
        continue;
    }
    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$name]);
        echo "  [ok]   $name\n";
        $ran++;
    } catch (\PDOException $e) {
        echo "  [FAIL] $name: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $ran ? "\n$ran migration(s) applied.\n" : "\nAll migrations up to date.\n";
```

**Step 5: PHP lint**

```bash
php -l db/migrate.php
```

**Step 6: Commit**

```bash
git add db/migrations/ db/migrate.php
git commit -m "feat: add DB migration runner with initial migrations"
```

---

### Task 10: Application error logging

**Goal:** A simple logger that writes structured entries to `storage/logs/app.log`. Log auth events, API errors, and GitHub failures.

**Files:**
- Create: `app/includes/logger.php`
- Modify: `app/api/auth.php` (log login success/failure)
- Modify: `app/api/github.php` (log API errors)

**Step 1: Create `app/includes/logger.php`**

```php
<?php
function app_log(string $level, string $message, array $context = []): void {
    $logFile = __DIR__ . '/../../storage/logs/app.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $entry = json_encode([
        'time'    => date('c'),
        'level'   => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ]) . PHP_EOL;

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}
```

**Step 2: Add logging to `app/api/auth.php`**

Read the file first, then:
- After successful login: `app_log('info', 'User logged in', ['user_id' => $user['id'], 'email' => $user['email']]);`
- After failed login attempt: `app_log('warning', 'Failed login attempt', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);`

Add `require_once __DIR__ . '/../includes/logger.php';` at the top of the file.

**Step 3: Add logging to `app/api/github.php`**

Read the file first, then find the `github_request()` function. After the HTTP status check that detects an error, add:
```php
require_once __DIR__ . '/../includes/logger.php';
app_log('error', 'GitHub API error', ['endpoint' => $endpoint, 'status' => $status, 'response' => $decoded]);
```

Add the require at the top of the file.

**Step 4: PHP lint**

```bash
php -l app/includes/logger.php
php -l app/api/auth.php
php -l app/api/github.php
```

**Step 5: Commit**

```bash
git add app/includes/logger.php app/api/auth.php app/api/github.php
git commit -m "feat: add structured app logging for auth events and GitHub errors"
```

---

### Task 11: Docker Compose + README setup guide

**Goal:** `docker-compose.yml` to run the app locally + `README.md` with setup instructions.

**Files:**
- Create: `docker-compose.yml`
- Create: `docker/nginx.conf`
- Create: `docker/php.ini`
- Create: `README.md`

**Step 1: Create `docker-compose.yml`**

```yaml
version: '3.8'

services:
  app:
    image: php:8.2-fpm
    volumes:
      - .:/var/www/html
      - ./docker/php.ini:/usr/local/etc/php/conf.d/custom.ini
    depends_on:
      - db
    environment:
      - APP_ENV=development

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app

  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: teamapp
    volumes:
      - db_data:/var/lib/mysql
      - ./db/schema.sql:/docker-entrypoint-initdb.d/01_schema.sql
    ports:
      - "3306:3306"

volumes:
  db_data:
```

**Step 2: Create `docker/nginx.conf`**

```nginx
server {
    listen 80;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block sensitive directories
    location ~ ^/(config|app|db|storage)/ {
        deny all;
    }
}
```

**Step 3: Create `docker/php.ini`**

```ini
display_errors = Off
log_errors = On
error_log = /var/www/html/storage/logs/php-errors.log
upload_max_filesize = 10M
post_max_size = 10M
```

**Step 4: Create `README.md`**

```markdown
# Team App

Internal team collaboration tool — Notion-style wiki + Jira-style issues + GitHub integration.

## Features

- **Wiki** — rich text pages with version history, nested structure
- **Issues** — kanban board, priorities, labels, assignees, comments
- **GitHub** — connect repos, create branches from issues, track PRs
- **Team** — invite members, role-based access (admin/member)
- **Dashboard** — activity feed and issue stats

## Tech Stack

- PHP 8.x (no framework)
- MySQL 8.x
- Vanilla JS
- Apache / Nginx

## Quick Start (Docker)

```bash
# 1. Clone and configure
cp .env.example .env
# Edit .env with your settings

# 2. Start services
docker-compose up -d

# 3. Run migrations
docker-compose exec app php db/migrate.php

# 4. Open in browser
open http://localhost:8080
```

Default admin login after running schema: set up manually in DB or use the first registered user.

## Manual Setup (Apache/Nginx)

### Requirements

- PHP 8.x with extensions: `pdo_mysql`, `openssl`
- MySQL 8.x
- Apache with `mod_rewrite` or Nginx

### Steps

```bash
# 1. Configure environment
cp .env.example .env
nano .env   # fill in DB_HOST, DB_NAME, DB_USER, DB_PASS, ENCRYPT_KEY, APP_URL

# 2. Create database
mysql -u root -p -e "CREATE DATABASE teamapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Run schema + migrations
mysql -u root -p teamapp < db/schema.sql
php db/migrate.php

# 4. Set permissions
mkdir -p storage/logs
chmod -R 755 storage/

# 5. Configure web server — point document root to public/
```

### Apache Virtual Host

```apache
<VirtualHost *:80>
    DocumentRoot /path/to/teamapp/public
    <Directory /path/to/teamapp/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx

See `docker/nginx.conf` for reference configuration.

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `APP_ENV` | `development` or `production` | `production` |
| `APP_URL` | Full URL to public/ directory | — |
| `DB_HOST` | MySQL host | `localhost` |
| `DB_NAME` | Database name | `teamapp` |
| `DB_USER` | DB username | — |
| `DB_PASS` | DB password | — |
| `ENCRYPT_KEY` | 32-char key for GitHub token encryption | — |
| `SMTP_HOST` | SMTP server hostname | — |
| `SMTP_PORT` | SMTP port (587 for STARTTLS) | `25` |
| `SMTP_USER` | SMTP username | — |
| `SMTP_PASS` | SMTP password | — |
| `SMTP_FROM` | From email address | — |
| `SMTP_FROM_NAME` | From display name | `Team App` |

## Security Notes

- Never commit `.env` to git
- Set a strong, unique `ENCRYPT_KEY` (32 random chars)
- Enable HTTPS and uncomment the HTTPS redirect in `public/.htaccess`
- GitHub tokens are stored AES-256-CBC encrypted
```

**Step 5: Commit**

```bash
git add docker-compose.yml docker/ README.md
git commit -m "feat: add Docker Compose setup and README documentation"
```

---

## Verification

After all tasks:

```bash
# PHP lint entire codebase
find app -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"

# Check all files committed
git status

# Review final log
git log --oneline
```
