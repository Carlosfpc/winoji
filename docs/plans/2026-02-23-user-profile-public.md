# Public User Profile — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a read-only public profile page (`?page=user_profile&id=X`) accessible only to members of the same team, showing avatar/name/email/role, assigned issues in the active project, and recent activity.

**Architecture:** New API endpoint `app/api/users.php` enforces same-team access and returns user + issues + activity. New page `app/pages/user_profile.php` fetches the API and renders 3 sections. The router (`public/index.php`) gets one new entry. Wiki `@person` mentions are updated to link to the new profile page instead of the team page.

**Tech Stack:** PHP 8.x · MySQL 8.x · Vanilla JS · No test framework — verification via `php -l` (syntax check) and `node --check` (JS syntax check).

---

### Task 1: API endpoint `app/api/users.php`

**Files:**
- Create: `app/api/users.php`

---

**Step 1: Create the file with the complete implementation**

Create `app/api/users.php` with this exact content:

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

require_auth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action !== 'profile') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$me      = current_user();
$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil no encontrado']);
    exit;
}

// Own profile → tell frontend to redirect
if ($user_id === (int)$me['id']) {
    echo json_encode(['success' => false, 'redirect' => 'profile']);
    exit;
}

$pdo = get_db();

// Verify target user belongs to same team
$stmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email, u.avatar, u.role
     FROM users u
     JOIN team_members tm ON u.id = tm.user_id
     WHERE u.id = ? AND tm.team_id = ?'
);
$stmt->execute([$user_id, (int)$me['team_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil no encontrado']);
    exit;
}

$project_id = (int)($_GET['project_id'] ?? 0);

// Last 20 issues assigned to this user in the active project
$issues = [];
if ($project_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT i.id, i.title, i.status, i.priority, t.name AS type_name, t.color AS type_color
         FROM issues i
         LEFT JOIN issue_types t ON i.type_id = t.id
         WHERE i.assigned_to = ? AND i.project_id = ?
         ORDER BY i.updated_at DESC
         LIMIT 20'
    );
    $stmt->execute([$user_id, $project_id]);
    $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Last 20 activity log entries for this user in the active project
$activity = [];
if ($project_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT action, entity_type, entity_id, entity_title, created_at
         FROM activity_log
         WHERE user_id = ? AND project_id = ?
         ORDER BY created_at DESC
         LIMIT 20'
    );
    $stmt->execute([$user_id, $project_id]);
    $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'success' => true,
    'data'    => [
        'user'     => $user,
        'issues'   => $issues,
        'activity' => $activity,
    ]
]);
```

---

**Step 2: Verify PHP syntax**

```bash
php -l /c/Users/carlo/proyects/claude-skills/app/api/users.php
```

Expected: `No syntax errors detected in /c/Users/carlo/proyects/claude-skills/app/api/users.php`

---

**Step 3: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add app/api/users.php
git commit -m "feat: add users API with profile endpoint (same-team access control)"
```

---

### Task 2: Page `app/pages/user_profile.php`

**Files:**
- Create: `app/pages/user_profile.php`

---

**Step 1: Create the file with the complete implementation**

Create `app/pages/user_profile.php` with this exact content:

```php
<?php
$page_title = 'Perfil de usuario';
require __DIR__ . '/../includes/layout_top.php';
?>
<div id="profile-loading" style="padding:3rem;text-align:center;color:#9ca3af;">Cargando...</div>
<div id="profile-error"   style="display:none;padding:3rem;text-align:center;color:var(--text-secondary);font-size:0.95rem;">Perfil no encontrado</div>
<div id="profile-content" style="display:none;max-width:760px;">

    <!-- User header card -->
    <div class="card" style="display:flex;align-items:center;gap:1.25rem;padding:1.25rem;margin-bottom:1.25rem;">
        <div id="profile-avatar"
             style="width:64px;height:64px;border-radius:50%;background:#4f46e5;
                    display:flex;align-items:center;justify-content:center;
                    color:#fff;font-size:1.75rem;font-weight:700;overflow:hidden;flex-shrink:0;">
        </div>
        <div>
            <div id="profile-name"  style="font-weight:700;font-size:1.1rem;margin-bottom:0.25rem;"></div>
            <div id="profile-email" style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:0.4rem;"></div>
            <span id="profile-role" class="badge" style="background:#e5e7eb;color:#374151;font-size:0.8rem;"></span>
        </div>
    </div>

    <!-- Assigned issues -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);font-weight:600;font-size:0.95rem;">
            Issues asignadas
        </div>
        <div id="profile-issues"></div>
    </div>

    <!-- Recent activity -->
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);font-weight:600;font-size:0.95rem;">
            Actividad reciente
        </div>
        <div id="profile-activity"></div>
    </div>
</div>

<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
const params     = new URLSearchParams(window.location.search);
const USER_ID    = parseInt(params.get('id') || '0');

const STATUS_LABELS = {
    todo: 'Todo', in_progress: 'En progreso', review: 'En revisión', done: 'Hecho'
};
const ACTION_LABELS = {
    issue_created:  'creó la issue',
    issue_updated:  'actualizó la issue',
    issue_deleted:  'eliminó la issue',
    comment_added:  'comentó en',
    page_created:   'creó la página',
    page_updated:   'editó la página'
};

function escapeHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function renderAvatar(user) {
    const el = document.getElementById('profile-avatar');
    if (user.avatar) {
        el.innerHTML = `<img src="${escapeHtml(user.avatar)}" style="width:100%;height:100%;object-fit:cover;" alt="">`;
    } else {
        el.textContent = (user.name || '?')[0].toUpperCase();
    }
}

function renderIssues(issues) {
    const el = document.getElementById('profile-issues');
    if (!issues.length) {
        el.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Sin issues asignadas en este proyecto</div>';
        return;
    }
    el.innerHTML = issues.map(i => {
        const typeColor   = i.type_color || '#6b7280';
        const typeName    = i.type_name  || 'Issue';
        const statusLabel = STATUS_LABELS[i.status] || i.status;
        return `<a href="${APP_URL}?page=issues&open_issue=${i.id}"
                   style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1.25rem;
                          border-bottom:1px solid var(--border);text-decoration:none;color:var(--text-primary);">
            <span style="background:${escapeHtml(typeColor)};color:#fff;font-size:0.7rem;
                         padding:0.15rem 0.45rem;border-radius:4px;white-space:nowrap;">
                ${escapeHtml(typeName)}
            </span>
            <span style="flex:1;font-size:0.875rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                ${escapeHtml(i.title)}
            </span>
            <span style="font-size:0.8rem;color:var(--text-secondary);white-space:nowrap;">
                ${escapeHtml(statusLabel)}
            </span>
        </a>`;
    }).join('');
}

function renderActivity(activity) {
    const el = document.getElementById('profile-activity');
    if (!activity.length) {
        el.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Sin actividad reciente en este proyecto</div>';
        return;
    }
    el.innerHTML = activity.map(a => {
        const label   = ACTION_LABELS[a.action] || a.action.replace(/_/g, ' ');
        const timeStr = typeof timeAgo === 'function'
            ? timeAgo(a.created_at)
            : new Date(a.created_at).toLocaleString('es');
        return `<div style="display:flex;gap:0.75rem;align-items:flex-start;
                            padding:0.75rem 1.25rem;border-bottom:1px solid var(--border);">
            <span style="font-size:0.75rem;color:var(--text-secondary);white-space:nowrap;padding-top:0.1rem;">
                ${timeStr}
            </span>
            <span style="font-size:0.875rem;">
                ${escapeHtml(label)} <em>${escapeHtml(a.entity_title || '#' + a.entity_id)}</em>
            </span>
        </div>`;
    }).join('');
}

async function loadProfile() {
    if (!USER_ID) {
        document.getElementById('profile-loading').style.display = 'none';
        document.getElementById('profile-error').style.display   = 'block';
        return;
    }

    const res  = await fetch(`${APP_URL}/app/api/users.php?action=profile&id=${USER_ID}&project_id=${PROJECT_ID}`);
    const data = await res.json();

    document.getElementById('profile-loading').style.display = 'none';

    if (!data.success) {
        if (data.redirect === 'profile') {
            window.location.href = `${APP_URL}?page=profile`;
            return;
        }
        document.getElementById('profile-error').style.display = 'block';
        return;
    }

    const { user, issues, activity } = data.data;
    renderAvatar(user);
    document.getElementById('profile-name').textContent  = user.name;
    document.getElementById('profile-email').textContent = user.email;
    document.getElementById('profile-role').textContent  = user.role;
    renderIssues(issues);
    renderActivity(activity);
    document.getElementById('profile-content').style.display = 'block';
    document.title = user.name + ' — WINOJI';
}

loadProfile();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
```

---

**Step 2: Verify PHP syntax**

```bash
php -l /c/Users/carlo/proyects/claude-skills/app/pages/user_profile.php
```

Expected: `No syntax errors detected in /c/Users/carlo/proyects/claude-skills/app/pages/user_profile.php`

---

**Step 3: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add app/pages/user_profile.php
git commit -m "feat: public user profile page with avatar, assigned issues, and activity"
```

---

### Task 3: Register route in `public/index.php`

**Files:**
- Modify: `public/index.php` (line 37)

---

**Step 1: Add `'user_profile'` to the `$allowed` array**

In `public/index.php`, find line 37:

```php
$allowed = ['login', 'dashboard', 'wiki', 'issues', 'kanban', 'sprint', 'team', 'project', 'profile', 'roadmap', 'notifications'];
```

Replace with:

```php
$allowed = ['login', 'dashboard', 'wiki', 'issues', 'kanban', 'sprint', 'team', 'project', 'profile', 'roadmap', 'notifications', 'user_profile'];
```

---

**Step 2: Verify PHP syntax**

```bash
php -l /c/Users/carlo/proyects/claude-skills/public/index.php
```

Expected: `No syntax errors detected in /c/Users/carlo/proyects/claude-skills/public/index.php`

---

**Step 3: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add public/index.php
git commit -m "feat: register user_profile route in router"
```

---

### Task 4: Update wiki `@person` mention navigation

**Files:**
- Modify: `app/assets/js/wiki.js` (around line 494)

---

**Step 1: Update the click handler to navigate to the user profile page**

In `app/assets/js/wiki.js`, find this exact block (around line 492-495):

```js
        const personMention = e.target.closest('.wiki-mention-person');
        if (personMention) {
            window.location.href = `${APP_URL}?page=team`;
        }
```

Replace with:

```js
        const personMention = e.target.closest('.wiki-mention-person');
        if (personMention) {
            const uid = parseInt(personMention.dataset.userId || '0');
            window.location.href = uid
                ? `${APP_URL}?page=user_profile&id=${uid}`
                : `${APP_URL}?page=team`;
        }
```

---

**Step 2: Verify JS syntax**

```bash
node --check /c/Users/carlo/proyects/claude-skills/app/assets/js/wiki.js
```

Expected: no output (silent success).

---

**Step 3: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add app/assets/js/wiki.js
git commit -m "feat: @mention click navigates to user profile page"
```

---

## Smoke test manual (`http://localhost/teamapp/public`)

1. Ir a **Team** → copiar el `id` de un miembro (inspeccionar elemento o ver URL invitación)
2. Navegar a `?page=user_profile&id=<id>` → debe mostrar avatar, nombre, email, rol del usuario
3. Si hay proyecto activo en localStorage → debe mostrar issues asignadas y actividad reciente
4. Si no hay proyecto activo → las secciones deben mostrar "Sin issues..." / "Sin actividad..."
5. Navegar a `?page=user_profile&id=<tu_propio_id>` → debe redirigir a `?page=profile`
6. Navegar a `?page=user_profile&id=999` (inexistente) → debe mostrar "Perfil no encontrado"
7. Navegar a `?page=user_profile` (sin id) → debe mostrar "Perfil no encontrado"
8. Ir a **Wiki** → editar una página → escribir `@` seguido de un nombre → seleccionar miembro
9. Click en el `@mención` insertada → debe navegar a `?page=user_profile&id=<uid>`
