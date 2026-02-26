# Issues Productivity — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add overdue indicators, bulk actions, issue dependencies, email notifications, and issue templates.

**Architecture:** All features follow the existing PHP/Vanilla JS pattern — PHP API files in `app/api/`, pages in `app/pages/`, JS in `app/assets/js/`. DB changes via migration files in `db/migrations/`. No new frameworks.

**Tech Stack:** PHP 8.x · MySQL 8.0 · Vanilla JS · CSS custom properties

> **Note:** Wiki version restore is already fully implemented (toggleHistory/loadHistory/restoreVersion in wiki.js, API in pages.php). No work needed there.

---

## Task 1: Overdue indicator in Issues list and Kanban

**Files:**
- Modify: `app/assets/js/issues.js:121-145` (issue row rendering)
- Modify: `app/assets/js/kanban.js:71-86` (kanban card rendering)
- Modify: `app/assets/css/main.css` (add overdue CSS classes)

**Step 1: Add CSS classes for overdue**

In `app/assets/css/main.css`, add after the `.kanban-card` block:

```css
.issue-row.overdue {
    border-left: 3px solid #dc2626;
}
.kanban-card.overdue {
    border-left: 3px solid #dc2626;
}
```

**Step 2: Add overdue class in issues.js**

In `app/assets/js/issues.js` line 121, the forEach builds `el`. After `el.className = 'card issue-row';` (line 123), add:

```js
const today = new Date(); today.setHours(0,0,0,0);
const isOverdue = issue.due_date && new Date(issue.due_date + 'T00:00:00') < today && issue.status !== 'done';
if (isOverdue) el.classList.add('overdue');
```

**Step 3: Add overdue class in kanban.js**

In `app/assets/js/kanban.js` line 73, after `card.className = 'kanban-card';`, add:

```js
const today = new Date(); today.setHours(0,0,0,0);
const isOverdue = issue.due_date && new Date(issue.due_date + 'T00:00:00') < today && issue.status !== 'done';
if (isOverdue) card.classList.add('overdue');
```

**Step 4: Verify PHP syntax**

```bash
php -l app/assets/js/issues.js
```
(JS files can't be checked with PHP, skip this step — just review manually.)

**Step 5: Commit**

```bash
git add app/assets/css/main.css app/assets/js/issues.js app/assets/js/kanban.js
git commit -m "feat: overdue indicator (red left border) on issues list and kanban cards"
```

---

## Task 2: Bulk actions in Issues list

**Files:**
- Modify: `app/pages/issues.php` (add select-all checkbox, bulk action bar HTML)
- Modify: `app/assets/js/issues.js` (add checkbox per row, bulk action logic)
- Modify: `app/assets/css/main.css` (bulk bar styles)

**Step 1: Add bulk action bar HTML to issues.php**

In `app/pages/issues.php`, after the closing `</div>` of `#filter-bar` (line 41) and before `<div id="issue-list">`, add:

```html
<!-- Bulk action bar (hidden until ≥1 selected) -->
<div id="bulk-bar" style="display:none;padding:0.5rem 0.75rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
    <span id="bulk-count" style="font-size:0.85rem;font-weight:600;color:#4f46e5;"></span>
    <button class="btn btn-secondary" id="bulk-done-btn" style="font-size:0.8rem;padding:0.3rem 0.75rem;">✓ Marcar como Hecho</button>
    <select id="bulk-priority-sel" style="padding:0.3rem 0.5rem;border:1px solid #c7d2fe;border-radius:4px;font-size:0.8rem;">
        <option value="">Cambiar prioridad...</option>
        <option value="low">Baja</option>
        <option value="medium">Media</option>
        <option value="high">Alta</option>
        <option value="critical">Crítica</option>
    </select>
    <select id="bulk-assignee-sel" style="padding:0.3rem 0.5rem;border:1px solid #c7d2fe;border-radius:4px;font-size:0.8rem;min-width:140px;">
        <option value="">Reasignar a...</option>
    </select>
    <button class="btn btn-secondary" id="bulk-clear-btn" style="font-size:0.8rem;padding:0.3rem 0.6rem;margin-left:auto;">✕ Cancelar</button>
</div>
```

Also add a select-all checkbox at the start of the filter bar (inside `#filter-bar`, before "Filtros:" span):

```html
<input type="checkbox" id="select-all-cb" title="Seleccionar todos" style="width:1rem;height:1rem;cursor:pointer;">
```

**Step 2: Add checkbox to each issue row in issues.js**

In `issues.js`, inside the `items.forEach(issue => { ... })` block, modify the innerHTML to include a checkbox at the beginning of the row. Change line 130 `el.innerHTML = \`` to start with:

```js
el.innerHTML = `
    <div style="display:flex;align-items:center;gap:0.6rem;flex-shrink:0;">
        <input type="checkbox" class="issue-cb" data-id="${issue.id}"
            style="width:1rem;height:1rem;cursor:pointer;"
            onclick="event.stopPropagation()">
    </div>
    <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
            ${typeChip}
            <span><strong>#${escapeHtml(String(issue.id))}</strong> ${escapeHtml(issue.title)}</span>
        </div>
        <div style="display:flex;gap:0.25rem;margin-top:0.25rem;flex-wrap:wrap;">${labelChips}</div>
    </div>
    <div style="display:flex;gap:0.5rem;font-size:0.8rem;align-items:center;flex-shrink:0;">
        <span class="badge badge-${issue.priority}">${issue.priority}</span>
        <span class="badge" style="background:#e5e7eb">${issue.status}</span>
        <button class="btn btn-secondary" onclick="event.stopPropagation();openFullIssue(${issue.id})"
            style="font-size:0.75rem;padding:0.2rem 0.6rem;white-space:nowrap;">&#8599; Ver completa</button>
    </div>`;
```

**Step 3: Add bulk action JS logic to issues.js**

Add these functions at the bottom of `issues.js` (before the `document.addEventListener('DOMContentLoaded'...` or equivalent init):

```js
// ── Bulk actions ──────────────────────────────────────────────────────────────
function getSelectedIds() {
    return [...document.querySelectorAll('.issue-cb:checked')].map(cb => parseInt(cb.dataset.id));
}

function updateBulkBar() {
    const ids = getSelectedIds();
    const bar = document.getElementById('bulk-bar');
    const countEl = document.getElementById('bulk-count');
    if (!bar) return;
    if (ids.length > 0) {
        bar.style.display = 'flex';
        countEl.textContent = `${ids.length} seleccionada${ids.length > 1 ? 's' : ''}`;
    } else {
        bar.style.display = 'none';
    }
}

function initBulkActions() {
    const selectAllCb = document.getElementById('select-all-cb');
    if (selectAllCb) {
        selectAllCb.addEventListener('change', () => {
            document.querySelectorAll('.issue-cb').forEach(cb => cb.checked = selectAllCb.checked);
            updateBulkBar();
        });
    }

    document.getElementById('issue-list').addEventListener('change', e => {
        if (e.target.classList.contains('issue-cb')) updateBulkBar();
    });

    document.getElementById('bulk-clear-btn')?.addEventListener('click', () => {
        document.querySelectorAll('.issue-cb').forEach(cb => cb.checked = false);
        if (selectAllCb) selectAllCb.checked = false;
        updateBulkBar();
    });

    document.getElementById('bulk-done-btn')?.addEventListener('click', async () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        for (const id of ids) {
            await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, { id, status: 'done' });
        }
        showToast(`${ids.length} issue${ids.length > 1 ? 's' : ''} marcada${ids.length > 1 ? 's' : ''} como Hecho`);
        loadIssues(currentPage);
    });

    document.getElementById('bulk-priority-sel')?.addEventListener('change', async function() {
        const priority = this.value;
        if (!priority) return;
        const ids = getSelectedIds();
        if (!ids.length) { this.value = ''; return; }
        for (const id of ids) {
            await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, { id, priority });
        }
        showToast(`Prioridad actualizada en ${ids.length} issue${ids.length > 1 ? 's' : ''}`);
        this.value = '';
        loadIssues(currentPage);
    });

    document.getElementById('bulk-assignee-sel')?.addEventListener('change', async function() {
        const assigned_to = this.value;
        if (!assigned_to) return;
        const ids = getSelectedIds();
        if (!ids.length) { this.value = ''; return; }
        for (const id of ids) {
            await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, { id, assigned_to: parseInt(assigned_to) });
        }
        showToast(`Reasignadas ${ids.length} issue${ids.length > 1 ? 's' : ''}`);
        this.value = '';
        loadIssues(currentPage);
    });
}
```

Also populate `bulk-assignee-sel` with team members — inside `initFilterBar()`, after populating `#filter-assignee`, add:

```js
const bulkSel = document.getElementById('bulk-assignee-sel');
if (bulkSel) {
    (data.data || []).forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.name;
        bulkSel.appendChild(opt);
    });
}
```

And call `initBulkActions()` from the `DOMContentLoaded` init block (wherever `initFilterBar()` is called, add `initBulkActions()` right after).

**Step 4: Verify PHP syntax of issues.php**

```bash
php -l app/pages/issues.php
```

Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add app/pages/issues.php app/assets/js/issues.js app/assets/css/main.css
git commit -m "feat: bulk actions (mark done, change priority, reassign) in issues list"
```

---

## Task 3: Issue dependencies (blocks / blocked by)

**Files:**
- Create: `db/migrations/add_issue_dependencies.sql`
- Create: `app/api/dependencies.php`
- Modify: `app/assets/js/issues.js` (add dependencies section in full issue view)
- Modify: `app/pages/issues.php` (add dependencies HTML in full issue view)

**Step 1: Create DB migration**

Create `db/migrations/add_issue_dependencies.sql`:

```sql
CREATE TABLE IF NOT EXISTS issue_dependencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_issue_id INT NOT NULL,
    to_issue_id   INT NOT NULL,
    type          ENUM('blocks','relates_to') NOT NULL DEFAULT 'blocks',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dep (from_issue_id, to_issue_id, type),
    FOREIGN KEY (from_issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (to_issue_id)   REFERENCES issues(id) ON DELETE CASCADE
);
```

Run the migration:

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp < db/migrations/add_issue_dependencies.sql
```

Verify:

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp -e "DESCRIBE issue_dependencies;"
```

Expected: columns id, from_issue_id, to_issue_id, type, created_at

**Step 2: Create dependencies API**

Create `app/api/dependencies.php`:

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b      = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        // GET ?action=list&issue_id=N — returns blocks_out + blocks_in + relates_to
        $method === 'GET' && $action === 'list' => (function() {
            $issue_id = (int)($_GET['issue_id'] ?? 0);
            $pdo = get_db();
            // Issues this issue blocks
            $s1 = $pdo->prepare(
                "SELECT id.id, id.type, i.id as issue_id, i.title, i.status, i.priority
                 FROM issue_dependencies id JOIN issues i ON id.to_issue_id = i.id
                 WHERE id.from_issue_id = ? ORDER BY id.type, i.id"
            );
            $s1->execute([$issue_id]);
            $outgoing = $s1->fetchAll();
            // Issues that block this issue
            $s2 = $pdo->prepare(
                "SELECT id.id, id.type, i.id as issue_id, i.title, i.status, i.priority
                 FROM issue_dependencies id JOIN issues i ON id.from_issue_id = i.id
                 WHERE id.to_issue_id = ? ORDER BY id.type, i.id"
            );
            $s2->execute([$issue_id]);
            $incoming = $s2->fetchAll();
            print json_encode(['success' => true, 'outgoing' => $outgoing, 'incoming' => $incoming]);
        })(),

        // POST ?action=add — body: { from_issue_id, to_issue_id, type }
        $method === 'POST' && $action === 'add' => (function() use ($b) {
            if (empty($b['from_issue_id']) || empty($b['to_issue_id'])) {
                print json_encode(['success' => false, 'error' => 'from_issue_id and to_issue_id required']);
                return;
            }
            if ((int)$b['from_issue_id'] === (int)$b['to_issue_id']) {
                print json_encode(['success' => false, 'error' => 'Una issue no puede depender de sí misma']);
                return;
            }
            $type = in_array($b['type'] ?? '', ['blocks','relates_to']) ? $b['type'] : 'blocks';
            try {
                get_db()->prepare(
                    "INSERT IGNORE INTO issue_dependencies (from_issue_id, to_issue_id, type) VALUES (?,?,?)"
                )->execute([(int)$b['from_issue_id'], (int)$b['to_issue_id'], $type]);
                print json_encode(['success' => true]);
            } catch (Exception $e) {
                print json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        })(),

        // POST ?action=remove — body: { id }
        $method === 'POST' && $action === 'remove' => (function() use ($b) {
            get_db()->prepare("DELETE FROM issue_dependencies WHERE id = ?")->execute([(int)($b['id'] ?? 0)]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Unknown action'])
    };
    exit;
}
```

**Step 3: Add dependencies section HTML to full issue view in issues.php**

In `app/pages/issues.php`, find the full-issue-view section. Look for the `#fi-github-section` div and add BEFORE it:

```html
<!-- Dependencies section in full issue view -->
<div id="fi-deps-section" style="margin-bottom:1.5rem;">
    <h4 style="margin:0 0 0.5rem;font-size:0.95rem;">Dependencias</h4>
    <div id="fi-deps-list" style="margin-bottom:0.75rem;"></div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        <select id="fi-dep-type" style="padding:0.3rem 0.5rem;border:1px solid #ddd;border-radius:4px;font-size:0.8rem;">
            <option value="blocks">Esta bloquea a</option>
            <option value="relates_to">Relacionada con</option>
        </select>
        <input type="number" id="fi-dep-issue-id" placeholder="ID de issue..." min="1"
            style="width:120px;padding:0.3rem 0.5rem;border:1px solid #ddd;border-radius:4px;font-size:0.8rem;">
        <button class="btn btn-secondary" id="fi-add-dep-btn" style="font-size:0.8rem;padding:0.3rem 0.6rem;">+ Añadir</button>
    </div>
</div>
```

**Step 4: Add dependencies JS functions to issues.js**

Add these functions in `issues.js`:

```js
// ── Dependencies ──────────────────────────────────────────────────────────────
async function loadDependencies(issueId) {
    const res = await fetch(`${APP_URL}/app/api/dependencies.php?action=list&issue_id=${issueId}`);
    const data = await res.json();
    const list = document.getElementById('fi-deps-list');
    if (!list) return;
    const outgoing = data.outgoing || [];
    const incoming = data.incoming || [];
    if (!outgoing.length && !incoming.length) {
        list.innerHTML = '<em style="color:#aaa;font-size:0.85rem;">Sin dependencias.</em>';
        return;
    }
    const renderRow = (dep, direction) => {
        const label = direction === 'out'
            ? (dep.type === 'blocks' ? '🔴 Bloquea' : '🔗 Relacionada con')
            : (dep.type === 'blocks' ? '⛔ Bloqueada por' : '🔗 Relacionada con');
        const statusColor = { todo:'#9ca3af', in_progress:'#f59e0b', review:'#3b82f6', done:'#16a34a' }[dep.status] || '#9ca3af';
        return `<div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0.5rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:0.3rem;font-size:0.85rem;">
            <div>
                <span style="color:#6b7280;margin-right:0.4rem;">${label}:</span>
                <strong>#${escapeHtml(String(dep.issue_id))}</strong> ${escapeHtml(dep.title)}
                <span style="color:${statusColor};margin-left:0.4rem;">(${dep.status})</span>
            </div>
            <button onclick="removeDependency(${dep.id})" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:1rem;" title="Eliminar">×</button>
        </div>`;
    };
    list.innerHTML = [...outgoing.map(d => renderRow(d,'out')), ...incoming.map(d => renderRow(d,'in'))].join('');
}

async function removeDependency(depId) {
    await apiFetch(`${APP_URL}/app/api/dependencies.php?action=remove`, { id: depId });
    await loadDependencies(currentFullIssueId);
}

function initDependencies() {
    document.getElementById('fi-add-dep-btn')?.addEventListener('click', async () => {
        const toId = parseInt(document.getElementById('fi-dep-issue-id').value);
        const type = document.getElementById('fi-dep-type').value;
        if (!toId) return showToast('Escribe el ID de la issue', 'error');
        const data = await apiFetch(`${APP_URL}/app/api/dependencies.php?action=add`, {
            from_issue_id: currentFullIssueId, to_issue_id: toId, type
        });
        if (data.success) {
            document.getElementById('fi-dep-issue-id').value = '';
            await loadDependencies(currentFullIssueId);
        } else {
            showToast(data.error || 'Error al añadir dependencia', 'error');
        }
    });
}
```

Also add `let currentFullIssueId = null;` at the top of `issues.js` (near `let currentIssueId = null;`).

Call `await loadDependencies(id);` inside the `openFullIssue(id)` function (where other sections like `loadComments`, `loadChecklist` are called).

Call `initDependencies()` from `DOMContentLoaded`.

**Step 5: Verify PHP syntax**

```bash
php -l app/api/dependencies.php
```

Expected: `No syntax errors detected`

**Step 6: Commit**

```bash
git add db/migrations/add_issue_dependencies.sql app/api/dependencies.php app/pages/issues.php app/assets/js/issues.js
git commit -m "feat: issue dependencies (blocks/relates_to) with API and full issue view UI"
```

---

## Task 4: Email notifications

**Files:**
- Create: `app/includes/mailer.php`
- Modify: `app/api/activity.php` (call mailer in notify_project and notify_user)

**Step 1: Create mailer.php**

Create `app/includes/mailer.php`:

```php
<?php
/**
 * Send notification email using PHP mail() or SMTP socket.
 * Uses SMTP_* constants from config.php.
 * Non-fatal: never throws, logs errors silently.
 */
function send_notification_email(string $to_email, string $to_name, string $subject, string $body_html): void {
    if (empty($to_email)) return;
    $from      = SMTP_FROM;
    $from_name = SMTP_FROM_NAME;

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Mailer: TeamApp/1.0\r\n";

    $full_body = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#374151;'>{$body_html}</body></html>";

    @mail($to_email, $subject, $full_body, $headers);
}

/**
 * Build and send a notification email for a project event.
 * Looks up user email from DB.
 */
function maybe_send_email_notification(int $user_id, string $type, string $entity_type, ?string $entity_title, ?string $actor_name): void {
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return;

        $type_label = [
            'issue_created'  => 'Nueva issue creada',
            'issue_updated'  => 'Issue actualizada',
            'issue_deleted'  => 'Issue eliminada',
            'comment_added'  => 'Nuevo comentario',
            'page_created'   => 'Nueva página en Wiki',
            'page_updated'   => 'Página Wiki actualizada',
            'mention'        => 'Te han mencionado',
        ][$type] ?? $type;

        $title = htmlspecialchars($entity_title ?? '', ENT_QUOTES);
        $actor = htmlspecialchars($actor_name ?? 'Alguien', ENT_QUOTES);

        $subject = "[Team App] {$type_label}" . ($entity_title ? ": {$entity_title}" : '');
        $body    = "<h3 style='color:#4f46e5;'>{$type_label}</h3>
                    <p><strong>{$actor}</strong> ha realizado una acción sobre <em>{$title}</em>.</p>
                    <p style='color:#6b7280;font-size:0.875rem;'>Accede a Team App para ver los detalles.</p>";

        send_notification_email($user['email'], $user['name'], $subject, $body);
    } catch (Exception $e) {
        // Non-fatal
    }
}
```

**Step 2: Integrate mailer into notify_project() and notify_user() in activity.php**

In `app/api/activity.php`, add `require_once __DIR__ . '/../includes/mailer.php';` after the existing requires (line 3).

Inside `notify_project()`, after `$ins->execute(...)` (line 48), add:

```php
// Send email notification
$actor_stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$actor_stmt->execute([$actor_id]);
$actor_name = $actor_stmt->fetchColumn();
maybe_send_email_notification((int)$uid, $type, $entity_type, $entity_title, $actor_name ?: null);
```

Inside `notify_user()`, after the `execute(...)` call (line 61), add:

```php
$pdo2 = get_db();
$actor_stmt2 = $pdo2->prepare('SELECT name FROM users WHERE id = ?');
$actor_stmt2->execute([$actor_id]);
$actor_name2 = $actor_stmt2->fetchColumn();
maybe_send_email_notification($user_id, $type, $entity_type, $entity_title, $actor_name2 ?: null);
```

**Step 3: Verify PHP syntax**

```bash
php -l app/includes/mailer.php && php -l app/api/activity.php
```

Expected: `No syntax errors detected` for both

**Step 4: Commit**

```bash
git add app/includes/mailer.php app/api/activity.php
git commit -m "feat: email notifications via PHP mail() using SMTP_FROM/SMTP_FROM_NAME from config"
```

---

## Task 5: Issue templates

**Files:**
- Create: `db/migrations/add_issue_templates.sql`
- Create: `app/api/templates.php`
- Modify: `app/pages/issues.php` (add template picker to Nueva Issue modal)
- Modify: `app/assets/js/issues.js` (load templates, fill modal on select)
- Modify: `app/pages/project.php` (add template management section)

**Step 1: Create DB migration**

Create `db/migrations/add_issue_templates.sql`:

```sql
CREATE TABLE IF NOT EXISTS issue_templates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    project_id  INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    title       VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT,
    type_id     INT DEFAULT NULL,
    priority    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id)    REFERENCES issue_types(id) ON DELETE SET NULL
);
```

Run:

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp < db/migrations/add_issue_templates.sql
```

Verify:

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp -e "DESCRIBE issue_templates;"
```

Expected: columns id, project_id, name, title, description, type_id, priority, created_at

**Step 2: Create templates API**

Create `app/api/templates.php`:

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b      = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        $method === 'GET' && $action === 'list' => (function() {
            $pid  = (int)($_GET['project_id'] ?? 0);
            $stmt = get_db()->prepare(
                'SELECT it.*, itype.name as type_name, itype.color as type_color
                 FROM issue_templates it
                 LEFT JOIN issue_types itype ON it.type_id = itype.id
                 WHERE it.project_id = ?
                 ORDER BY it.name'
            );
            $stmt->execute([$pid]);
            print json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        })(),

        $method === 'POST' && $action === 'create' => (function() use ($b) {
            if (empty($b['project_id']) || empty($b['name'])) {
                print json_encode(['success' => false, 'error' => 'project_id and name required']);
                return;
            }
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'INSERT INTO issue_templates (project_id, name, title, description, type_id, priority)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                (int)$b['project_id'],
                $b['name'],
                $b['title'] ?? '',
                $b['description'] ?? '',
                !empty($b['type_id']) ? (int)$b['type_id'] : null,
                in_array($b['priority'] ?? '', ['low','medium','high','critical']) ? $b['priority'] : 'medium',
            ]);
            print json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        })(),

        $method === 'POST' && $action === 'update' => (function() use ($b) {
            if (empty($b['id'])) { print json_encode(['success' => false, 'error' => 'id required']); return; }
            get_db()->prepare(
                'UPDATE issue_templates SET name=?, title=?, description=?, type_id=?, priority=? WHERE id=?'
            )->execute([
                $b['name'] ?? '',
                $b['title'] ?? '',
                $b['description'] ?? '',
                !empty($b['type_id']) ? (int)$b['type_id'] : null,
                in_array($b['priority'] ?? '', ['low','medium','high','critical']) ? $b['priority'] : 'medium',
                (int)$b['id'],
            ]);
            print json_encode(['success' => true]);
        })(),

        $method === 'POST' && $action === 'delete' => (function() use ($b) {
            get_db()->prepare('DELETE FROM issue_templates WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Unknown action'])
    };
    exit;
}
```

**Step 3: Add template picker to Nueva Issue modal in issues.php**

In `app/pages/issues.php`, find the "Nueva Issue" modal (`#new-issue-modal`). After the modal title `<h3>Nueva Issue</h3>` and before the first form field, add:

```html
<div style="margin-bottom:0.75rem;">
    <label style="font-size:0.85rem;color:#6b7280;">Usar plantilla (opcional)</label>
    <select id="template-picker" style="width:100%;padding:0.4rem 0.6rem;border:1px solid #ddd;border-radius:6px;font-size:0.875rem;margin-top:0.3rem;">
        <option value="">Sin plantilla</option>
    </select>
</div>
```

**Step 4: Add template management section to project.php**

In `app/pages/project.php`, after the issue types section, add:

```html
<!-- Issue Templates section -->
<div class="card" style="margin-top:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h3 style="margin:0;font-size:1rem;">Plantillas de Issues</h3>
        <button class="btn btn-primary" id="new-template-btn" style="font-size:0.85rem;">+ Nueva Plantilla</button>
    </div>
    <div id="templates-list"></div>

    <!-- New/edit template form (hidden) -->
    <div id="template-form" style="display:none;border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:1rem;">
        <input type="hidden" id="tpl-id">
        <div style="display:grid;gap:0.6rem;">
            <input type="text" id="tpl-name" placeholder="Nombre de la plantilla *" style="padding:0.4rem 0.6rem;border:1px solid #ddd;border-radius:6px;font-size:0.875rem;">
            <input type="text" id="tpl-title" placeholder="Título pre-rellenado (opcional)" style="padding:0.4rem 0.6rem;border:1px solid #ddd;border-radius:6px;font-size:0.875rem;">
            <textarea id="tpl-desc" placeholder="Descripción pre-rellenada (opcional)" rows="3" style="padding:0.4rem 0.6rem;border:1px solid #ddd;border-radius:6px;font-size:0.875rem;resize:vertical;"></textarea>
            <div style="display:flex;gap:0.5rem;">
                <select id="tpl-type" style="flex:1;padding:0.4rem 0.5rem;border:1px solid #ddd;border-radius:6px;font-size:0.875rem;">
                    <option value="">Sin tipo</option>
                </select>
                <select id="tpl-priority" style="flex:1;padding:0.4rem 0.5rem;border:1px solid #ddd;border-radius:6px;font-size:0.875rem;">
                    <option value="low">Baja</option>
                    <option value="medium" selected>Media</option>
                    <option value="high">Alta</option>
                    <option value="critical">Crítica</option>
                </select>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button class="btn btn-primary" id="tpl-save-btn">Guardar</button>
                <button class="btn btn-secondary" id="tpl-cancel-btn">Cancelar</button>
            </div>
        </div>
    </div>
</div>
```

**Step 5: Add template JS logic to issues.js**

Add these functions to `issues.js`:

```js
// ── Issue templates ───────────────────────────────────────────────────────────
let issueTemplates = [];

async function loadTemplates() {
    if (!PROJECT_ID) return;
    const res  = await fetch(`${APP_URL}/app/api/templates.php?action=list&project_id=${PROJECT_ID}`);
    const data = await res.json();
    issueTemplates = data.data || [];
    const sel = document.getElementById('template-picker');
    if (!sel) return;
    sel.innerHTML = '<option value="">Sin plantilla</option>';
    issueTemplates.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.name;
        sel.appendChild(opt);
    });
}

function initTemplatePicker() {
    document.getElementById('template-picker')?.addEventListener('change', function() {
        const tpl = issueTemplates.find(t => t.id == this.value);
        if (!tpl) return;
        const titleEl = document.getElementById('new-title');
        const descEl  = document.getElementById('new-description');
        const typeEl  = document.getElementById('new-type');
        const prioEl  = document.getElementById('new-priority');
        if (titleEl && tpl.title) titleEl.value = tpl.title;
        if (descEl  && tpl.description) descEl.value = tpl.description;
        if (typeEl  && tpl.type_id) typeEl.value = tpl.type_id;
        if (prioEl  && tpl.priority) prioEl.value = tpl.priority;
    });
}
```

Call `loadTemplates()` and `initTemplatePicker()` from `DOMContentLoaded`.

**Step 6: Add template management JS to project.php inline script**

In `app/pages/project.php`, add to the existing inline `<script>` block (or create one if needed):

```js
// ── Templates management ──────────────────────────────────────────────────────
async function loadTemplatesList() {
    if (!PROJECT_ID) return;
    const res  = await fetch(`${APP_URL}/app/api/templates.php?action=list&project_id=${PROJECT_ID}`);
    const data = await res.json();
    const list = document.getElementById('templates-list');
    if (!list) return;
    const tpls = data.data || [];
    if (!tpls.length) {
        list.innerHTML = '<p style="color:#aaa;font-size:0.875rem;">No hay plantillas todavía.</p>';
        return;
    }
    list.innerHTML = tpls.map(t => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid #f3f4f6;">
            <div>
                <strong style="font-size:0.9rem;">${escapeHtml(t.name)}</strong>
                ${t.type_name ? `<span style="font-size:0.75rem;color:#6b7280;margin-left:0.4rem;">${escapeHtml(t.type_name)}</span>` : ''}
                <span style="font-size:0.75rem;color:#6b7280;margin-left:0.4rem;">${t.priority}</span>
            </div>
            <div style="display:flex;gap:0.4rem;">
                <button onclick="editTemplate(${t.id})" class="btn btn-secondary" style="font-size:0.75rem;padding:0.2rem 0.5rem;">✎ Editar</button>
                <button onclick="deleteTemplate(${t.id})" class="btn btn-danger" style="font-size:0.75rem;padding:0.2rem 0.5rem;">🗑</button>
            </div>
        </div>`).join('');
}

function editTemplate(id) {
    const tpl = window._tplCache?.find(t => t.id == id);
    if (!tpl) return;
    document.getElementById('tpl-id').value    = tpl.id;
    document.getElementById('tpl-name').value  = tpl.name;
    document.getElementById('tpl-title').value = tpl.title || '';
    document.getElementById('tpl-desc').value  = tpl.description || '';
    document.getElementById('tpl-type').value  = tpl.type_id || '';
    document.getElementById('tpl-priority').value = tpl.priority;
    document.getElementById('template-form').style.display = 'block';
}

async function deleteTemplate(id) {
    showConfirm('¿Eliminar esta plantilla?', async () => {
        await apiFetch(`${APP_URL}/app/api/templates.php?action=delete`, { id });
        loadTemplatesList();
    }, { confirmLabel:'Eliminar', confirmClass:'btn-danger' });
}

// Init
document.getElementById('new-template-btn')?.addEventListener('click', () => {
    document.getElementById('tpl-id').value = '';
    ['tpl-name','tpl-title','tpl-desc'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('tpl-priority').value = 'medium';
    document.getElementById('template-form').style.display = 'block';
});
document.getElementById('tpl-cancel-btn')?.addEventListener('click', () => {
    document.getElementById('template-form').style.display = 'none';
});
document.getElementById('tpl-save-btn')?.addEventListener('click', async () => {
    const id     = document.getElementById('tpl-id').value;
    const name   = document.getElementById('tpl-name').value.trim();
    if (!name) return showToast('El nombre es obligatorio', 'error');
    const payload = {
        name,
        title:       document.getElementById('tpl-title').value.trim(),
        description: document.getElementById('tpl-desc').value.trim(),
        type_id:     document.getElementById('tpl-type').value || null,
        priority:    document.getElementById('tpl-priority').value,
    };
    const action = id ? 'update' : 'create';
    if (id) payload.id = parseInt(id);
    else    payload.project_id = PROJECT_ID;
    const data = await apiFetch(`${APP_URL}/app/api/templates.php?action=${action}`, payload);
    if (data.success) {
        document.getElementById('template-form').style.display = 'none';
        loadTemplatesList();
        showToast(id ? 'Plantilla actualizada' : 'Plantilla creada');
    } else {
        showToast(data.error || 'Error', 'error');
    }
});

loadTemplatesList();
```

**Step 7: Verify PHP syntax**

```bash
php -l app/api/templates.php && php -l app/pages/issues.php && php -l app/pages/project.php
```

Expected: `No syntax errors detected` for all three

**Step 8: Commit**

```bash
git add db/migrations/add_issue_templates.sql app/api/templates.php app/pages/issues.php app/assets/js/issues.js app/pages/project.php
git commit -m "feat: issue templates — create/edit/delete in Proyecto page, apply from Nueva Issue modal"
```

---

## Final: Merge to master

After all tasks are committed on the working branch (or if working directly on master, just verify):

```bash
git log --oneline -8
```

All 5 commits should be visible. Verify the app loads in Laragon: `http://localhost/teamapp/public`

---

## Testing checklist

1. **Overdue indicator**
   - Create an issue with `due_date` in the past → issue row has red left border
   - Same issue in Kanban → card has red left border
   - Mark issue as `done` → red border disappears (status=done excluded)
   - Issue with no `due_date` → no red border

2. **Bulk actions**
   - Check multiple issues → bulk bar appears with count
   - "Marcar como Hecho" → all selected change status
   - Change priority dropdown → all selected change priority
   - Reasignar dropdown → all selected change assignee
   - "Select all" checkbox → selects all visible
   - "Cancelar" → deselects all, bar hides

3. **Dependencies**
   - Open full issue view → Dependencies section visible
   - Add dependency "Esta bloquea a #2" → appears in list
   - Add "Relacionada con #3" → appears in list
   - Click × → dependency removed
   - Try adding issue as dependency of itself → error toast
   - Open issue #2 → "Bloqueada por" entry visible

4. **Email notifications**
   - Create an issue → no PHP fatal errors (email may or may not send depending on local SMTP)
   - `php -l app/includes/mailer.php` → no syntax errors

5. **Issue templates**
   - Go to Proyecto page → "Plantillas de Issues" section visible
   - Create template "Bug report" with priority=high → appears in list
   - Go to Issues → Nueva Issue modal → template picker dropdown shows "Bug report"
   - Select template → title/priority fields pre-filled
   - Back in Proyecto → edit template → values update
   - Delete template → removed from list and picker
