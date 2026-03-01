# WINOJI Completion — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all critical bugs and implement all missing features to make the WINOJI production-ready.

**Architecture:** PHP 8.x vanilla + MySQL. Pages server-render HTML shells; JS fetches data via API endpoints in `app/api/`. Auth via PHP sessions. All DB via PDO prepared statements.

**Tech Stack:** PHP 8.x, MySQL 8.x, Vanilla JS, DOMPurify (CDN), GitHub REST API via cURL.

---

## PHASE 1 — CRITICAL BUG FIXES

---

### Task 1: Add team_id to session on login

**Why:** `app/api/team.php` hardcodes `team_id=1`. Need to get it from the logged-in user's `team_members` row.

**Files:**
- Modify: `app/api/auth.php`

**Step 1: Update `attempt_login()` to fetch team_id**

In `app/api/auth.php`, replace the entire `attempt_login` function:

```php
function attempt_login(string $email, string $password): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    // Get team membership
    $stmt2 = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = ? LIMIT 1');
    $stmt2->execute([$user['id']]);
    $team = $stmt2->fetch();

    return ['success' => true, 'user' => [
        'id'      => $user['id'],
        'name'    => $user['name'],
        'email'   => $user['email'],
        'role'    => $user['role'],
        'team_id' => $team ? (int)$team['team_id'] : 1,
    ]];
}
```

**Step 2: Update `app/api/team.php` to use session team_id**

In `app/api/team.php`, replace the three hardcoded `1` values in the `match(true)` block:

```php
match(true) {
    $method === 'GET'  && $action === 'members' => print json_encode(['success'=>true,'data'=>list_team_members(current_user()['team_id'] ?? 1)]),
    $method === 'POST' && $action === 'invite'  => (function() use ($b) { require_role('admin'); $team_id = current_user()['team_id'] ?? 1; print json_encode(!empty($b['name']) && !empty($b['email']) ? invite_user($b['name'], $b['email'], $b['role']??'member', $team_id) : ['success'=>false,'error'=>'name and email required']); })(),
    $method === 'POST' && $action === 'remove'  => (function() use ($b) { require_role('admin'); $team_id = current_user()['team_id'] ?? 1; print json_encode(remove_team_member((int)($b['user_id']??0), $team_id)); })(),
    default => null
};
```

**Step 3: Verify manually**

Log out and log back in. Open browser DevTools → Application → Cookies. Verify the PHP session contains team_id. Also check: `GET /app/api/team.php?action=members` returns team members.

**Step 4: Commit**

```bash
git add app/api/auth.php app/api/team.php
git commit -m "fix: store team_id in session on login, remove hardcoded team_id"
```

---

### Task 2: Create utils.js — toast notifications + confirm modal

**Why:** All JS files use `alert()` for errors. Need a reusable toast + confirm system before touching other files. This is used by tasks 3–23.

**Files:**
- Create: `app/assets/js/utils.js`
- Modify: `app/assets/css/main.css`
- Modify: `app/includes/layout_top.php`

**Step 1: Create `app/assets/js/utils.js`**

```javascript
// Toast notifications
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('toast-visible'), 10);
    setTimeout(() => {
        toast.classList.remove('toast-visible');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Confirm modal
function showConfirm(message, onConfirm) {
    const modal = document.getElementById('confirm-modal');
    document.getElementById('confirm-message').textContent = message;
    modal.classList.remove('hidden');

    const yesBtn = document.getElementById('confirm-yes');
    const noBtn = document.getElementById('confirm-no');

    function cleanup() {
        modal.classList.add('hidden');
        yesBtn.replaceWith(yesBtn.cloneNode(true));
        noBtn.replaceWith(noBtn.cloneNode(true));
    }

    document.getElementById('confirm-yes').addEventListener('click', () => { cleanup(); onConfirm(); }, { once: true });
    document.getElementById('confirm-no').addEventListener('click', () => cleanup(), { once: true });
}
```

**Step 2: Add toast + confirm HTML + CSS to `app/includes/layout_top.php`**

In `layout_top.php`, add inside `<head>` after the CSS link:

```html
<script src="<?= APP_URL ?>/app/assets/js/utils.js" defer></script>
```

In `layout_top.php`, add just after `<body>` opening (before `<div class="app-layout">`):

```html
<!-- Toast container -->
<div id="toast-container" style="position:fixed;top:1rem;right:1rem;z-index:999;display:flex;flex-direction:column;gap:0.5rem;"></div>

<!-- Confirm modal -->
<div id="confirm-modal" class="modal hidden">
    <div class="modal-box" style="max-width:400px;">
        <p id="confirm-message" style="margin-bottom:1.5rem;font-size:1rem;"></p>
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button class="btn btn-secondary" id="confirm-no">Cancel</button>
            <button class="btn btn-danger" id="confirm-yes">Delete</button>
        </div>
    </div>
</div>
```

**Step 3: Add toast + btn-danger CSS to `app/assets/css/main.css`**

Append at the end of `main.css`:

```css
/* Toast notifications */
.toast {
    padding: 0.75rem 1.25rem;
    border-radius: 6px;
    font-size: 0.875rem;
    color: #fff;
    opacity: 0;
    transform: translateX(1rem);
    transition: opacity 0.3s, transform 0.3s;
    min-width: 200px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.toast-visible { opacity: 1; transform: translateX(0); }
.toast-success { background: #16a34a; }
.toast-error   { background: #dc2626; }
.toast-warning { background: #d97706; }

/* Danger button */
.btn-danger { background: #dc2626; color: #fff; border: none; }
.btn-danger:hover { background: #b91c1c; }

/* Loading spinner */
.btn-loading { opacity: 0.7; cursor: not-allowed; }
```

**Step 4: Verify manually**

Open any page, open DevTools console, run `showToast('Hello!', 'success')`. Verify toast appears and disappears. Run `showConfirm('Delete this?', () => console.log('confirmed'))`.

**Step 5: Commit**

```bash
git add app/assets/js/utils.js app/assets/css/main.css app/includes/layout_top.php
git commit -m "feat: add toast notifications and confirm modal utility"
```

---

### Task 3: Fix XSS vulnerability in Wiki

**Why:** `wiki.js` line 25 does `innerHTML = data.data.content` with raw HTML from DB — XSS vulnerability. Also sanitize on save in the API.

**Files:**
- Modify: `app/assets/js/wiki.js`
- Modify: `app/api/pages.php`
- Modify: `app/includes/layout_top.php`

**Step 1: Add DOMPurify to `app/includes/layout_top.php`**

Add in `<head>` before utils.js script tag:

```html
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
```

**Step 2: Fix `app/assets/js/wiki.js` line 25**

Change:
```javascript
document.getElementById('page-content').innerHTML = data.data.content || '';
```

To:
```javascript
document.getElementById('page-content').innerHTML = DOMPurify.sanitize(data.data.content || '');
```

**Step 3: Add server-side sanitization to `app/api/pages.php`**

Add this helper function at the top of `app/api/pages.php` (after `<?php`):

```php
function sanitize_html(string $html): string {
    $allowed = '<p><h1><h2><h3><ul><ol><li><strong><em><code><pre><a><br><blockquote>';
    return strip_tags($html, $allowed);
}
```

In `create_page()`, change the INSERT execute line:
```php
$stmt->execute([$title, $parent_id, sanitize_html($content), $user_id]);
```

In `update_page()`, change the UPDATE execute line:
```php
$pdo->prepare('UPDATE pages SET title = ?, content = ? WHERE id = ?')
    ->execute([$title, sanitize_html($content), $id]);
```

**Step 4: Verify manually**

In the wiki editor, type: `<img src=x onerror="alert('xss')">` and save. Reload page and click the same page. Verify no alert appears — the img tag should be stripped.

**Step 5: Commit**

```bash
git add app/assets/js/wiki.js app/api/pages.php app/includes/layout_top.php
git commit -m "fix: sanitize wiki HTML with DOMPurify (client) and strip_tags (server)"
```

---

### Task 4: Project switcher — fix hardcoded PROJECT_ID

**Why:** `app/pages/issues.php` line 49 and `app/pages/kanban.php` line 24 both have `const PROJECT_ID = 1;` hardcoded. Need a real project switcher.

**Files:**
- Modify: `app/includes/layout_top.php`
- Modify: `app/api/projects.php`
- Modify: `app/pages/issues.php`
- Modify: `app/pages/kanban.php`

**Step 1: Add project switcher HTML to `app/includes/layout_top.php`**

In the sidebar `<nav>`, add after `<div class="sidebar-logo">WINOJI</div>`:

```html
<div class="project-switcher">
    <select id="project-select" style="width:100%;padding:0.4rem;border:1px solid #ddd;border-radius:4px;font-size:0.875rem;margin-bottom:0.5rem;">
        <option value="">Loading projects...</option>
    </select>
    <button class="btn btn-primary" id="new-project-btn" style="width:100%;font-size:0.8rem;padding:0.3rem;">+ New Project</button>
</div>
```

**Step 2: Add project switcher JS to `app/includes/layout_top.php`**

Add inline `<script>` tag at the very bottom of `layout_top.php`, before closing `?>`:

```html
<script>
const APP_URL = '<?= APP_URL ?>';
(async function initProjectSwitcher() {
    const sel = document.getElementById('project-select');
    if (!sel) return;
    const res = await fetch(`${APP_URL}/app/api/projects.php?action=list`);
    const data = await res.json();
    const projects = data.data || [];
    sel.innerHTML = projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
    // Restore from localStorage
    const saved = localStorage.getItem('active_project_id');
    if (saved && projects.find(p => String(p.id) === saved)) {
        sel.value = saved;
    } else if (projects.length) {
        localStorage.setItem('active_project_id', projects[0].id);
    }
    sel.addEventListener('change', () => {
        localStorage.setItem('active_project_id', sel.value);
        window.location.reload();
    });
    document.getElementById('new-project-btn').addEventListener('click', async () => {
        const name = prompt('Project name:');
        if (!name) return;
        const r = await fetch(`${APP_URL}/app/api/projects.php?action=create`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ name, description: '' })
        });
        const d = await r.json();
        if (d.success) { localStorage.setItem('active_project_id', d.id); window.location.reload(); }
    });
})();
</script>
```

**Step 3: Update `app/api/projects.php` — return id on create**

Open `app/api/projects.php` and verify `create_project()` returns the new id. If not, update:

```php
function create_project(string $name, ?string $description, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO projects (name, description, created_by) VALUES (?,?,?)');
    $stmt->execute([$name, $description, $user_id]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}
```

**Step 4: Replace hardcoded PROJECT_ID in `app/pages/issues.php`**

Change line 49:
```html
<script>const APP_URL = '<?= APP_URL ?>'; const PROJECT_ID = 1;</script>
```
To:
```html
<script>const APP_URL = '<?= APP_URL ?>'; const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '1');</script>
```

**Step 5: Replace hardcoded PROJECT_ID in `app/pages/kanban.php`**

Change line 24:
```html
<script>const APP_URL = '<?= APP_URL ?>'; const PROJECT_ID = 1;</script>
```
To:
```html
<script>const APP_URL = '<?= APP_URL ?>'; const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '1');</script>
```

**Step 6: Verify manually**

1. Open Issues page. Verify it loads issues for the selected project.
2. Change project in the dropdown. Verify the page reloads and shows the correct project's issues.
3. Do the same for Kanban.

**Step 7: Commit**

```bash
git add app/includes/layout_top.php app/api/projects.php app/pages/issues.php app/pages/kanban.php
git commit -m "fix: replace hardcoded PROJECT_ID with dynamic project switcher"
```

---

### Task 5: Fix GitHub API error handling

**Why:** `github_request()` doesn't check HTTP status code for non-2xx responses (e.g. 401 invalid token). API errors go silent.

**Files:**
- Modify: `app/api/github.php`

**Step 1: Add status check to `github_request()`**

The function already captures `$status` (line 32) but `create_branch_for_issue()` only checks `$result['status'] !== 201`. The issue is that `get_repo_for_project` errors silently and `$repoData['body']['default_branch']` can be null with no error shown.

Update `create_branch_for_issue()` to check intermediate calls:

```php
function create_branch_for_issue(int $issue_id, string $branch_name, int $user_id): array {
    $pdo = get_db();
    $issue = get_issue($issue_id);
    if (!$issue) return ['success' => false, 'error' => 'Issue not found'];

    $repo = get_repo_for_project($issue['project_id']);
    if (!$repo) return ['success' => false, 'error' => 'No GitHub repo connected to this project'];

    // Use project token, fallback to user's personal token
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return ['success' => false, 'error' => 'No GitHub token available'];

    $repoData = github_request($token, "repos/{$repo['repo_full_name']}");
    if ($repoData['status'] !== 200) {
        $msg = $repoData['body']['message'] ?? 'Could not access GitHub repo';
        return ['success' => false, 'error' => "GitHub error: $msg"];
    }

    $defaultBranch = $repoData['body']['default_branch'] ?? 'main';
    $refData = github_request($token, "repos/{$repo['repo_full_name']}/git/ref/heads/$defaultBranch");
    if ($refData['status'] !== 200) {
        return ['success' => false, 'error' => 'Could not get default branch SHA'];
    }

    $sha = $refData['body']['object']['sha'] ?? null;
    if (!$sha) return ['success' => false, 'error' => 'Could not get branch SHA'];

    $result = github_request($token, "repos/{$repo['repo_full_name']}/git/refs", 'POST', [
        'ref' => "refs/heads/$branch_name",
        'sha' => $sha
    ]);

    if ($result['status'] !== 201) {
        return ['success' => false, 'error' => $result['body']['message'] ?? 'GitHub error creating branch'];
    }

    $pdo->prepare('INSERT INTO branches (issue_id, branch_name, created_by) VALUES (?,?,?)')
        ->execute([$issue_id, $branch_name, $user_id]);

    return ['success' => true, 'branch' => $branch_name];
}
```

Also add `get_user_github_token()` placeholder (implemented fully in Task 15):

```php
function get_user_github_token(int $user_id): ?string {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT github_token FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['github_token']) return null;
    return decrypt_token($row['github_token']);
}
```

**Step 2: Update `issues.js` to show real error messages instead of alert()**

In `app/assets/js/issues.js`, find the branch creation handler and replace `alert(data.error)` with toast:

```javascript
if (data.success) {
    await loadBranches(currentIssueId);
    showToast('Branch created successfully');
} else {
    showToast(data.error || 'Failed to create branch', 'error');
}
```

**Step 3: Add `github_token` column to users table**

Run in MySQL:
```sql
ALTER TABLE users ADD COLUMN github_token TEXT DEFAULT NULL;
```

Also update `db/schema.sql` to add this column to the `users` table definition.

**Step 4: Verify manually**

Try creating a branch with an invalid token. Verify a real error message appears in a toast instead of a silent failure.

**Step 5: Commit**

```bash
git add app/api/github.php app/assets/js/issues.js db/schema.sql
git commit -m "fix: improve GitHub API error handling and propagate error messages"
```

---

### Task 6: Create Profile page and API

**Files:**
- Create: `app/api/profile.php`
- Create: `app/pages/profile.php`
- Modify: `app/includes/layout_top.php`

**Step 1: Create `app/api/profile.php`**

```php
<?php
function get_profile(int $user_id): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, name, email, avatar, role FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

function update_profile(int $user_id, array $fields): array {
    $pdo = get_db();
    $allowed = ['name', 'email', 'avatar'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $fields) && $fields[$f] !== null) {
            $sets[] = "$f = ?";
            $params[] = $fields[$f];
        }
    }

    // Handle password change
    if (!empty($fields['new_password'])) {
        if (empty($fields['current_password'])) {
            return ['success' => false, 'error' => 'Current password required'];
        }
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if (!password_verify($fields['current_password'], $row['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }
        $sets[] = 'password_hash = ?';
        $params[] = password_hash($fields['new_password'], PASSWORD_DEFAULT);
    }

    // Handle GitHub token
    if (array_key_exists('github_token', $fields)) {
        if (!empty($fields['github_token'])) {
            require_once __DIR__ . '/github.php';
            $sets[] = 'github_token = ?';
            $params[] = encrypt_token($fields['github_token']);
        } else {
            $sets[] = 'github_token = ?';
            $params[] = null;
        }
    }

    if (empty($sets)) return ['success' => false, 'error' => 'Nothing to update'];
    $params[] = $user_id;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

    // Update session name if changed
    if (!empty($fields['name'])) {
        $_SESSION['user']['name'] = $fields['name'];
    }

    return ['success' => true];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $user_id = current_user()['id'];

    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => get_profile($user_id)]);
    } elseif ($method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        echo json_encode(update_profile($user_id, $b));
    }
    exit;
}
```

**Step 2: Create `app/pages/profile.php`**

```php
<?php
$page_title = 'Profile';
require __DIR__ . '/../includes/layout_top.php';
?>
<div style="max-width:600px;">
    <h2>My Profile</h2>

    <div class="card" style="margin-bottom:1.5rem;">
        <h3 style="margin-bottom:1rem;">Account Info</h3>
        <div style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.875rem;color:#6b7280;margin-bottom:0.25rem;">Name</label>
            <input type="text" id="profile-name" style="width:100%;padding:0.5rem;border:1px solid #ddd;border-radius:4px;">
        </div>
        <div style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.875rem;color:#6b7280;margin-bottom:0.25rem;">Email</label>
            <input type="email" id="profile-email" style="width:100%;padding:0.5rem;border:1px solid #ddd;border-radius:4px;">
        </div>
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.875rem;color:#6b7280;margin-bottom:0.25rem;">Avatar URL</label>
            <input type="url" id="profile-avatar" placeholder="https://..." style="width:100%;padding:0.5rem;border:1px solid #ddd;border-radius:4px;">
        </div>
        <button class="btn btn-primary" id="save-profile-btn">Save Changes</button>
    </div>

    <div class="card" style="margin-bottom:1.5rem;">
        <h3 style="margin-bottom:1rem;">Change Password</h3>
        <div style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.875rem;color:#6b7280;margin-bottom:0.25rem;">Current Password</label>
            <input type="password" id="current-password" style="width:100%;padding:0.5rem;border:1px solid #ddd;border-radius:4px;">
        </div>
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.875rem;color:#6b7280;margin-bottom:0.25rem;">New Password</label>
            <input type="password" id="new-password" style="width:100%;padding:0.5rem;border:1px solid #ddd;border-radius:4px;">
        </div>
        <button class="btn btn-primary" id="save-password-btn">Update Password</button>
    </div>

    <div class="card">
        <h3 style="margin-bottom:0.5rem;">GitHub Personal Access Token</h3>
        <p style="font-size:0.875rem;color:#6b7280;margin-bottom:0.75rem;">Used as fallback when a project has no GitHub token configured.</p>
        <input type="password" id="github-token" placeholder="ghp_..." style="width:100%;padding:0.5rem;border:1px solid #ddd;border-radius:4px;margin-bottom:0.75rem;">
        <button class="btn btn-primary" id="save-token-btn">Save Token</button>
    </div>
</div>

<script>const APP_URL = '<?= APP_URL ?>';</script>
<script>
(async function() {
    const res = await fetch(`${APP_URL}/app/api/profile.php`);
    const data = await res.json();
    if (!data.success) return;
    const p = data.data;
    document.getElementById('profile-name').value = p.name || '';
    document.getElementById('profile-email').value = p.email || '';
    document.getElementById('profile-avatar').value = p.avatar || '';
})();

document.getElementById('save-profile-btn').addEventListener('click', async () => {
    const btn = document.getElementById('save-profile-btn');
    btn.disabled = true; btn.textContent = 'Saving...';
    const res = await fetch(`${APP_URL}/app/api/profile.php`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            name: document.getElementById('profile-name').value,
            email: document.getElementById('profile-email').value,
            avatar: document.getElementById('profile-avatar').value,
        })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Save Changes';
    data.success ? showToast('Profile updated') : showToast(data.error, 'error');
});

document.getElementById('save-password-btn').addEventListener('click', async () => {
    const btn = document.getElementById('save-password-btn');
    btn.disabled = true; btn.textContent = 'Updating...';
    const res = await fetch(`${APP_URL}/app/api/profile.php`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            current_password: document.getElementById('current-password').value,
            new_password: document.getElementById('new-password').value,
        })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Update Password';
    if (data.success) {
        showToast('Password updated');
        document.getElementById('current-password').value = '';
        document.getElementById('new-password').value = '';
    } else {
        showToast(data.error, 'error');
    }
});

document.getElementById('save-token-btn').addEventListener('click', async () => {
    const btn = document.getElementById('save-token-btn');
    btn.disabled = true; btn.textContent = 'Saving...';
    const res = await fetch(`${APP_URL}/app/api/profile.php`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ github_token: document.getElementById('github-token').value })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Save Token';
    data.success ? showToast('GitHub token saved') : showToast(data.error, 'error');
    document.getElementById('github-token').value = '';
});
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
```

**Step 3: Add Profile link to sidebar in `app/includes/layout_top.php`**

In the `<ul>` nav list, add:
```html
<li><a href="<?= APP_URL ?>?page=profile">Profile</a></li>
```

**Step 4: Verify manually**

1. Go to `?page=profile`. Verify form loads with your name/email.
2. Change name, click Save. Verify toast appears and name updates.
3. Try changing password with wrong current password. Verify error toast.
4. Enter a GitHub token, save. Verify success toast.

**Step 5: Commit**

```bash
git add app/api/profile.php app/pages/profile.php app/includes/layout_top.php
git commit -m "feat: add profile page with name, password, and GitHub token management"
```

---

## PHASE 2 — CORE MISSING FEATURES

---

### Task 7: Comments API

**Files:**
- Create: `app/api/comments.php`

**Step 1: Create `app/api/comments.php`**

```php
<?php
function list_comments(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT c.*, u.name as author_name, u.avatar as author_avatar
         FROM comments c JOIN users u ON c.user_id = u.id
         WHERE c.issue_id = ? ORDER BY c.created_at ASC'
    );
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll();
}

function create_comment(int $issue_id, string $body, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO comments (issue_id, body, user_id) VALUES (?,?,?)');
    $stmt->execute([$issue_id, $body, $user_id]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function delete_comment(int $id, int $user_id, string $role): array {
    $pdo = get_db();
    // Only author or admin can delete
    if ($role !== 'admin') {
        $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_id'] !== $user_id) {
            return ['success' => false, 'error' => 'Not authorized'];
        }
    }
    $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $user = current_user();

    match(true) {
        $method === 'GET'  && $action === 'list'   => print json_encode(['success'=>true,'data'=>list_comments((int)($_GET['issue_id']??0))]),
        $method === 'POST' && $action === 'create' => print json_encode(!empty($b['issue_id']) && !empty($b['body']) ? create_comment((int)$b['issue_id'], $b['body'], $user['id']) : ['success'=>false,'error'=>'issue_id and body required']),
        $method === 'POST' && $action === 'delete' => print json_encode(delete_comment((int)($b['id']??0), $user['id'], $user['role'])),
        default => null
    };
    exit;
}
```

**Step 2: Verify the `comments` table exists**

Run in MySQL:
```sql
DESCRIBE comments;
```
Expected columns: id, issue_id, body, user_id, created_at. If missing, create:
```sql
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,
    body TEXT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

**Step 3: Test API manually**

In browser: `GET /app/api/comments.php?action=list&issue_id=1`
Expected: `{"success":true,"data":[]}`

**Step 4: Commit**

```bash
git add app/api/comments.php
git commit -m "feat: add comments API (list, create, delete)"
```

---

### Task 8: Comments UI in issue detail panel

**Files:**
- Modify: `app/pages/issues.php`
- Modify: `app/assets/js/issues.js`

**Step 1: Update comments section in `app/pages/issues.php`**

Replace the comments section (lines 29-33):

```html
<div class="comments-section">
    <strong>Comments</strong>
    <div id="comments-list" style="margin-top:0.75rem;"></div>
    <div style="margin-top:0.75rem;display:flex;gap:0.5rem;">
        <textarea id="comment-input" placeholder="Write a comment..." style="flex:1;padding:0.4rem 0.6rem;border:1px solid #ddd;border-radius:4px;height:60px;resize:vertical;"></textarea>
        <button class="btn btn-primary" id="add-comment-btn" style="align-self:flex-end;">Send</button>
    </div>
</div>
```

**Step 2: Add comment functions to `app/assets/js/issues.js`**

Add these functions after `loadBranches()`:

```javascript
async function loadComments(issueId) {
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=list&issue_id=${issueId}`);
    const data = await res.json();
    const list = document.getElementById('comments-list');
    const comments = data.data || [];
    if (!comments.length) {
        list.innerHTML = '<em style="color:#aaa;font-size:0.875rem;">No comments yet.</em>';
        return;
    }
    list.innerHTML = comments.map(c => `
        <div class="comment-item" data-id="${c.id}" style="padding:0.6rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:0.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.3rem;">
                <strong style="font-size:0.875rem;">${escapeHtml(c.author_name)}</strong>
                <span style="font-size:0.75rem;color:#aaa;">${new Date(c.created_at).toLocaleDateString()}</span>
            </div>
            <p style="margin:0;font-size:0.875rem;">${escapeHtml(c.body)}</p>
            <button onclick="deleteComment(${c.id})" style="font-size:0.75rem;color:#dc2626;background:none;border:none;cursor:pointer;margin-top:0.25rem;">Delete</button>
        </div>
    `).join('');
}

async function deleteComment(id) {
    showConfirm('Delete this comment?', async () => {
        const res = await fetch(`${APP_URL}/app/api/comments.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.success) { await loadComments(currentIssueId); showToast('Comment deleted'); }
        else showToast(data.error, 'error');
    });
}
```

In `openIssue()`, add `await loadComments(id);` after `await loadBranches(id);`.

Add comment submit handler (after the close-detail listener):

```javascript
document.getElementById('add-comment-btn').addEventListener('click', async () => {
    const body = document.getElementById('comment-input').value.trim();
    if (!body || !currentIssueId) return;
    const btn = document.getElementById('add-comment-btn');
    btn.disabled = true; btn.textContent = 'Sending...';
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentIssueId, body })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Send';
    if (data.success) {
        document.getElementById('comment-input').value = '';
        await loadComments(currentIssueId);
    } else {
        showToast(data.error, 'error');
    }
});
```

**Step 3: Verify manually**

Open an issue. Verify comments section shows "No comments yet." Type a comment and click Send. Verify comment appears. Click Delete and confirm. Verify it disappears.

**Step 4: Commit**

```bash
git add app/pages/issues.php app/assets/js/issues.js
git commit -m "feat: add comments UI to issue detail panel"
```

---

### Task 9: Labels API

**Files:**
- Create: `app/api/labels.php`

**Step 1: Verify labels tables exist**

Run in MySQL:
```sql
DESCRIBE labels;
DESCRIBE issue_labels;
```
Expected `labels`: id, project_id, name, color. Expected `issue_labels`: id, issue_id, label_id. Create if missing:
```sql
CREATE TABLE IF NOT EXISTS labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS issue_labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,
    label_id INT NOT NULL,
    UNIQUE KEY (issue_id, label_id),
    FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
);
```

**Step 2: Create `app/api/labels.php`**

```php
<?php
function list_labels(int $project_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM labels WHERE project_id = ? ORDER BY name');
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

function create_label(int $project_id, string $name, string $color): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO labels (project_id, name, color) VALUES (?,?,?)');
    $stmt->execute([$project_id, $name, $color]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function delete_label(int $id): array {
    get_db()->prepare('DELETE FROM labels WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

function get_issue_labels(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT l.* FROM labels l JOIN issue_labels il ON l.id = il.label_id WHERE il.issue_id = ?');
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll();
}

function add_label_to_issue(int $issue_id, int $label_id): array {
    try {
        get_db()->prepare('INSERT INTO issue_labels (issue_id, label_id) VALUES (?,?)')->execute([$issue_id, $label_id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Label already added'];
    }
}

function remove_label_from_issue(int $issue_id, int $label_id): array {
    get_db()->prepare('DELETE FROM issue_labels WHERE issue_id = ? AND label_id = ?')->execute([$issue_id, $label_id]);
    return ['success' => true];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    match(true) {
        $method === 'GET'  && $action === 'list'              => print json_encode(['success'=>true,'data'=>list_labels((int)($_GET['project_id']??0))]),
        $method === 'GET'  && $action === 'issue_labels'      => print json_encode(['success'=>true,'data'=>get_issue_labels((int)($_GET['issue_id']??0))]),
        $method === 'POST' && $action === 'create'            => print json_encode(!empty($b['project_id']) && !empty($b['name']) ? create_label((int)$b['project_id'], $b['name'], $b['color']??'#6366f1') : ['success'=>false,'error'=>'project_id and name required']),
        $method === 'POST' && $action === 'delete'            => print json_encode(delete_label((int)($b['id']??0))),
        $method === 'POST' && $action === 'add_to_issue'      => print json_encode(add_label_to_issue((int)($b['issue_id']??0), (int)($b['label_id']??0))),
        $method === 'POST' && $action === 'remove_from_issue' => print json_encode(remove_label_from_issue((int)($b['issue_id']??0), (int)($b['label_id']??0))),
        default => null
    };
    exit;
}
```

**Step 3: Test API manually**

`GET /app/api/labels.php?action=list&project_id=1` → `{"success":true,"data":[]}`

**Step 4: Commit**

```bash
git add app/api/labels.php
git commit -m "feat: add labels API (CRUD labels, add/remove from issues)"
```

---

### Task 10: Labels UI — chips, picker, filter

**Files:**
- Modify: `app/pages/issues.php`
- Modify: `app/assets/js/issues.js`
- Modify: `app/assets/css/main.css`

**Step 1: Add labels section to issue detail panel in `app/pages/issues.php`**

After `<div id="detail-meta"></div>`, add:

```html
<div id="labels-section" style="margin:0.75rem 0;">
    <div id="issue-labels-list" style="display:flex;flex-wrap:wrap;gap:0.3rem;margin-bottom:0.5rem;"></div>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <select id="label-picker" style="padding:0.3rem;border:1px solid #ddd;border-radius:4px;font-size:0.875rem;">
            <option value="">Add label...</option>
        </select>
        <button class="btn btn-secondary" id="add-label-btn" style="font-size:0.8rem;padding:0.3rem 0.6rem;">Add</button>
    </div>
</div>
```

**Step 2: Add label functions to `app/assets/js/issues.js`**

```javascript
async function loadIssueLabels(issueId) {
    const [labelsRes, allLabelsRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/labels.php?action=issue_labels&issue_id=${issueId}`),
        fetch(`${APP_URL}/app/api/labels.php?action=list&project_id=${PROJECT_ID}`)
    ]);
    const labelsData = await labelsRes.json();
    const allData = await allLabelsRes.json();
    const issueLabels = labelsData.data || [];
    const allLabels = allData.data || [];

    // Render chips
    const list = document.getElementById('issue-labels-list');
    list.innerHTML = issueLabels.map(l => `
        <span class="label-chip" style="background:${escapeHtml(l.color)}22;color:${escapeHtml(l.color)};border:1px solid ${escapeHtml(l.color)};" data-id="${l.id}">
            ${escapeHtml(l.name)}
            <button onclick="removeLabelFromIssue(${l.id})" style="background:none;border:none;cursor:pointer;color:inherit;margin-left:0.2rem;">×</button>
        </span>
    `).join('') || '<em style="color:#aaa;font-size:0.8rem;">No labels</em>';

    // Populate picker with labels not already on issue
    const existingIds = issueLabels.map(l => l.id);
    const picker = document.getElementById('label-picker');
    picker.innerHTML = '<option value="">Add label...</option>' +
        allLabels.filter(l => !existingIds.includes(l.id))
            .map(l => `<option value="${l.id}" style="color:${escapeHtml(l.color)}">${escapeHtml(l.name)}</option>`).join('');
}

async function removeLabelFromIssue(labelId) {
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=remove_from_issue`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentIssueId, label_id: labelId })
    });
    const data = await res.json();
    if (data.success) await loadIssueLabels(currentIssueId);
    else showToast(data.error, 'error');
}

document.getElementById('add-label-btn').addEventListener('click', async () => {
    const labelId = document.getElementById('label-picker').value;
    if (!labelId || !currentIssueId) return;
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=add_to_issue`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentIssueId, label_id: parseInt(labelId) })
    });
    const data = await res.json();
    if (data.success) await loadIssueLabels(currentIssueId);
    else showToast(data.error, 'error');
});
```

In `openIssue()`, add `await loadIssueLabels(id);` after `await loadBranches(id);`.

**Step 3: Add label-chip CSS to `app/assets/css/main.css`**

```css
.label-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 500;
}
```

**Step 4: Also show labels in issue list cards**

Update `loadIssues()` in `issues.js` to fetch labels for each issue — this would be too many requests. Instead, update `list_issues()` in `app/api/issues.php` to include labels via a subquery:

In `list_issues()`, update the SELECT:

```php
$sql = 'SELECT i.*, u.name as assignee_name,
    (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'id\', l.id, \'name\', l.name, \'color\', l.color))
     FROM issue_labels il JOIN labels l ON il.label_id = l.id WHERE il.issue_id = i.id) as labels_json
    FROM issues i LEFT JOIN users u ON i.assigned_to = u.id WHERE i.project_id = ?';
```

Then in `issues.js` `loadIssues()`, parse labels:
```javascript
const labels = issue.labels_json ? JSON.parse(issue.labels_json) : [];
const labelChips = labels.map(l => `<span class="label-chip" style="background:${escapeHtml(l.color)}22;color:${escapeHtml(l.color)};border:1px solid ${escapeHtml(l.color)};">${escapeHtml(l.name)}</span>`).join('');
```
Add `labelChips` to the issue card HTML.

**Step 5: Verify manually**

1. Create a label via: `POST /app/api/labels.php?action=create` with `{"project_id":1,"name":"Bug","color":"#dc2626"}`
2. Open an issue. Verify label picker shows "Bug". Add it. Verify chip appears.
3. Click × on chip. Verify label removed.

**Step 6: Commit**

```bash
git add app/pages/issues.php app/assets/js/issues.js app/api/issues.php app/assets/css/main.css
git commit -m "feat: add labels UI with chips, picker, and labels in issue cards"
```

---

### Task 11: Issue assignment UI + kanban avatar

**Files:**
- Modify: `app/pages/issues.php`
- Modify: `app/assets/js/issues.js`

**Step 1: Add assignee section to issue detail panel in `app/pages/issues.php`**

After `<div id="labels-section">`, add:

```html
<div style="margin:0.75rem 0;display:flex;align-items:center;gap:0.75rem;">
    <label style="font-size:0.875rem;color:#6b7280;white-space:nowrap;">Assigned to:</label>
    <select id="assignee-picker" style="padding:0.3rem;border:1px solid #ddd;border-radius:4px;font-size:0.875rem;flex:1;">
        <option value="">Unassigned</option>
    </select>
</div>
```

**Step 2: Add assignee loading to `openIssue()` in `app/assets/js/issues.js`**

Add `loadAssigneePicker(id)` call in `openIssue()`.

Add the function:

```javascript
async function loadAssigneePicker(issueId) {
    const [issueRes, membersRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/issues.php?action=get&id=${issueId}`),
        fetch(`${APP_URL}/app/api/team.php?action=members`)
    ]);
    const issueData = await issueRes.json();
    const membersData = await membersRes.json();
    const members = membersData.data || [];
    const currentAssignee = issueData.data?.assigned_to;

    const picker = document.getElementById('assignee-picker');
    picker.innerHTML = '<option value="">Unassigned</option>' +
        members.map(m => `<option value="${m.id}" ${m.id == currentAssignee ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

    picker.onchange = async () => {
        const res = await fetch(`${APP_URL}/app/api/issues.php?action=update`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: issueId, assigned_to: picker.value ? parseInt(picker.value) : null })
        });
        const data = await res.json();
        if (data.success) { showToast('Assignee updated'); loadIssues(); }
        else showToast(data.error, 'error');
    };
}
```

**Step 3: Verify manually**

Open an issue. Verify "Assigned to" dropdown shows team members. Select one. Verify issue card in list updates with their name.

**Step 4: Commit**

```bash
git add app/pages/issues.php app/assets/js/issues.js
git commit -m "feat: add assignee picker to issue detail panel"
```

---

### Task 12: Dashboard with real data

**Files:**
- Create: `app/api/dashboard.php`
- Create: `app/assets/js/dashboard.js`
- Modify: `app/pages/dashboard.php`

**Step 1: Create `app/api/dashboard.php`**

```php
<?php
function get_dashboard_stats(int $user_id, int $project_id): array {
    $pdo = get_db();

    $stmt = $pdo->prepare('SELECT status, COUNT(*) as cnt FROM issues WHERE project_id = ? GROUP BY status');
    $stmt->execute([$project_id]);
    $byStatus = [];
    foreach ($stmt->fetchAll() as $row) $byStatus[$row['status']] = (int)$row['cnt'];

    $stmt2 = $pdo->prepare('SELECT i.*, u.name as assignee_name FROM issues i LEFT JOIN users u ON i.assigned_to = u.id WHERE i.assigned_to = ? AND i.project_id = ? AND i.status != "done" ORDER BY i.created_at DESC LIMIT 10');
    $stmt2->execute([$user_id, $project_id]);
    $myIssues = $stmt2->fetchAll();

    $stmt3 = $pdo->prepare('SELECT i.id, i.title, i.status, i.created_at, u.name as creator_name FROM issues i JOIN users u ON i.created_by = u.id WHERE i.project_id = ? ORDER BY i.created_at DESC LIMIT 15');
    $stmt3->execute([$project_id]);
    $recentIssues = $stmt3->fetchAll();

    return [
        'stats'         => $byStatus,
        'my_issues'     => $myIssues,
        'recent_issues' => $recentIssues,
    ];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $user = current_user();
    $project_id = (int)($_GET['project_id'] ?? 1);
    echo json_encode(['success' => true, 'data' => get_dashboard_stats($user['id'], $project_id)]);
    exit;
}
```

**Step 2: Update `app/pages/dashboard.php`**

```php
<?php
$page_title = 'Dashboard';
require __DIR__ . '/../includes/layout_top.php';
?>
<h2>Dashboard</h2>

<div class="dashboard-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem;">
    <div class="card stat-card"><div class="stat-num" id="stat-todo">—</div><div class="stat-label">To Do</div></div>
    <div class="card stat-card"><div class="stat-num" id="stat-in_progress">—</div><div class="stat-label">In Progress</div></div>
    <div class="card stat-card"><div class="stat-num" id="stat-review">—</div><div class="stat-label">In Review</div></div>
    <div class="card stat-card"><div class="stat-num" id="stat-done">—</div><div class="stat-label">Done</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div>
        <h3 style="margin-bottom:1rem;">My Open Issues</h3>
        <div id="my-issues"><em style="color:#aaa;">Loading...</em></div>
    </div>
    <div>
        <h3 style="margin-bottom:1rem;">Recent Activity</h3>
        <div id="recent-issues"><em style="color:#aaa;">Loading...</em></div>
    </div>
</div>

<script>const APP_URL = '<?= APP_URL ?>';</script>
<script src="<?= APP_URL ?>/app/assets/js/dashboard.js"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
```

**Step 3: Create `app/assets/js/dashboard.js`**

```javascript
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function loadDashboard() {
    const projectId = parseInt(localStorage.getItem('active_project_id') || '1');
    const res = await fetch(`${APP_URL}/app/api/dashboard.php?project_id=${projectId}`);
    const data = await res.json();
    if (!data.success) return;
    const d = data.data;

    // Stats
    ['todo', 'in_progress', 'review', 'done'].forEach(s => {
        const el = document.getElementById(`stat-${s}`);
        if (el) el.textContent = d.stats[s] || 0;
    });

    // My issues
    const myEl = document.getElementById('my-issues');
    myEl.innerHTML = d.my_issues.length
        ? d.my_issues.map(i => `
            <div class="card" style="margin-bottom:0.5rem;padding:0.6rem 0.75rem;">
                <div style="font-weight:500;">#${i.id} ${escapeHtml(i.title)}</div>
                <span class="badge badge-${i.priority}" style="font-size:0.75rem;">${i.priority}</span>
                <span class="badge" style="background:#e5e7eb;font-size:0.75rem;">${i.status}</span>
            </div>`).join('')
        : '<em style="color:#aaa;">No issues assigned to you.</em>';

    // Recent activity
    const recEl = document.getElementById('recent-issues');
    recEl.innerHTML = d.recent_issues.length
        ? d.recent_issues.map(i => `
            <div style="padding:0.5rem 0;border-bottom:1px solid #f3f4f6;font-size:0.875rem;">
                <span style="color:#6b7280;">#${i.id}</span> ${escapeHtml(i.title)}
                <span style="color:#aaa;font-size:0.75rem;"> — ${escapeHtml(i.creator_name)}</span>
            </div>`).join('')
        : '<em style="color:#aaa;">No recent activity.</em>';
}

loadDashboard();
```

**Step 4: Add stat-card CSS to `app/assets/css/main.css`**

```css
.stat-card { text-align: center; padding: 1.25rem; }
.stat-num  { font-size: 2rem; font-weight: 700; color: #4f46e5; }
.stat-label { font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem; }
```

**Step 5: Verify manually**

Go to Dashboard. Verify stats show real numbers. Verify "My Open Issues" shows issues assigned to you. Verify Recent Activity shows recently created issues.

**Step 6: Commit**

```bash
git add app/api/dashboard.php app/pages/dashboard.php app/assets/js/dashboard.js app/assets/css/main.css
git commit -m "feat: implement dashboard with issue stats, my issues, and recent activity"
```

---

### Task 13: Wiki version history UI

**Files:**
- Modify: `app/api/pages.php`
- Modify: `app/pages/wiki.php`
- Modify: `app/assets/js/wiki.js`

**Step 1: Add `list_page_versions()` to `app/api/pages.php`**

Add this function before the HTTP routing block:

```php
function list_page_versions(int $page_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT pv.id, pv.saved_at, u.name as saved_by_name
         FROM page_versions pv JOIN users u ON pv.saved_by = u.id
         WHERE pv.page_id = ? ORDER BY pv.saved_at DESC LIMIT 20'
    );
    $stmt->execute([$page_id]);
    return $stmt->fetchAll();
}

function get_page_version(int $version_id): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM page_versions WHERE id = ?');
    $stmt->execute([$version_id]);
    return $stmt->fetch() ?: null;
}
```

Add to HTTP routing block:
```php
} elseif ($method === 'GET' && $action === 'versions') {
    echo json_encode(['success' => true, 'data' => list_page_versions((int)($_GET['page_id'] ?? 0))]);
} elseif ($method === 'GET' && $action === 'get_version') {
    $v = get_page_version((int)($_GET['id'] ?? 0));
    echo json_encode($v ? ['success' => true, 'data' => $v] : ['success' => false, 'error' => 'Not found']);
}
```

**Step 2: Add history panel HTML to `app/pages/wiki.php`**

After the editor toolbar buttons, add a History button:

```html
<button onclick="toggleHistory()" style="margin-left:auto;font-size:0.8rem;">📋 History</button>
```

Add a history panel after the editor-area div:

```html
<div id="history-panel" class="hidden" style="position:fixed;right:0;top:0;bottom:0;width:280px;background:#fff;border-left:1px solid #e5e7eb;overflow-y:auto;padding:1rem;z-index:100;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <strong>Version History</strong>
        <button onclick="toggleHistory()" style="background:none;border:none;font-size:1.25rem;cursor:pointer;">×</button>
    </div>
    <div id="history-list"></div>
</div>
```

**Step 3: Add history functions to `app/assets/js/wiki.js`**

```javascript
async function toggleHistory() {
    const panel = document.getElementById('history-panel');
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden') && currentPageId) {
        await loadHistory(currentPageId);
    }
}

async function loadHistory(pageId) {
    const res = await fetch(`${APP_URL}/app/api/pages.php?action=versions&page_id=${pageId}`);
    const data = await res.json();
    const list = document.getElementById('history-list');
    const versions = data.data || [];
    if (!versions.length) { list.innerHTML = '<em style="color:#aaa;">No saved versions yet.</em>'; return; }
    list.innerHTML = versions.map(v => `
        <div style="padding:0.6rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:0.5rem;font-size:0.875rem;">
            <div style="color:#6b7280;">${new Date(v.saved_at).toLocaleString()}</div>
            <div>by ${escapeHtml(v.saved_by_name)}</div>
            <button onclick="restoreVersion(${v.id})" style="margin-top:0.3rem;font-size:0.75rem;color:#4f46e5;background:none;border:none;cursor:pointer;">Restore</button>
        </div>
    `).join('');
}

async function restoreVersion(versionId) {
    const res = await fetch(`${APP_URL}/app/api/pages.php?action=get_version&id=${versionId}`);
    const data = await res.json();
    if (!data.success) return showToast('Could not load version', 'error');
    document.getElementById('page-content').innerHTML = DOMPurify.sanitize(data.data.content || '');
    showToast('Version restored — save to keep changes', 'warning');
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
```

**Step 4: Verify manually**

Edit a wiki page and save it. Then open History panel. Verify versions list shows up. Click Restore on a version. Verify the editor loads the old content.

**Step 5: Commit**

```bash
git add app/api/pages.php app/pages/wiki.php app/assets/js/wiki.js
git commit -m "feat: add wiki version history panel with restore"
```

---

## PHASE 3 — GITHUB COMPLETE INTEGRATION

---

### Task 14: GitHub token per user (profile fallback)

**Why:** Task 5 added `get_user_github_token()` as a placeholder. Task 6 added the github_token column and the profile UI. Now wire them together.

**Files:**
- Modify: `app/api/github.php`

**Step 1: Verify `get_user_github_token()` is in `github.php`**

The function was added in Task 5. It should already be in `app/api/github.php`. If not, add:

```php
function get_user_github_token(int $user_id): ?string {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT github_token FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['github_token']) return null;
    return decrypt_token($row['github_token']);
}
```

**Step 2: Also apply token fallback to `get_issue_branches()` calls and PR listing**

The fallback logic in `create_branch_for_issue()` already uses `get_user_github_token()`. For new GitHub functions in Tasks 16-18, always use the same pattern:

```php
$token = $repo['access_token'] ?: get_user_github_token($user_id);
if (!$token) return ['success' => false, 'error' => 'No GitHub token available. Set one in your Profile.'];
```

**Step 3: Commit**

```bash
git add app/api/github.php
git commit -m "feat: add user personal GitHub token fallback for all GitHub operations"
```

---

### Task 15: Add pr_url column and PR listing per issue

**Files:**
- Modify: `db/schema.sql`
- Modify: `app/api/github.php`
- Modify: `app/pages/issues.php`
- Modify: `app/assets/js/issues.js`

**Step 1: Migrate database**

Run in MySQL:
```sql
ALTER TABLE branches ADD COLUMN pr_number INT DEFAULT NULL;
ALTER TABLE branches ADD COLUMN pr_url VARCHAR(500) DEFAULT NULL;
```

Update `db/schema.sql` branches table definition to include these columns.

**Step 2: Add `list_issue_prs()` to `app/api/github.php`**

```php
function list_issue_prs(int $issue_id, int $user_id): array {
    $pdo = get_db();

    // Get branches for this issue
    $stmt = $pdo->prepare('SELECT b.*, i.project_id FROM branches b JOIN issues i ON b.issue_id = i.id WHERE b.issue_id = ?');
    $stmt->execute([$issue_id]);
    $branches = $stmt->fetchAll();
    if (!$branches) return [];

    $project_id = $branches[0]['project_id'];
    $repo = get_repo_for_project($project_id);
    if (!$repo) return [];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return [];

    $prs = [];
    foreach ($branches as $branch) {
        // Search for PRs with this branch as head
        $result = github_request($token, "repos/{$repo['repo_full_name']}/pulls?head=" . urlencode($repo['repo_full_name'] . ':' . $branch['branch_name']) . "&state=all");
        if ($result['status'] === 200 && !empty($result['body'])) {
            foreach ($result['body'] as $pr) {
                $prs[] = [
                    'number'     => $pr['number'],
                    'title'      => $pr['title'],
                    'state'      => $pr['state'],
                    'merged'     => !empty($pr['merged_at']),
                    'url'        => $pr['html_url'],
                    'author'     => $pr['user']['login'] ?? '',
                    'created_at' => $pr['created_at'],
                    'branch'     => $branch['branch_name'],
                ];
            }
        }
    }
    return $prs;
}
```

Add to HTTP routing in `github.php`:
```php
$method === 'GET' && $action === 'prs' => print json_encode(['success'=>true,'data'=>list_issue_prs((int)($_GET['issue_id']??0), current_user()['id'])]),
```

**Step 3: Add PRs section to issue detail panel in `app/pages/issues.php`**

After the github-section div, add:

```html
<hr style="margin:1rem 0;">
<div class="prs-section">
    <strong>Pull Requests</strong>
    <div id="prs-list" style="margin-top:0.5rem;"></div>
</div>
```

**Step 4: Add `loadPRs()` to `app/assets/js/issues.js`**

```javascript
async function loadPRs(issueId) {
    const res = await fetch(`${APP_URL}/app/api/github.php?action=prs&issue_id=${issueId}`);
    const data = await res.json();
    const list = document.getElementById('prs-list');
    const prs = data.data || [];
    if (!prs.length) { list.innerHTML = '<em style="color:#aaa;font-size:0.875rem;">No pull requests yet.</em>'; return; }
    list.innerHTML = prs.map(pr => {
        const stateColor = pr.merged ? '#7c3aed' : pr.state === 'open' ? '#16a34a' : '#dc2626';
        const stateLabel = pr.merged ? 'merged' : pr.state;
        return `<div class="branch-item" style="margin-bottom:0.4rem;">
            <span style="color:${stateColor};font-weight:600;">[${stateLabel}]</span>
            <a href="${escapeHtml(pr.url)}" target="_blank" style="color:#4f46e5;">#${pr.number} ${escapeHtml(pr.title)}</a>
            <span style="color:#aaa;font-size:0.8rem;"> by ${escapeHtml(pr.author)}</span>
        </div>`;
    }).join('');
}
```

Call `await loadPRs(id)` in `openIssue()`.

**Step 5: Verify manually**

Open an issue that has a branch created from the app. Verify PRs section shows associated PRs from GitHub.

**Step 6: Commit**

```bash
git add db/schema.sql app/api/github.php app/pages/issues.php app/assets/js/issues.js
git commit -m "feat: add PR listing per issue fetched from GitHub API"
```

---

### Task 16: PR status sync → issue status

**Files:**
- Modify: `app/api/github.php`
- Modify: `app/assets/js/issues.js`

**Step 1: Add `sync_pr_status_to_issue()` to `app/api/github.php`**

```php
function sync_pr_status_to_issue(int $issue_id, int $user_id): array {
    $prs = list_issue_prs($issue_id, $user_id);
    if (empty($prs)) return ['synced' => false, 'reason' => 'no_prs'];

    foreach ($prs as $pr) {
        if ($pr['merged']) {
            update_issue($issue_id, ['status' => 'done']);
            return ['synced' => true, 'new_status' => 'done', 'reason' => 'pr_merged'];
        }
        if ($pr['state'] === 'closed' && !$pr['merged']) {
            update_issue($issue_id, ['status' => 'todo']);
            return ['synced' => true, 'new_status' => 'todo', 'reason' => 'pr_closed'];
        }
        if ($pr['state'] === 'open') {
            update_issue($issue_id, ['status' => 'review']);
            return ['synced' => true, 'new_status' => 'review', 'reason' => 'pr_open'];
        }
    }
    return ['synced' => false, 'reason' => 'no_change'];
}
```

Add to HTTP routing:
```php
$method === 'POST' && $action === 'sync_pr_status' => print json_encode(array_merge(sync_pr_status_to_issue((int)($b['issue_id']??0), current_user()['id']), ['success'=>true])),
```

Note: `update_issue()` is in `issues.php`. Add require to github.php's routing block if not already present. The routing block already has `require_once __DIR__ . '/issues.php';` at line 99.

**Step 2: Auto-sync when opening issue detail in `app/assets/js/issues.js`**

In `openIssue()`, after `await loadPRs(id)`, add:

```javascript
// Auto-sync PR status
fetch(`${APP_URL}/app/api/github.php?action=sync_pr_status`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ issue_id: id })
}).then(r => r.json()).then(data => {
    if (data.synced) {
        showToast(`Issue status updated to "${data.new_status}" based on PR`, 'warning');
        loadIssues(); // refresh list
    }
});
```

**Step 3: Verify manually**

Create a PR on GitHub for a branch created from an issue. Open that issue in the app. Verify the status updates to "review". Merge the PR on GitHub. Re-open the issue in the app. Verify status updates to "done".

**Step 4: Commit**

```bash
git add app/api/github.php app/assets/js/issues.js
git commit -m "feat: auto-sync issue status from PR state on issue open"
```

---

### Task 17: Commits per branch (expandable)

**Files:**
- Modify: `app/api/github.php`
- Modify: `app/assets/js/issues.js`

**Step 1: Add `get_branch_commits()` to `app/api/github.php`**

```php
function get_branch_commits(int $issue_id, string $branch_name, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT i.project_id FROM issues i JOIN branches b ON b.issue_id = i.id WHERE b.issue_id = ? LIMIT 1');
    $stmt->execute([$issue_id]);
    $row = $stmt->fetch();
    if (!$row) return [];

    $repo = get_repo_for_project($row['project_id']);
    if (!$repo) return [];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return [];

    $result = github_request($token, "repos/{$repo['repo_full_name']}/commits?sha=" . urlencode($branch_name) . "&per_page=10");
    if ($result['status'] !== 200) return [];

    return array_map(fn($c) => [
        'sha'     => substr($c['sha'], 0, 7),
        'message' => explode("\n", $c['commit']['message'])[0], // first line only
        'author'  => $c['commit']['author']['name'] ?? '',
        'date'    => $c['commit']['author']['date'] ?? '',
        'url'     => $c['html_url'] ?? '',
    ], $result['body'] ?? []);
}
```

Add to HTTP routing:
```php
$method === 'GET' && $action === 'commits' => print json_encode(['success'=>true,'data'=>get_branch_commits((int)($_GET['issue_id']??0), $_GET['branch']??'', current_user()['id'])]),
```

**Step 2: Update `loadBranches()` in `app/assets/js/issues.js` to be expandable**

Replace the current `loadBranches()`:

```javascript
async function loadBranches(id) {
    const res = await fetch(`${APP_URL}/app/api/github.php?action=branches&issue_id=${id}`);
    const data = await res.json();
    const list = document.getElementById('branch-list');
    const branches = data.data || [];
    if (!branches.length) { list.innerHTML = '<em style="color:#aaa">No branches yet</em>'; return; }
    list.innerHTML = branches.map(b => `
        <div class="branch-item" style="margin-bottom:0.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span>🌿 ${escapeHtml(b.branch_name)} <span style="color:#aaa;font-size:0.8rem">by ${escapeHtml(b.creator_name)}</span></span>
                <button onclick="toggleCommits(${id}, '${escapeHtml(b.branch_name)}', this)" style="font-size:0.75rem;color:#4f46e5;background:none;border:none;cursor:pointer;">▶ Commits</button>
            </div>
            <div class="commits-panel" style="display:none;margin-top:0.4rem;padding-left:0.75rem;border-left:2px solid #e5e7eb;"></div>
        </div>
    `).join('');
}

async function toggleCommits(issueId, branchName, btn) {
    const panel = btn.closest('.branch-item').querySelector('.commits-panel');
    if (panel.style.display === 'block') { panel.style.display = 'none'; btn.textContent = '▶ Commits'; return; }
    panel.style.display = 'block';
    btn.textContent = '▼ Commits';
    panel.innerHTML = '<em style="color:#aaa;font-size:0.8rem;">Loading...</em>';
    const res = await fetch(`${APP_URL}/app/api/github.php?action=commits&issue_id=${issueId}&branch=${encodeURIComponent(branchName)}`);
    const data = await res.json();
    const commits = data.data || [];
    if (!commits.length) { panel.innerHTML = '<em style="color:#aaa;font-size:0.8rem;">No commits</em>'; return; }
    panel.innerHTML = commits.map(c => `
        <div style="font-size:0.8rem;padding:0.2rem 0;border-bottom:1px solid #f3f4f6;">
            <a href="${escapeHtml(c.url)}" target="_blank" style="color:#4f46e5;font-family:monospace;">${escapeHtml(c.sha)}</a>
            ${escapeHtml(c.message)}
            <span style="color:#aaa;"> — ${escapeHtml(c.author)}</span>
        </div>
    `).join('');
}
```

**Step 3: Verify manually**

Open an issue with a branch. Click "▶ Commits". Verify commits list loads from GitHub. Click again to collapse.

**Step 4: Commit**

```bash
git add app/api/github.php app/assets/js/issues.js
git commit -m "feat: add expandable commits per branch in issue detail panel"
```

---

## PHASE 4 — UX POLISH

---

### Task 18: Loading states across all pages

**Files:**
- Modify: `app/assets/js/issues.js`
- Modify: `app/assets/js/kanban.js`
- Modify: `app/assets/js/wiki.js`
- Modify: `app/assets/css/main.css`

**Step 1: Add skeleton loader CSS to `app/assets/css/main.css`**

```css
/* Skeleton loader */
.skeleton {
    background: linear-gradient(90deg, #f3f4f6 25%, #e5e7eb 50%, #f3f4f6 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.4s infinite;
    border-radius: 4px;
    height: 1em;
    display: block;
}
@keyframes skeleton-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.skeleton-card { height: 60px; margin-bottom: 0.5rem; border-radius: 8px; }
```

**Step 2: Add skeleton to `loadIssues()` in `issues.js`**

At the start of `loadIssues()`:
```javascript
list.innerHTML = '<span class="skeleton skeleton-card"></span><span class="skeleton skeleton-card"></span><span class="skeleton skeleton-card"></span>';
```

**Step 3: Add skeleton to `loadIssues()` in `kanban.js`**

At the start of `loadIssues()`, show a spinner in the board:
```javascript
document.getElementById('kanban-board').innerHTML = '<div style="padding:2rem;color:#aaa;">Loading board...</div>';
```

**Step 4: Disable create buttons while submitting in `issues.js` and `kanban.js`**

In every click handler that does an API POST, wrap with:
```javascript
btn.disabled = true; btn.classList.add('btn-loading');
// ... await fetch ...
btn.disabled = false; btn.classList.remove('btn-loading');
```

**Step 5: Commit**

```bash
git add app/assets/js/issues.js app/assets/js/kanban.js app/assets/js/wiki.js app/assets/css/main.css
git commit -m "feat: add skeleton loaders and button loading states"
```

---

### Task 19: Confirmation dialogs for destructive actions

**Files:**
- Modify: `app/assets/js/issues.js`
- Modify: `app/assets/js/wiki.js`

**Why:** Currently deleting issues and wiki pages happens with no confirmation. Use `showConfirm()` from utils.js (Task 2).

**Step 1: Issues.js — add delete issue button**

In `openIssue()`, after setting `detail-title`, add a delete button to `detail-meta`:

```javascript
document.getElementById('detail-meta').innerHTML = `
    <button onclick="deleteCurrentIssue()" style="color:#dc2626;background:none;border:none;cursor:pointer;font-size:0.875rem;">🗑 Delete Issue</button>
`;
```

Add `deleteCurrentIssue()` function:

```javascript
async function deleteCurrentIssue() {
    showConfirm('Delete this issue? This cannot be undone.', async () => {
        const res = await fetch(`${APP_URL}/app/api/issues.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: currentIssueId })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('issue-detail').classList.add('hidden');
            showToast('Issue deleted');
            loadIssues();
        } else {
            showToast(data.error || 'Failed to delete', 'error');
        }
    });
}
```

**Step 2: Wiki.js — confirm before deleting page**

Add delete button to wiki sidebar. In `loadPagesList()`, add delete button to each `<li>`:

```javascript
const del = document.createElement('button');
del.textContent = '×';
del.style.cssText = 'float:right;background:none;border:none;color:#dc2626;cursor:pointer;';
del.addEventListener('click', (e) => {
    e.stopPropagation();
    showConfirm(`Delete page "${p.title}"?`, async () => {
        const r = await fetch(`${APP_URL}/app/api/pages.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: p.id })
        });
        const d = await r.json();
        if (d.success) { if (currentPageId === p.id) { currentPageId = null; document.getElementById('editor-area').classList.add('hidden'); document.getElementById('editor-placeholder').classList.remove('hidden'); } showToast('Page deleted'); loadPagesList(); }
        else showToast(d.error, 'error');
    });
});
li.appendChild(del);
```

**Step 3: Verify manually**

Click delete on a wiki page. Verify confirm modal appears. Click Cancel. Verify page not deleted. Click Delete. Verify page removed and editor resets.

**Step 4: Commit**

```bash
git add app/assets/js/issues.js app/assets/js/wiki.js
git commit -m "feat: add confirmation dialogs for delete issue and delete page"
```

---

### Task 20: Empty states

**Files:**
- Modify: `app/assets/js/issues.js`
- Modify: `app/assets/js/kanban.js`
- Modify: `app/assets/js/wiki.js`

**Step 1: Empty state in `issues.js` `loadIssues()`**

After rendering issues, if none found:

```javascript
if (!(data.data || []).length) {
    list.innerHTML = `<div style="text-align:center;padding:3rem;color:#aaa;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
        <div style="font-size:1rem;margin-bottom:1rem;">No issues yet</div>
        <button class="btn btn-primary" onclick="document.getElementById('new-issue-btn').click()">Create First Issue</button>
    </div>`;
    return;
}
```

**Step 2: Empty state in `kanban.js` `renderBoard()`**

For each column that has no cards:

```javascript
if (!issues.filter(i => i.status === col).length) {
    colEl.innerHTML += '<div style="color:#aaa;font-size:0.875rem;padding:0.75rem;text-align:center;">No issues</div>';
}
```

**Step 3: Empty state in `wiki.js` `loadPagesList()`**

If no pages:

```javascript
if (!(data.data || []).length) {
    ul.innerHTML = '<li style="padding:0.75rem;color:#aaa;font-size:0.875rem;">No pages yet. Create one!</li>';
}
```

**Step 4: Commit**

```bash
git add app/assets/js/issues.js app/assets/js/kanban.js app/assets/js/wiki.js
git commit -m "feat: add empty states with action prompts"
```

---

### Task 21: Pagination in issues

**Files:**
- Modify: `app/api/issues.php`
- Modify: `app/pages/issues.php`
- Modify: `app/assets/js/issues.js`

**Step 1: Add pagination to `list_issues()` in `app/api/issues.php`**

Update function signature and body:

```php
function list_issues(int $project_id, array $filters = [], int $page = 1, int $per_page = 25): array {
    $pdo = get_db();
    $sql = 'SELECT i.*, u.name as assignee_name FROM issues i LEFT JOIN users u ON i.assigned_to = u.id WHERE i.project_id = ?';
    $params = [$project_id];
    if (!empty($filters['status']))      { $sql .= ' AND i.status = ?';      $params[] = $filters['status']; }
    if (!empty($filters['priority']))    { $sql .= ' AND i.priority = ?';    $params[] = $filters['priority']; }
    if (!empty($filters['assigned_to'])) { $sql .= ' AND i.assigned_to = ?'; $params[] = $filters['assigned_to']; }

    // Count total
    $countStmt = $pdo->prepare(str_replace('SELECT i.*, u.name as assignee_name', 'SELECT COUNT(*)', $sql));
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $per_page;
    $sql .= ' ORDER BY i.created_at DESC LIMIT ? OFFSET ?';
    $params[] = $per_page; $params[] = $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $per_page];
}
```

Update HTTP routing to pass page param:
```php
$method === 'GET' && $action === 'list' => print json_encode(['success'=>true,...list_issues((int)($_GET['project_id']??0), $_GET, (int)($_GET['page']??1), 25)]),
```

**Step 2: Add pagination controls to `app/pages/issues.php`**

After `<div id="issue-list"></div>`, add:

```html
<div id="pagination" style="display:flex;gap:0.5rem;align-items:center;justify-content:center;margin-top:1rem;"></div>
```

**Step 3: Update `loadIssues()` in `app/assets/js/issues.js` to handle pagination**

```javascript
let currentPage = 1;

async function loadIssues(page = 1) {
    currentPage = page;
    const list = document.getElementById('issue-list');
    list.innerHTML = '<span class="skeleton skeleton-card"></span><span class="skeleton skeleton-card"></span><span class="skeleton skeleton-card"></span>';
    const res = await fetch(`${APP_URL}/app/api/issues.php?action=list&project_id=${PROJECT_ID}&page=${page}`);
    const data = await res.json();
    list.innerHTML = '';
    const items = data.items || [];
    const total = data.total || 0;

    if (!items.length && page === 1) {
        list.innerHTML = `<div style="text-align:center;padding:3rem;color:#aaa;"><div style="font-size:2rem;margin-bottom:0.5rem;">📋</div><div style="margin-bottom:1rem;">No issues yet</div><button class="btn btn-primary" onclick="document.getElementById('new-issue-btn').click()">Create First Issue</button></div>`;
        return;
    }

    items.forEach(issue => {
        const el = document.createElement('div');
        el.className = 'card issue-row';
        el.style.cssText = 'cursor:pointer;display:flex;justify-content:space-between;align-items:center;';
        el.innerHTML = `<div><strong>#${escapeHtml(String(issue.id))}</strong> ${escapeHtml(issue.title)}</div>
            <div style="display:flex;gap:0.5rem;font-size:0.8rem;">
                <span class="badge badge-${issue.priority}">${issue.priority}</span>
                <span class="badge" style="background:#e5e7eb">${issue.status}</span>
            </div>`;
        el.addEventListener('click', () => openIssue(issue.id, issue.title, issue.description));
        list.appendChild(el);
    });

    // Pagination controls
    const pag = document.getElementById('pagination');
    const totalPages = Math.ceil(total / 25);
    pag.innerHTML = '';
    if (totalPages > 1) {
        pag.innerHTML = `
            <button class="btn btn-secondary" onclick="loadIssues(${page-1})" ${page===1?'disabled':''}>← Prev</button>
            <span style="font-size:0.875rem;color:#6b7280;">Page ${page} of ${totalPages} (${total} total)</span>
            <button class="btn btn-secondary" onclick="loadIssues(${page+1})" ${page===totalPages?'disabled':''}>Next →</button>
        `;
    }
}
```

**Step 4: Verify manually**

With fewer than 25 issues: no pagination appears. Create 26+ issues (use seed data) and verify pagination appears.

**Step 5: Commit**

```bash
git add app/api/issues.php app/pages/issues.php app/assets/js/issues.js
git commit -m "feat: add pagination to issues list (25 per page)"
```

---

### Task 22: Responsive CSS and mobile hamburger menu

**Files:**
- Modify: `app/assets/css/main.css`
- Modify: `app/includes/layout_top.php`
- Modify: `app/includes/layout_bottom.php`

**Step 1: Add hamburger button HTML to `app/includes/layout_top.php`**

In the `<nav class="sidebar">`, add as very first child:
```html
<button id="sidebar-toggle" style="display:none;position:fixed;top:1rem;left:1rem;z-index:200;background:#4f46e5;color:#fff;border:none;border-radius:6px;padding:0.4rem 0.6rem;font-size:1.25rem;cursor:pointer;">☰</button>
```

**Step 2: Add responsive CSS to `app/assets/css/main.css`**

```css
/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    #sidebar-toggle { display: block !important; }

    .sidebar {
        position: fixed;
        left: -260px;
        top: 0; bottom: 0;
        z-index: 150;
        transition: left 0.25s ease;
        box-shadow: 2px 0 12px rgba(0,0,0,0.15);
    }
    .sidebar.open { left: 0; }

    .main-content {
        margin-left: 0 !important;
        padding: 1rem;
        padding-top: 3.5rem; /* space for hamburger */
    }

    /* Kanban horizontal scroll */
    .kanban-board { flex-direction: row; overflow-x: auto; gap: 0.75rem; }
    .kanban-col   { min-width: 260px; }

    /* Issue detail panel full screen on mobile */
    .issue-detail {
        left: 0 !important;
        width: 100% !important;
    }

    /* Dashboard grid single column */
    .dashboard-stats { grid-template-columns: repeat(2, 1fr) !important; }
}

@media (max-width: 480px) {
    .dashboard-stats { grid-template-columns: 1fr 1fr; }
}
```

**Step 3: Add hamburger toggle JS to `app/includes/layout_bottom.php`**

Add before closing `</body>`:

```html
<script>
const toggle = document.getElementById('sidebar-toggle');
const sidebar = document.querySelector('.sidebar');
if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    // Close sidebar when clicking outside
    document.addEventListener('click', e => {
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    });
}
</script>
```

**Step 4: Verify manually**

Resize browser to 600px wide. Verify: hamburger button appears, sidebar hides. Click hamburger. Verify sidebar slides in. Click outside. Verify sidebar closes. Check kanban scrolls horizontally. Check issue detail panel is full-width.

**Step 5: Commit**

```bash
git add app/assets/css/main.css app/includes/layout_top.php app/includes/layout_bottom.php
git commit -m "feat: add responsive layout with hamburger menu for mobile"
```

---

## FINAL VERIFICATION

After all 22 tasks are complete, do a full end-to-end check:

1. **Auth:** Login, logout, verify session persists
2. **Projects:** Create a new project, switch to it, verify issues/kanban show that project's data
3. **Issues:** Create issue, add label, assign user, add comment, create branch, verify PR appears, merge PR on GitHub, reopen issue and verify status changed to done
4. **Kanban:** Drag cards between columns, verify status updates in issues list
5. **Wiki:** Create page, edit, verify history shows old versions, restore a version
6. **Team:** Invite member, verify temp password shown, verify member appears in list
7. **Profile:** Update name, change password, save GitHub token
8. **Dashboard:** Verify stats update when issues change
9. **Mobile:** Test on 375px viewport — hamburger, kanban scroll, issue detail fullscreen
10. **Security:** Try XSS payload in wiki. Verify it's stripped. Try accessing API without auth. Verify 401.

```bash
git tag v1.0.0
git push origin master --tags
```
