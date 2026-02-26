# Notifications System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** In-app notification system — bell icon with badge in sidebar, dropdown panel, full notifications page, polling every 30s, covering all project events.

**Architecture:** New `notifications` table (one row per recipient per event). Helper `notify_project()` in `activity.php` fans out to all team members. `app/api/notifications.php` serves list/count/mark-read. Bell icon in sidebar with polling; full page at `?page=notifications`.

**Tech Stack:** PHP 8.x, MySQL 8.x, Vanilla JS, CSS variables (dark mode compatible).

---

## Task 1: DB migration

**Files:**
- Create: `db/migrations/add_notifications.sql`

**Step 1: Create migration file**

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    project_id   INT NOT NULL,
    actor_id     INT NOT NULL,
    type         VARCHAR(50) NOT NULL,
    entity_type  VARCHAR(20) NOT NULL,
    entity_id    INT NOT NULL,
    entity_title VARCHAR(255) NULL,
    read_at      DATETIME NULL DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, read_at),
    INDEX idx_user_created (user_id, created_at DESC),
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Step 2: Run migration**
```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp < db/migrations/add_notifications.sql
```
Expected: no errors.

**Step 3: Verify table exists**
```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp -e "DESCRIBE notifications;"
```
Expected: shows all columns including `read_at`.

**Step 4: Commit**
```bash
git add db/migrations/add_notifications.sql
git commit -m "feat: add notifications table migration"
```

---

## Task 2: notify_project() helper

**Files:**
- Modify: `app/api/activity.php`

**Context:**
Add `notify_project()` function that inserts one notification row per project team member (excluding the actor). To get team members for a project, query `team_members` via the project creator's team.

**Step 1: Append to activity.php**

After the `get_recent_activity()` function, add:

```php
function notify_project(int $project_id, int $actor_id, string $type, string $entity_type, int $entity_id, ?string $entity_title = null): void {
    try {
        $pdo = get_db();
        // Get all team members for this project (via the project creator's team)
        $stmt = $pdo->prepare(
            "SELECT DISTINCT tm.user_id
             FROM team_members tm
             WHERE tm.team_id = (
                 SELECT tm2.team_id FROM team_members tm2
                 WHERE tm2.user_id = (SELECT created_by FROM projects WHERE id = ?)
                 LIMIT 1
             ) AND tm.user_id != ?"
        );
        $stmt->execute([$project_id, $actor_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($recipients)) return;
        $ins = $pdo->prepare(
            "INSERT INTO notifications (user_id, project_id, actor_id, type, entity_type, entity_id, entity_title)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($recipients as $uid) {
            $ins->execute([(int)$uid, $project_id, $actor_id, $type, $entity_type, $entity_id, $entity_title]);
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

function notify_user(int $user_id, int $project_id, int $actor_id, string $type, string $entity_type, int $entity_id, ?string $entity_title = null): void {
    if ($user_id === $actor_id) return;
    try {
        get_db()->prepare(
            "INSERT INTO notifications (user_id, project_id, actor_id, type, entity_type, entity_id, entity_title)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$user_id, $project_id, $actor_id, $type, $entity_type, $entity_id, $entity_title]);
    } catch (Exception $e) {
        // Non-fatal
    }
}
```

**Step 2: Verify syntax**
```bash
php -l app/api/activity.php
```
Expected: `No syntax errors detected`

**Step 3: Commit**
```bash
git add app/api/activity.php
git commit -m "feat: add notify_project() and notify_user() helpers to activity.php"
```

---

## Task 3: notifications API

**Files:**
- Create: `app/api/notifications.php`

**Step 1: Create the file**

```php
<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/activity.php';

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $user   = current_user();
    $uid    = $user['id'];

    if ($method === 'POST') { verify_csrf(); }
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    match(true) {
        $method === 'GET' && $action === 'list' => (function() use ($uid) {
            $rows = get_db()->prepare(
                "SELECT n.*, u.name AS actor_name, u.avatar AS actor_avatar
                 FROM notifications n
                 JOIN users u ON n.actor_id = u.id
                 WHERE n.user_id = ?
                 ORDER BY n.created_at DESC
                 LIMIT 50"
            );
            $rows->execute([$uid]);
            print json_encode(['success' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        })(),

        $method === 'GET' && $action === 'unread_count' => (function() use ($uid) {
            $cnt = get_db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
            $cnt->execute([$uid]);
            print json_encode(['success' => true, 'count' => (int)$cnt->fetchColumn()]);
        })(),

        $method === 'POST' && $action === 'mark_read' => (function() use ($b, $uid) {
            $id = (int)($b['id'] ?? 0);
            if (!$id) { print json_encode(['success' => false, 'error' => 'id requerido']); return true; }
            get_db()->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?")
                    ->execute([$id, $uid]);
            print json_encode(['success' => true]);
        })(),

        $method === 'POST' && $action === 'mark_all_read' => (function() use ($uid) {
            get_db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
                    ->execute([$uid]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Acción no válida'])
    };
    exit;
}
```

**Step 2: Verify syntax**
```bash
php -l app/api/notifications.php
```

**Step 3: Test unread_count endpoint in browser**

Navigate to: `http://localhost/teamapp/public/app/api/notifications.php?action=unread_count`
Expected: `{"success":true,"count":0}`

**Step 4: Commit**
```bash
git add app/api/notifications.php
git commit -m "feat: add notifications API (list, unread_count, mark_read, mark_all_read)"
```

---

## Task 4: Integrate notifications in issues.php

**Files:**
- Modify: `app/api/issues.php`

**Context:**
`issues.php` already has `require_once __DIR__ . '/activity.php';` at the top.
The create and update match arms are closures. Add `notify_project()` calls after the existing `log_activity()` calls.

**Step 1: Add notify call to create arm**

Find this block in the `create` match arm:
```php
if (!empty($result['success'])) {
    log_activity($pid, current_user()['id'], 'issue_created', 'issue', $result['id'], $b['title']);
}
```

Replace with:
```php
if (!empty($result['success'])) {
    $u = current_user();
    log_activity($pid, $u['id'], 'issue_created', 'issue', $result['id'], $b['title']);
    notify_project($pid, $u['id'], 'issue_created', 'issue', $result['id'], $b['title']);
    // If assigned to someone other than the creator, send assignment notification
    if (!empty($b['assigned_to']) && (int)$b['assigned_to'] !== $u['id']) {
        notify_user((int)$b['assigned_to'], $pid, $u['id'], 'issue_assigned', 'issue', $result['id'], $b['title']);
    }
}
```

**Step 2: Add notify call to update arm**

Find this block in the `update` match arm:
```php
if (!empty($result['success'])) {
    $issue = get_issue((int)$b['id']);
    if ($issue) {
        log_activity($issue['project_id'], $u['id'], 'issue_updated', 'issue', $issue['id'], $issue['title']);
    }
}
```

Replace with:
```php
if (!empty($result['success'])) {
    $issue = get_issue((int)$b['id']);
    if ($issue) {
        log_activity($issue['project_id'], $u['id'], 'issue_updated', 'issue', $issue['id'], $issue['title']);
        notify_project($issue['project_id'], $u['id'], 'issue_updated', 'issue', $issue['id'], $issue['title']);
        // If assigning to someone new, send assignment notification
        if (!empty($b['assigned_to']) && (int)$b['assigned_to'] !== $u['id']) {
            notify_user((int)$b['assigned_to'], $issue['project_id'], $u['id'], 'issue_assigned', 'issue', $issue['id'], $issue['title']);
        }
    }
}
```

**Step 3: Verify syntax**
```bash
php -l app/api/issues.php
```

**Step 4: Commit**
```bash
git add app/api/issues.php
git commit -m "feat: fire notify_project() on issue create/update"
```

---

## Task 5: Integrate notifications in comments.php

**Files:**
- Modify: `app/api/comments.php`

**Context:**
The `create` match arm is already a closure. After `log_activity()`, add `notify_project()` and scan the comment body for `@mentions`.

**Step 1: Update the create comment closure**

Find the `comment_added` log_activity call and the block around it:
```php
if ($row) {
    log_activity($row['project_id'], $user['id'], 'comment_added', 'comment', $result['id'], $row['title']);
}
```

Replace with:
```php
if ($row) {
    log_activity($row['project_id'], $user['id'], 'comment_added', 'comment', $result['id'], $row['title']);
    notify_project($row['project_id'], $user['id'], 'comment_added', 'comment', $result['id'], $row['title']);
    // Detect @mentions and send targeted notifications
    preg_match_all('/@([\w]+(?:\s[\w]+)*)/', $b['body'], $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $mentionedName) {
            $mu = get_db()->prepare("SELECT id FROM users WHERE name = ? LIMIT 1");
            $mu->execute([trim($mentionedName)]);
            $muid = $mu->fetchColumn();
            if ($muid && (int)$muid !== $user['id']) {
                notify_user((int)$muid, $row['project_id'], $user['id'], 'mention', 'comment', $result['id'], $row['title']);
            }
        }
    }
}
```

**Step 2: Verify syntax**
```bash
php -l app/api/comments.php
```

**Step 3: Commit**
```bash
git add app/api/comments.php
git commit -m "feat: fire notify_project() on comment create, detect @mentions"
```

---

## Task 6: Integrate notifications in pages.php

**Files:**
- Modify: `app/api/pages.php`

**Context:**
`pages.php` does not yet include `activity.php`. Add the require, then add notify calls after `create_page` and `update_page` for project-scoped pages only (scope === 'project' AND project_id is set).

**Step 1: Add require at top**

After `require_once __DIR__ . '/../bootstrap.php';`, add:
```php
require_once __DIR__ . '/activity.php';
```

**Step 2: Add notify to create action**

Find:
```php
echo json_encode(create_page($b['title'], $b['parent_id'] ?? null, $b['content'] ?? '', current_user()['id'], $scope, $pid));
```

Replace with:
```php
$result = create_page($b['title'], $b['parent_id'] ?? null, $b['content'] ?? '', current_user()['id'], $scope, $pid);
if (!empty($result['success']) && $scope === 'project' && $pid) {
    $u = current_user();
    notify_project($pid, $u['id'], 'page_created', 'page', $result['id'], $b['title']);
}
echo json_encode($result);
```

**Step 3: Add notify to update action**

Find:
```php
echo json_encode(update_page((int)$b['id'], $b['title'], $b['content'] ?? '', current_user()['id']));
```

Replace with:
```php
$result = update_page((int)$b['id'], $b['title'], $b['content'] ?? '', current_user()['id']);
if (!empty($result['success'])) {
    $page = get_page((int)$b['id']);
    if ($page && $page['scope'] === 'project' && $page['project_id']) {
        $u = current_user();
        notify_project((int)$page['project_id'], $u['id'], 'page_updated', 'page', (int)$b['id'], $b['title']);
    }
}
echo json_encode($result);
```

**Step 4: Verify syntax**
```bash
php -l app/api/pages.php
```

**Step 5: Commit**
```bash
git add app/api/pages.php
git commit -m "feat: fire notify_project() on wiki page create/update"
```

---

## Task 7: Bell icon in sidebar

**Files:**
- Modify: `app/includes/layout_top.php`
- Modify: `app/assets/css/main.css`

**Context:**
Add a bell icon with a red badge between the `.project-switcher` div and the `<ul>` nav. Add a dropdown panel positioned below it.

**Step 1: Add HTML to layout_top.php**

After the `</div>` closing tag of `.project-switcher` and before `<ul>`, insert:

```html
<div class="notif-bell-wrap" style="position:relative;margin-bottom:0.5rem;">
    <button id="notif-bell" title="Notificaciones" style="width:100%;text-align:left;background:none;border:none;cursor:pointer;color:var(--text-sidebar);font-size:0.875rem;padding:0.4rem 0.75rem;border-radius:4px;display:flex;align-items:center;gap:0.5rem;">
        <span style="font-size:1rem;">&#128276;</span>
        <span>Notificaciones</span>
        <span id="notif-badge" class="notif-badge hidden">0</span>
    </button>
    <div id="notif-panel" class="notif-panel hidden"></div>
</div>
```

**Step 2: Add CSS to main.css**

Append at the end of `main.css`:

```css
/* Notifications */
.notif-badge {
    background: #dc2626;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    border-radius: 999px;
    padding: 0.1rem 0.4rem;
    min-width: 18px;
    text-align: center;
    margin-left: auto;
}
.notif-panel {
    position: absolute;
    left: 0;
    top: calc(100% + 4px);
    width: 320px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    z-index: 400;
    overflow: hidden;
}
.notif-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
}
.notif-item {
    display: flex;
    gap: 0.6rem;
    padding: 0.65rem 1rem;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    font-size: 0.8rem;
    color: var(--text-primary);
    text-decoration: none;
    transition: background 0.1s;
}
.notif-item:hover { background: var(--hover-bg); }
.notif-item.unread { background: rgba(79,70,229,0.06); }
.notif-item.unread:hover { background: rgba(79,70,229,0.1); }
.notif-item-icon { font-size: 1rem; flex-shrink: 0; margin-top: 0.1rem; }
.notif-item-body { flex: 1; min-width: 0; }
.notif-item-text { line-height: 1.4; }
.notif-item-time { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.15rem; }
.notif-panel-footer {
    padding: 0.6rem 1rem;
    display: flex;
    gap: 0.5rem;
    justify-content: space-between;
    border-top: 1px solid var(--border);
    font-size: 0.8rem;
}
.notif-panel-footer a,
.notif-panel-footer button {
    color: #4f46e5;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    padding: 0;
    text-decoration: none;
}
.notif-panel-footer a:hover,
.notif-panel-footer button:hover { text-decoration: underline; }
```

**Step 3: Add JS to layout_top.php**

After the dark-mode script block, append a new `<script>` block:

```html
<script>
// Notifications bell
(function() {
    const NOTIF_ICONS = {
        issue_created: '✨', issue_updated: '✏️', issue_assigned: '👤',
        comment_added: '💬', page_created: '📄', page_updated: '📝', mention: '🔔'
    };
    const NOTIF_LABELS = {
        issue_created: 'creó una issue', issue_updated: 'actualizó una issue',
        issue_assigned: 'te asignó una issue', comment_added: 'comentó en',
        page_created: 'creó una página', page_updated: 'editó una página', mention: 'te mencionó en'
    };
    const NOTIF_URLS = {
        issue: id => `${APP_URL}?page=issues&open_issue=${id}`,
        comment: id => `${APP_URL}?page=issues`,
        page: id => `${APP_URL}?page=wiki&open_page=${id}`
    };

    let cachedCount = -1;
    const bell  = document.getElementById('notif-bell');
    const badge = document.getElementById('notif-badge');
    const panel = document.getElementById('notif-panel');
    if (!bell || !badge || !panel) return;

    function escH(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    async function fetchCount() {
        try {
            const res  = await fetch(`${APP_URL}/app/api/notifications.php?action=unread_count`);
            const data = await res.json();
            const n    = data.count || 0;
            if (n !== cachedCount) {
                cachedCount = n;
                badge.textContent = n > 99 ? '99+' : n;
                badge.classList.toggle('hidden', n === 0);
                // If panel is open, reload it
                if (!panel.classList.contains('hidden')) renderPanel();
            }
        } catch(e) {}
    }

    async function renderPanel() {
        panel.innerHTML = '<div style="padding:1rem;color:#9ca3af;font-size:0.8rem;">Cargando...</div>';
        try {
            const res  = await fetch(`${APP_URL}/app/api/notifications.php?action=list`);
            const data = await res.json();
            const items = (data.data || []).slice(0, 20);

            let html = `<div class="notif-panel-header">
                <span>Notificaciones</span>
                <button id="notif-mark-all" style="font-size:0.75rem;color:#4f46e5;background:none;border:none;cursor:pointer;">Marcar todas como leídas</button>
            </div>`;

            if (!items.length) {
                html += '<div style="padding:1.5rem;text-align:center;color:#9ca3af;font-size:0.8rem;">Sin notificaciones</div>';
            } else {
                items.forEach(n => {
                    const icon    = NOTIF_ICONS[n.type]  || '•';
                    const label   = NOTIF_LABELS[n.type] || n.type.replace(/_/g, ' ');
                    const url     = (NOTIF_URLS[n.entity_type] || (() => APP_URL))(n.entity_id);
                    const unread  = !n.read_at ? 'unread' : '';
                    const timeStr = typeof timeAgo === 'function' ? timeAgo(n.created_at) : n.created_at.slice(0, 10);
                    html += `<a class="notif-item ${unread}" href="${escH(url)}" data-id="${n.id}" data-read="${n.read_at ? '1' : '0'}">
                        <span class="notif-item-icon">${icon}</span>
                        <div class="notif-item-body">
                            <div class="notif-item-text"><strong>${escH(n.actor_name)}</strong> ${label} <em>${escH(n.entity_title || '#' + n.entity_id)}</em></div>
                            <div class="notif-item-time">${timeStr}</div>
                        </div>
                    </a>`;
                });
            }

            html += `<div class="notif-panel-footer">
                <a href="${APP_URL}?page=notifications">Ver todas →</a>
            </div>`;

            panel.innerHTML = html;

            // Mark read on click
            panel.querySelectorAll('.notif-item[data-read="0"]').forEach(el => {
                el.addEventListener('click', async () => {
                    await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_read`, { id: parseInt(el.dataset.id) });
                    cachedCount = -1;
                    fetchCount();
                });
            });

            // Mark all read
            const markAllBtn = panel.querySelector('#notif-mark-all');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async e => {
                    e.preventDefault();
                    e.stopPropagation();
                    await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_all_read`, {});
                    cachedCount = -1;
                    fetchCount();
                    renderPanel();
                });
            }
        } catch(e) {
            panel.innerHTML = '<div style="padding:1rem;color:#dc2626;font-size:0.8rem;">Error al cargar</div>';
        }
    }

    bell.addEventListener('click', e => {
        e.stopPropagation();
        const isOpen = !panel.classList.contains('hidden');
        panel.classList.toggle('hidden');
        if (!isOpen) renderPanel();
    });

    document.addEventListener('click', e => {
        if (!panel.contains(e.target) && e.target !== bell) {
            panel.classList.add('hidden');
        }
    });

    // Initial fetch + polling every 30s
    fetchCount();
    setInterval(fetchCount, 30000);
})();
</script>
```

**Step 4: Verify syntax**
```bash
php -l app/includes/layout_top.php
```

**Step 5: Verify visually**
- Open the app → sidebar shows 🔔 Notificaciones with no badge
- Create an issue → login as another user → badge should show 1

**Step 6: Commit**
```bash
git add app/includes/layout_top.php app/assets/css/main.css
git commit -m "feat: notification bell icon in sidebar with badge, dropdown panel, polling"
```

---

## Task 8: Notifications page

**Files:**
- Create: `app/pages/notifications.php`
- Modify: `public/index.php`
- Modify: `app/includes/layout_top.php` (add nav link)

**Step 1: Create notifications.php**

```php
<?php
$page_title = 'Notificaciones';
require __DIR__ . '/../includes/layout_top.php';
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
    <h2 style="margin:0;">Notificaciones</h2>
    <button id="mark-all-btn" class="btn btn-secondary" style="font-size:0.875rem;">
        &#10003; Marcar todas como leídas
    </button>
</div>

<div style="display:flex;gap:0.5rem;margin-bottom:1rem;">
    <button class="btn notif-filter-btn active" data-filter="all" style="font-size:0.85rem;">Todas</button>
    <button class="btn notif-filter-btn" data-filter="unread" style="font-size:0.85rem;">No leídas</button>
</div>

<div id="notif-page-list" class="card" style="padding:0;">
    <div style="padding:2rem;text-align:center;color:#9ca3af;">Cargando...</div>
</div>

<script>
const NOTIF_ICONS = {
    issue_created:'✨', issue_updated:'✏️', issue_assigned:'👤',
    comment_added:'💬', page_created:'📄', page_updated:'📝', mention:'🔔'
};
const NOTIF_LABELS = {
    issue_created:'creó una issue', issue_updated:'actualizó una issue',
    issue_assigned:'te asignó', comment_added:'comentó en',
    page_created:'creó la página', page_updated:'editó la página', mention:'te mencionó en'
};
const NOTIF_URLS = {
    issue: id => `${APP_URL}?page=issues&open_issue=${id}`,
    comment: id => `${APP_URL}?page=issues`,
    page: id => `${APP_URL}?page=wiki&open_page=${id}`
};

let allNotifs = [];
let currentFilter = 'all';

function escapeHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

async function loadNotifications() {
    const res  = await fetch(`${APP_URL}/app/api/notifications.php?action=list`);
    const data = await res.json();
    allNotifs = data.data || [];
    render();
}

function render() {
    const items = currentFilter === 'unread' ? allNotifs.filter(n => !n.read_at) : allNotifs;
    const list  = document.getElementById('notif-page-list');

    document.querySelectorAll('.notif-filter-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.filter === currentFilter);
        b.style.background = b.dataset.filter === currentFilter ? '#4f46e5' : '';
        b.style.color      = b.dataset.filter === currentFilter ? '#fff' : '';
    });

    if (!items.length) {
        list.innerHTML = '<div style="padding:2rem;text-align:center;color:#9ca3af;">Sin notificaciones</div>';
        return;
    }

    list.innerHTML = items.map(n => {
        const icon    = NOTIF_ICONS[n.type]  || '•';
        const label   = NOTIF_LABELS[n.type] || n.type.replace(/_/g, ' ');
        const url     = (NOTIF_URLS[n.entity_type] || (() => APP_URL))(n.entity_id);
        const unreadBg = !n.read_at ? 'background:rgba(79,70,229,0.06);' : '';
        const timeStr  = typeof timeAgo === 'function' ? timeAgo(n.created_at) : new Date(n.created_at).toLocaleString('es');
        return `<a class="notif-page-row" href="${escapeHtml(url)}"
            data-id="${n.id}" data-read="${n.read_at ? '1' : '0'}"
            style="display:flex;gap:0.75rem;padding:0.85rem 1.25rem;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text-primary);${unreadBg}transition:background 0.1s;">
            <span style="font-size:1.1rem;flex-shrink:0;">${icon}</span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.875rem;line-height:1.4;">
                    <strong>${escapeHtml(n.actor_name)}</strong> ${label}
                    <em>${escapeHtml(n.entity_title || '#' + n.entity_id)}</em>
                </div>
                <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.2rem;">${timeStr}</div>
            </div>
            ${!n.read_at ? '<span style="width:8px;height:8px;border-radius:50%;background:#4f46e5;flex-shrink:0;margin-top:0.35rem;"></span>' : ''}
        </a>`;
    }).join('');

    // Mark as read on click
    list.querySelectorAll('.notif-page-row[data-read="0"]').forEach(el => {
        el.addEventListener('click', async () => {
            await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_read`, { id: parseInt(el.dataset.id) });
        });
    });
}

document.getElementById('mark-all-btn').addEventListener('click', async () => {
    await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_all_read`, {});
    allNotifs = allNotifs.map(n => ({ ...n, read_at: new Date().toISOString() }));
    render();
});

document.querySelectorAll('.notif-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        currentFilter = btn.dataset.filter;
        render();
    });
});

loadNotifications();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
```

**Step 2: Add route in public/index.php**

Find:
```php
$allowed = ['login', 'dashboard', 'wiki', 'issues', 'kanban', 'team', 'project', 'profile', 'roadmap'];
```
Replace with:
```php
$allowed = ['login', 'dashboard', 'wiki', 'issues', 'kanban', 'team', 'project', 'profile', 'roadmap', 'notifications'];
```

**Step 3: Verify syntax**
```bash
php -l app/pages/notifications.php
```

**Step 4: Verify in browser**
- Navigate to `http://localhost/teamapp/public/?page=notifications`
- Should show the notifications page with filter buttons
- "Marcar todas como leídas" button should work

**Step 5: Commit**
```bash
git add app/pages/notifications.php public/index.php
git commit -m "feat: notifications page with filter (todas/no leídas) and mark-all-read"
```

---

## Summary

8 tasks:
1. DB migration for `notifications` table
2. `notify_project()` + `notify_user()` helpers in activity.php
3. `notifications.php` API (list, unread_count, mark_read, mark_all_read)
4. Integrate in issues.php (create + update with assignment)
5. Integrate in comments.php (create + @mention detection)
6. Integrate in pages.php (create + update, project-scoped only)
7. Bell icon in sidebar — badge, dropdown panel, 30s polling
8. Full notifications page + route
