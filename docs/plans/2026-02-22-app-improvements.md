# WINOJI — App Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement 24 improvements across bugs, enhancements, new features, and UX polish for the WINOJI.

**Architecture:** PHP 8.x vanilla backend + Vanilla JS frontend. All API endpoints in `app/api/`, page shells in `app/pages/`, shared JS in `app/assets/js/`. DB migrations go in `db/migrations/`.

**Tech Stack:** PHP 8.x, MySQL 8.x, Vanilla JS (no frameworks), CSS variables for theming.

---

## Task 1: Fix PROJECT_ID hardcoded fallback

**Files:**
- Modify: `app/pages/kanban.php` (line ~44)
- Modify: `app/pages/issues.php` (line ~317)

**Context:**
Both pages have `|| '1'` fallback when no project is in localStorage. This silently shows project 1's data instead of prompting the user to select a project.

**Step 1: Fix kanban.php**

Find and replace on line ~44:
```php
// Before:
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '1');

// After:
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
```

Also add guard at start of `loadIssues()` in kanban.js — actually kanban.js already uses PROJECT_ID from the PHP-injected constant. So we only need to fix the PHP. Then add a guard in `kanban.php` inline script after the `const PROJECT_ID`:

```html
<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
if (!PROJECT_ID) {
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('kanban-board').innerHTML =
            '<div style="padding:2rem;color:#9ca3af;text-align:center;">Selecciona un proyecto en el menú lateral para ver el tablero.</div>';
    });
}
</script>
```

**Step 2: Fix issues.php**

Find and replace on line ~317:
```php
// Before:
<script>const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '1');</script>

// After:
<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
if (!PROJECT_ID) {
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('issues-list').innerHTML =
            '<div style="padding:2rem;color:#9ca3af;text-align:center;">Selecciona un proyecto en el menú lateral para ver las issues.</div>';
    });
}
</script>
```

**Step 3: Verify**
- Open kanban without a project selected → see "Selecciona un proyecto" message
- Open issues without a project selected → same message

**Step 4: Commit**
```bash
git add app/pages/kanban.php app/pages/issues.php
git commit -m "fix: remove hardcoded project_id=1 fallback, show empty state instead"
```

---

## Task 2: Sidebar active page indicator

**Files:**
- Modify: `app/includes/layout_top.php`

**Context:**
The sidebar nav has hardcoded `<a href="...">` links with no active state. We need to highlight the current page link so the user knows where they are.

**Step 1: Inject current page into JS**

In `layout_top.php`, after `const APP_URL = '...'`, add:

```html
<script>
const APP_URL = '<?= APP_URL ?>';
const CURRENT_PAGE = '<?= htmlspecialchars($_GET['page'] ?? 'dashboard') ?>';
</script>
```

**Step 2: Add active class to current nav link**

Below the existing sidebar scripts in `layout_top.php`, add:

```html
<script>
(function() {
    document.querySelectorAll('.sidebar ul li a').forEach(a => {
        const url = new URL(a.href, location.origin);
        const page = url.searchParams.get('page') || 'dashboard';
        if (page === CURRENT_PAGE) {
            a.classList.add('active');
        }
    });
})();
</script>
```

**Step 3: Add active style in main.css**

Find the `.sidebar ul li a` rule and add:

```css
.sidebar ul li a.active {
    background: rgba(255,255,255,0.15);
    color: #fff;
    font-weight: 600;
}
```

**Step 4: Verify**
- Go to Dashboard → "Dashboard" link is highlighted
- Go to Wiki → "Wiki" link is highlighted

**Step 5: Commit**
```bash
git add app/includes/layout_top.php app/assets/css/main.css
git commit -m "feat: add active page indicator to sidebar navigation"
```

---

## Task 3: Kanban improvements — type badge, column counter, drag feedback

**Files:**
- Modify: `app/assets/js/kanban.js`
- Modify: `app/assets/css/main.css`

**Context:**
- Column headers show only the label name, no issue count
- Cards show priority + assignee but no type badge
- No visual drag-over feedback on columns
- Issues API already returns `type_name` and `type_color`

**Step 1: Update `renderBoard()` in kanban.js**

Replace the column header line (line ~65):
```js
// Before:
colEl.innerHTML = `<div class="kanban-col-header">${COLUMN_LABELS[col]}</div>`;

// After:
colEl.innerHTML = `<div class="kanban-col-header">${COLUMN_LABELS[col]} <span class="col-count">${colIssues.length}</span></div>`;
```

Replace the empty state line (line ~68):
```js
// Before:
colEl.innerHTML += '<div style="color:#aaa;font-size:0.875rem;padding:0.75rem;text-align:center;">Sin issues</div>';

// After:
colEl.innerHTML += '<div class="kanban-empty">Sin issues en esta columna</div>';
```

Replace card innerHTML (lines ~75-77):
```js
// Before:
card.innerHTML = `<div class="card-title">${escapeHtml(issue.title)}</div>
    <div class="card-meta"><span class="badge badge-${issue.priority}">${issue.priority}</span>
    ${issue.assignee_name ? `<span>${escapeHtml(issue.assignee_name)}</span>` : ''}</div>`;

// After:
const typeChip = issue.type_name
    ? `<span class="badge" style="background:${escapeHtml(issue.type_color||'#6b7280')}22;color:${escapeHtml(issue.type_color||'#6b7280')};border:1px solid ${escapeHtml(issue.type_color||'#6b7280')}44;font-size:0.65rem;">${escapeHtml(issue.type_name)}</span>`
    : '';
card.innerHTML = `
    ${typeChip ? `<div style="margin-bottom:0.3rem;">${typeChip}</div>` : ''}
    <div class="card-title">${escapeHtml(issue.title)}</div>
    <div class="card-meta">
        <span class="badge badge-${issue.priority}">${issue.priority}</span>
        ${issue.assignee_name ? `<span>${escapeHtml(issue.assignee_name)}</span>` : ''}
    </div>`;
```

**Step 2: Add drag-over visual feedback**

After the `colEl.addEventListener('dragover', ...)` line, update it:
```js
// Before:
colEl.addEventListener('dragover', e => e.preventDefault());

// After:
colEl.addEventListener('dragover', e => { e.preventDefault(); colEl.classList.add('drag-over'); });
colEl.addEventListener('dragleave', e => { if (!colEl.contains(e.relatedTarget)) colEl.classList.remove('drag-over'); });
```

Also update the drop handler to remove the class:
```js
colEl.addEventListener('drop', async e => {
    e.preventDefault();
    colEl.classList.remove('drag-over');
    // ... existing drop code
```

**Step 3: Add CSS in main.css**

```css
.col-count {
    background: rgba(255,255,255,0.2);
    border-radius: 999px;
    padding: 0.1rem 0.4rem;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 0.4rem;
}
.kanban-empty {
    color: #9ca3af;
    font-size: 0.8rem;
    padding: 1.25rem 0.75rem;
    text-align: center;
    border: 2px dashed #e5e7eb;
    border-radius: 6px;
    margin: 0.5rem;
}
.kanban-col.drag-over {
    outline: 2px dashed #4f46e5;
    outline-offset: -2px;
    background: rgba(79, 70, 229, 0.05);
}
```

**Step 4: Verify**
- Open Kanban — each column shows issue count in header badge
- Cards show type chip when type is set
- Drag a card over a column — blue dashed outline appears

**Step 5: Commit**
```bash
git add app/assets/js/kanban.js app/assets/css/main.css
git commit -m "feat: kanban type badge on cards, column counters, drag visual feedback"
```

---

## Task 4: Relative dates helper

**Files:**
- Modify: `app/assets/js/utils.js`
- Modify: `app/assets/js/dashboard.js` (use in recent_issues)
- Modify: `app/pages/issues.php` (use in comments section)

**Context:**
Dates show as raw ISO strings or `toLocaleDateString()`. Add a `timeAgo(dateStr)` helper and use it where dates appear.

**Step 1: Add `timeAgo()` to utils.js**

Append to `app/assets/js/utils.js`:
```js
function timeAgo(dateStr) {
    if (!dateStr) return '';
    const now = new Date();
    const then = new Date(dateStr);
    const diffMs = now - then;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffH   = Math.floor(diffMin / 60);
    const diffD   = Math.floor(diffH / 24);
    const diffW   = Math.floor(diffD / 7);
    const diffMo  = Math.floor(diffD / 30);
    const diffY   = Math.floor(diffD / 365);
    if (diffSec < 60)   return 'ahora mismo';
    if (diffMin < 60)   return `hace ${diffMin} min`;
    if (diffH < 24)     return `hace ${diffH}h`;
    if (diffD === 1)    return 'ayer';
    if (diffD < 7)      return `hace ${diffD} días`;
    if (diffW < 5)      return `hace ${diffW} semanas`;
    if (diffMo < 12)    return `hace ${diffMo} meses`;
    return `hace ${diffY} años`;
}
```

**Step 2: Use in dashboard.js recent_issues**

In dashboard.js, find the recent_issues render (line ~132) and replace:
```js
// Before:
<span>${new Date(i.created_at).toLocaleDateString()}</span>

// After:
<span title="${escapeHtml(new Date(i.created_at).toLocaleString())}">${timeAgo(i.created_at)}</span>
```

**Step 3: Use in issues.php comments section**

In `app/pages/issues.php`, find the comment date render in the `renderComments()` JS function and replace any `new Date(...).toLocaleDateString()` calls with `timeAgo(c.created_at)`.

**Step 4: Verify**
- Dashboard recent issues show "hace 2 días", "hace 1h", etc.
- Comments in issue view show relative time with absolute time on hover

**Step 5: Commit**
```bash
git add app/assets/js/utils.js app/assets/js/dashboard.js app/pages/issues.php
git commit -m "feat: add timeAgo() relative dates helper, apply to dashboard and comments"
```

---

## Task 5: Dashboard general refresh button

**Files:**
- Modify: `app/pages/dashboard.php`
- Modify: `app/assets/js/dashboard.js`

**Context:**
Only the PR section has a refresh button. We need a general "Actualizar" button in the dashboard header that re-runs `loadDashboard()`.

**Step 1: Add refresh button in dashboard.php header**

In `dashboard.php`, find the header `<h2>` or project name element. After the `<h2>` tag, add:

```html
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <h2 style="margin:0;" id="dash-project-name">Dashboard</h2>
    <button id="dash-refresh-btn" class="btn btn-secondary" style="font-size:0.875rem;">
        &#8635; Actualizar
    </button>
</div>
```

Remove the original `<h2>` that was there.

**Step 2: Wire refresh button in dashboard.js**

At the end of `dashboard.js`, before `loadDashboard()`, add:

```js
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('dash-refresh-btn');
    if (btn) btn.addEventListener('click', () => {
        btn.disabled = true;
        btn.textContent = 'Actualizando...';
        loadDashboard().finally(() => {
            btn.disabled = false;
            btn.innerHTML = '&#8635; Actualizar';
        });
    });
});
```

Note: wrap `loadDashboard()` in a try/catch so `.finally()` always runs — `loadDashboard` already returns implicitly (no `return` keyword). Change the last line from `loadDashboard()` to `loadDashboard()`.

**Step 3: Verify**
- Dashboard has "Actualizar" button in top right
- Clicking it reloads all data and re-enables the button when done

**Step 4: Commit**
```bash
git add app/pages/dashboard.php app/assets/js/dashboard.js
git commit -m "feat: add general refresh button to dashboard header"
```

---

## Task 6: Edit labels

**Files:**
- Modify: `app/api/labels.php`
- Modify: `app/pages/issues.php` (label management UI in full issue view)

**Context:**
`labels.php` has list/create/delete/add_to_issue/remove_from_issue but no `update` action. The full issue view shows labels but doesn't allow editing existing ones.

**Step 1: Add `update` action to labels.php**

In `labels.php`, find the routing switch and add:
```php
case 'update':
    require_login();
    $id    = (int)($body['id'] ?? 0);
    $name  = trim($body['name'] ?? '');
    $color = trim($body['color'] ?? '');
    if (!$id || !$name || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        json_error('Datos inválidos');
    }
    // Verify label belongs to a project the user can access
    $label = db()->query("SELECT project_id FROM labels WHERE id = $id")->fetch();
    if (!$label) json_error('Label no encontrado');
    require_project_access($label['project_id']);
    db()->prepare("UPDATE labels SET name = ?, color = ? WHERE id = ?")->execute([$name, $color, $id]);
    json_ok(['id' => $id]);
    break;
```

**Step 2: Add edit button to label chips in full issue view**

In `app/pages/issues.php`, in the `renderLabels(labels)` function, update label chips to show an edit icon for admin/manager:

```js
// In the label chip HTML, after the × button:
// Add a pencil icon that opens an inline edit form
```

The actual implementation: in the `loadLabels()` function that renders the project labels list (in the label picker section), add an edit button per label that shows an inline name+color input. When saved, calls `POST /app/api/labels.php?action=update`.

**Step 3: Verify**
- In project label picker, labels have an edit icon
- Click edit → inline inputs appear for name and color
- Save → label updates in DB and UI refreshes

**Step 4: Commit**
```bash
git add app/api/labels.php app/pages/issues.php
git commit -m "feat: add label edit support (API update action + UI inline edit)"
```

---

## Task 7: Edit comments

**Files:**
- Modify: `app/api/comments.php`
- Modify: `app/pages/issues.php` (comment UI in panel and full view)

**Context:**
`comments.php` has list/create/delete but no `update` action. Users should be able to edit their own comments.

**Step 1: Add `update` action to comments.php**

```php
case 'update':
    require_login();
    $id      = (int)($body['id'] ?? 0);
    $content = trim($body['content'] ?? '');
    if (!$id || !$content) json_error('Datos inválidos');
    $comment = db()->query("SELECT user_id, issue_id FROM comments WHERE id = $id")->fetch();
    if (!$comment) json_error('Comentario no encontrado');
    // Only author or admin can edit
    $u = current_user();
    if ($comment['user_id'] != $u['id'] && $u['role'] !== 'admin') json_error('Sin permiso', 403);
    db()->prepare("UPDATE comments SET content = ? WHERE id = ?")->execute([$content, $id]);
    json_ok(['id' => $id]);
    break;
```

**Step 2: Add edit button to comments in issues.php**

In the `renderComments(comments)` JS function, add an edit button next to delete for the current user's comments:

```js
const isOwn = c.user_id == currentUserId; // currentUserId injected from PHP session
const editBtn = isOwn
    ? `<button onclick="editComment(${c.id}, this)" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:0.75rem;padding:0 0.3rem;">Editar</button>`
    : '';
```

Add `editComment(id, btn)` function:
```js
function editComment(id, btn) {
    const commentDiv = btn.closest('[data-comment-id]');
    const contentEl  = commentDiv.querySelector('.comment-content');
    const original   = contentEl.textContent;
    contentEl.innerHTML = `
        <textarea style="width:100%;padding:0.4rem;border:1px solid #ddd;border-radius:4px;font-size:0.875rem;resize:vertical;" rows="3">${escapeHtml(original)}</textarea>
        <div style="margin-top:0.4rem;display:flex;gap:0.4rem;">
            <button class="btn btn-primary" style="font-size:0.8rem;padding:0.3rem 0.8rem;" onclick="saveCommentEdit(${id}, this)">Guardar</button>
            <button class="btn btn-secondary" style="font-size:0.8rem;padding:0.3rem 0.8rem;" onclick="cancelCommentEdit(${id}, '${escapeHtml(original)}', this)">Cancelar</button>
        </div>`;
}
async function saveCommentEdit(id, btn) {
    const textarea = btn.closest('[data-comment-id]').querySelector('textarea');
    const content  = textarea.value.trim();
    if (!content) return;
    btn.disabled = true;
    await apiFetch(`${APP_URL}/app/api/comments.php?action=update`, { id, content });
    loadComments(currentIssueId);
}
function cancelCommentEdit(id, original, btn) {
    const commentDiv = btn.closest('[data-comment-id]');
    commentDiv.querySelector('.comment-content').textContent = original;
}
```

**Step 3: Verify**
- Own comments have "Editar" button
- Click → inline textarea appears
- Save → comment updates in DB and re-renders

**Step 4: Commit**
```bash
git add app/api/comments.php app/pages/issues.php
git commit -m "feat: add comment editing for own comments (API + UI)"
```

---

## Task 8: Story points

**Files:**
- Create: `db/migrations/add_story_points.sql`
- Modify: `app/api/issues.php`
- Modify: `app/pages/issues.php`
- Modify: `app/assets/js/kanban.js`
- Modify: `app/api/dashboard.php`
- Modify: `app/assets/js/dashboard.js`
- Modify: `app/pages/dashboard.php`

**Context:**
Story points are a common agile estimation field. Add `story_points TINYINT UNSIGNED NULL` to issues, show in full issue view and kanban cards, sum in dashboard.

**Step 1: Create migration**

`db/migrations/add_story_points.sql`:
```sql
ALTER TABLE issues ADD COLUMN story_points TINYINT UNSIGNED NULL DEFAULT NULL AFTER due_date;
```

**Step 2: Run migration**
```bash
mysql -u root teamapp < db/migrations/add_story_points.sql
```

**Step 3: Update issues API**

In `app/api/issues.php`:
- `list_issues()`: add `i.story_points` to SELECT
- `get_issue()`: add `i.story_points` to SELECT
- `create_issue()`: add `story_points` to allowed fields (nullable int, 1-100)
- `update_issue()`: add `'story_points'` to `$allowed` array with validation `is_null($v) || (is_int($v) && $v >= 1 && $v <= 100)`

**Step 4: Add story points field in full issue view (issues.php)**

In the metadata card of the full issue view, add after due_date row:
```html
<div class="meta-row">
    <span class="meta-label">Puntos</span>
    <input type="number" id="fi-points" min="1" max="100" placeholder="—"
        style="width:70px;padding:0.3rem;border:1px solid #ddd;border-radius:4px;font-size:0.875rem;">
</div>
```

In `openFullIssue(issue)`, add:
```js
document.getElementById('fi-points').value = issue.story_points || '';
```

In `fi-save` click handler, add to payload:
```js
const points = document.getElementById('fi-points').value;
story_points: points ? parseInt(points) : null,
```

**Step 5: Show story points on kanban cards**

In `kanban.js` card innerHTML, after `card-meta` div:
```js
${issue.story_points ? `<div style="font-size:0.7rem;color:#6b7280;margin-top:0.2rem;">${issue.story_points} pts</div>` : ''}
```

**Step 6: Sum story points in dashboard**

In `app/api/dashboard.php`, in the `get_dashboard_data()` function, add to the returned data:
```php
'story_points_total' => (int)db()->query(
    "SELECT COALESCE(SUM(story_points),0) FROM issues
     WHERE project_id = $project_id AND status != 'done'"
)->fetchColumn(),
```

In `dashboard.js`, add a stat card for points:
```js
const ptEl = document.getElementById('stat-points');
if (ptEl) ptEl.textContent = d.story_points_total || 0;
```

In `dashboard.php`, add a stat card:
```html
<div class="stat-card">
    <div class="stat-value" id="stat-points">0</div>
    <div class="stat-label">Puntos pendientes</div>
</div>
```

**Step 7: Verify**
- Full issue view has "Puntos" field — save 5 → saved in DB
- Kanban card shows "5 pts"
- Dashboard shows sum of pending story points

**Step 8: Commit**
```bash
git add db/migrations/add_story_points.sql app/api/issues.php app/pages/issues.php app/assets/js/kanban.js app/api/dashboard.php app/assets/js/dashboard.js app/pages/dashboard.php
git commit -m "feat: story points — DB field, API, full issue view, kanban badge, dashboard sum"
```

---

## Task 9: Export issues to CSV

**Files:**
- Modify: `app/api/issues.php`
- Modify: `app/pages/issues.php`

**Context:**
Add a "Exportar CSV" button to the issues list. Clicking it downloads a CSV with all currently filtered issues (same filters as list, no pagination limit).

**Step 1: Add `export` action to issues API**

In `app/api/issues.php`, add case:
```php
case 'export':
    require_login();
    $project_id = (int)($_GET['project_id'] ?? 0);
    if (!$project_id) json_error('project_id requerido');
    require_project_access($project_id);
    // Build same filter as list_issues but no LIMIT
    $where = ["i.project_id = $project_id"];
    if (!empty($_GET['status']))   $where[] = "i.status = " . db()->quote($_GET['status']);
    if (!empty($_GET['priority'])) $where[] = "i.priority = " . db()->quote($_GET['priority']);
    if (!empty($_GET['assigned_to'])) $where[] = "i.assigned_to = " . (int)$_GET['assigned_to'];
    if (!empty($_GET['type_id'])) $where[] = "i.type_id = " . (int)$_GET['type_id'];
    $sql = "SELECT i.id, i.title, i.status, i.priority, i.due_date, i.story_points, i.created_at,
                   COALESCE(it.name,'') AS type_name, COALESCE(u.name,'') AS assignee_name
            FROM issues i
            LEFT JOIN issue_types it ON i.type_id = it.id
            LEFT JOIN users u ON i.assigned_to = u.id
            WHERE " . implode(' AND ', $where) . " ORDER BY i.created_at DESC";
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="issues-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Título','Estado','Prioridad','Tipo','Asignado a','Puntos','Fecha límite','Creado']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['title'], $r['status'], $r['priority'], $r['type_name'],
                       $r['assignee_name'], $r['story_points'], $r['due_date'], $r['created_at']]);
    }
    fclose($out);
    exit;
```

Note: this action must exit early (no JSON), so add it before the json routing or add a special header check.

**Step 2: Add Export button in issues.php**

In the issues filter bar, add an export button:
```html
<button id="export-csv-btn" class="btn btn-secondary" title="Exportar a CSV"
    style="padding:0.4rem 0.75rem;font-size:0.85rem;">&#8595; CSV</button>
```

Wire it in JS:
```js
document.getElementById('export-csv-btn').addEventListener('click', () => {
    const f = getActiveFilters();
    const params = new URLSearchParams({ action: 'export', project_id: PROJECT_ID });
    if (f.status)      params.set('status', f.status);
    if (f.priority)    params.set('priority', f.priority);
    if (f.assigned_to) params.set('assigned_to', f.assigned_to);
    if (f.type_id)     params.set('type_id', f.type_id);
    window.location.href = `${APP_URL}/app/api/issues.php?${params}`;
});
```

**Step 3: Verify**
- Issues page has "↓ CSV" button
- Click → browser downloads issues.csv with all filtered issues

**Step 4: Commit**
```bash
git add app/api/issues.php app/pages/issues.php
git commit -m "feat: export filtered issues to CSV download"
```

---

## Task 10: Search result highlighting

**Files:**
- Modify: `app/includes/layout_top.php` (search results render)

**Context:**
The search box shows results but doesn't highlight the matching query text in the result titles.

**Step 1: Add highlight helper and use in results render**

In `layout_top.php`, inside the search IIFE, after the `escapeHtml` inline or using the global `escapeHtml` from utils.js, add a `highlightQuery(text, query)` function and use it when rendering results:

```js
function highlightQuery(text, query) {
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const escapedQ = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return escaped.replace(new RegExp(`(${escapedQ})`, 'gi'),
        '<mark style="background:#fef08a;color:#713f12;border-radius:2px;padding:0 1px;">$1</mark>');
}
```

In the results render, replace:
```js
// Before:
const titleEsc = r.title.replace(/</g,'&lt;').replace(/>/g,'&gt;');
return `... ${icon} ${titleEsc} ...`;

// After:
return `... ${icon} ${highlightQuery(r.title, q)} ...`;
```

**Step 2: Verify**
- Search for "fix" → matching characters in result titles appear with yellow highlight

**Step 3: Commit**
```bash
git add app/includes/layout_top.php
git commit -m "feat: highlight matching query text in search results"
```

---

## Task 11: Keyboard shortcuts

**Files:**
- Modify: `app/assets/js/utils.js`
- Modify: `app/pages/issues.php`
- Modify: `app/includes/layout_top.php`

**Context:**
Add global keyboard shortcuts: `n` = new issue (on issues page), `/` = focus search box, `Esc` = close any open modal/panel, `?` = show shortcuts help toast.

**Step 1: Add keyboard shortcut handler in utils.js**

Append to `utils.js`:
```js
(function initKeyboardShortcuts() {
    document.addEventListener('keydown', e => {
        // Don't fire when typing in inputs/textareas
        const tag = document.activeElement?.tagName;
        const isEditing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                       || document.activeElement?.isContentEditable;

        if (e.key === '/' && !isEditing) {
            e.preventDefault();
            document.getElementById('search-input')?.focus();
        }

        if (e.key === 'Escape') {
            // Close search results
            document.getElementById('search-results')?.classList.add('hidden');
            // Close any open modal
            document.querySelectorAll('.modal:not(.hidden)').forEach(m => m.classList.add('hidden'));
            // Blur search input
            if (document.activeElement?.id === 'search-input') {
                document.activeElement.blur();
            }
            // Fire custom event so page-specific code can react
            document.dispatchEvent(new CustomEvent('app:escape'));
        }

        if (e.key === '?' && !isEditing) {
            showToast('Atajos: / = buscar · n = nueva issue (en issues) · Esc = cerrar', 'info', 4000);
        }
    });
})();
```

Update `showToast` signature in utils.js to accept optional duration:
```js
// Before:
function showToast(msg, type = 'success') {

// After:
function showToast(msg, type = 'success', duration = 3000) {
```

And update the timeout:
```js
// Before:
setTimeout(() => t.remove(), 3000);

// After:
setTimeout(() => t.remove(), duration);
```

**Step 2: Add `n` shortcut in issues.php**

In `app/pages/issues.php`, inside the `<script>` section, add:
```js
document.addEventListener('keydown', e => {
    const tag = document.activeElement?.tagName;
    const isEditing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                   || document.activeElement?.isContentEditable;
    if (e.key === 'n' && !isEditing) {
        document.getElementById('new-issue-btn')?.click();
    }
});

// Also close issue panel on Escape
document.addEventListener('app:escape', () => {
    const panel = document.getElementById('issue-panel');
    if (panel && !panel.classList.contains('hidden')) {
        panel.classList.add('hidden');
    }
});
```

**Step 3: Verify**
- Press `/` anywhere → search input focused
- Press `?` → toast with shortcuts
- Press `Esc` → closes modals/panels
- On issues page, press `n` → new issue modal opens

**Step 4: Commit**
```bash
git add app/assets/js/utils.js app/pages/issues.php
git commit -m "feat: global keyboard shortcuts — / search, Esc close, ? help, n new issue"
```

---

## Task 12: Avatar support

**Files:**
- Modify: `app/pages/profile.php`
- Modify: `app/api/auth.php`
- Modify: `app/includes/layout_top.php`
- Modify: `app/pages/team.php` (member avatars)
- Modify: `app/pages/issues.php` (assignee avatars in comments)
- Modify: `app/assets/css/main.css`

**Context:**
`users.avatar` column exists but is unused. Add avatar upload in profile (stored as base64 data URL or URL) and display in sidebar user area, team list, and comment authors.

**Approach:** Store avatar as a URL in `users.avatar`. In profile, allow uploading an image — read it with FileReader as base64 and store (max 200KB after resize). On display, show as `<img>` or show initials if null.

**Step 1: Add avatar upload UI in profile.php**

In `profile.php`, add an avatar section above the two cards:
```html
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
    <div id="avatar-preview" style="width:64px;height:64px;border-radius:50%;background:#4f46e5;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.5rem;font-weight:700;overflow:hidden;flex-shrink:0;">
        <?php
        $av = $user['avatar'] ?? '';
        if ($av): ?>
            <img src="<?= htmlspecialchars($av) ?>" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
            <?= htmlspecialchars(mb_substr($user['name'], 0, 1)) ?>
        <?php endif; ?>
    </div>
    <div>
        <div style="font-weight:600;margin-bottom:0.25rem;"><?= htmlspecialchars($user['name']) ?></div>
        <label class="btn btn-secondary" style="cursor:pointer;font-size:0.8rem;">
            Cambiar foto
            <input type="file" id="avatar-file" accept="image/*" style="display:none;">
        </label>
    </div>
</div>
```

Add JS in profile.php:
```js
document.getElementById('avatar-file').addEventListener('change', async function() {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { showToast('Imagen muy grande (máx 2MB)', 'error'); return; }
    // Resize to max 128x128 via canvas
    const url = await resizeImageToDataURL(file, 128);
    // Save to server
    const res = await fetch(`${APP_URL}/app/api/auth.php?action=update_avatar`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ avatar: url })
    });
    const data = await res.json();
    if (data.success) {
        document.getElementById('avatar-preview').innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:cover;">`;
        showToast('Avatar actualizado');
    } else {
        showToast(data.error || 'Error al guardar avatar', 'error');
    }
});

function resizeImageToDataURL(file, maxSize) {
    return new Promise(resolve => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                const ratio = Math.min(maxSize / img.width, maxSize / img.height, 1);
                canvas.width  = Math.round(img.width  * ratio);
                canvas.height = Math.round(img.height * ratio);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                resolve(canvas.toDataURL('image/jpeg', 0.85));
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}
```

**Step 2: Add `update_avatar` action in auth.php**

```php
case 'update_avatar':
    require_login();
    $avatar = trim($body['avatar'] ?? '');
    // Validate it's a data URL or empty
    if ($avatar && !str_starts_with($avatar, 'data:image/')) json_error('Formato inválido');
    $user_id = current_user()['id'];
    db()->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatar ?: null, $user_id]);
    json_ok([]);
    break;
```

**Step 3: Create avatar helper function in layout_top.php**

Add inline in `layout_top.php` (PHP side), create a helper:
```php
<?php
function user_avatar(array $user, int $size = 28): string {
    $initials = htmlspecialchars(mb_substr($user['name'] ?? '?', 0, 1));
    if (!empty($user['avatar'])) {
        return '<img src="' . htmlspecialchars($user['avatar']) . '"
            style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;vertical-align:middle;"
            title="' . htmlspecialchars($user['name']) . '">';
    }
    return '<span style="display:inline-flex;align-items:center;justify-content:center;
        width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;
        background:#4f46e5;color:#fff;font-size:' . round($size*0.45) . 'px;font-weight:700;
        vertical-align:middle;" title="' . htmlspecialchars($user['name']) . '">' . $initials . '</span>';
}
?>
```

**Step 4: Show avatar in sidebar user area**

In `layout_top.php`, sidebar user section:
```html
<!-- Before: -->
<span><?= htmlspecialchars(current_user()['name']) ?></span>

<!-- After: -->
<span style="display:flex;align-items:center;gap:0.5rem;">
    <?= user_avatar(current_user(), 28) ?>
    <?= htmlspecialchars(current_user()['name']) ?>
</span>
```

**Step 5: Show avatars in team.php member list**

In team.php member rendering, include `avatar` in the member data and show the avatar circle.

**Step 6: Verify**
- Profile has avatar upload — upload a photo → see it in sidebar
- Team page shows avatar circles next to member names

**Step 7: Commit**
```bash
git add app/pages/profile.php app/api/auth.php app/includes/layout_top.php app/pages/team.php app/assets/css/main.css
git commit -m "feat: avatar upload in profile, display in sidebar and team list"
```

---

## Task 13: @mentions in comments

**Files:**
- Modify: `app/pages/issues.php` (comment textarea + render)
- Modify: `app/assets/css/main.css`

**Context:**
When typing `@` in a comment textarea, show a dropdown of team members. Selected member's name is inserted as `@nombre`. On render, `@nombre` is highlighted.

**Step 1: Add autocomplete dropdown HTML**

In `issues.php`, after the comment textarea:
```html
<div id="mention-dropdown" class="mention-dropdown hidden"></div>
```

**Step 2: Add mention logic in issues.php JS**

```js
let teamMembersCache = [];

async function loadTeamForMentions() {
    const res  = await fetch(`${APP_URL}/app/api/team.php?action=members`);
    const data = await res.json();
    teamMembersCache = data.data || [];
}

function initMentionAutocomplete(textarea) {
    const dropdown = document.getElementById('mention-dropdown');
    textarea.addEventListener('input', () => {
        const val    = textarea.value;
        const cursor = textarea.selectionStart;
        // Find @word before cursor
        const before = val.slice(0, cursor);
        const match  = before.match(/@(\w*)$/);
        if (!match) { dropdown.classList.add('hidden'); return; }
        const query   = match[1].toLowerCase();
        const members = teamMembersCache.filter(m => m.name.toLowerCase().includes(query)).slice(0, 5);
        if (!members.length) { dropdown.classList.add('hidden'); return; }
        dropdown.innerHTML = members.map(m =>
            `<div class="mention-item" data-name="${escapeHtml(m.name)}">${escapeHtml(m.name)}</div>`
        ).join('');
        dropdown.classList.remove('hidden');
        // Position dropdown near textarea
        const rect = textarea.getBoundingClientRect();
        dropdown.style.top  = (rect.bottom + window.scrollY) + 'px';
        dropdown.style.left = rect.left + 'px';
        dropdown.style.width = rect.width + 'px';
    });
    dropdown.addEventListener('click', e => {
        const item = e.target.closest('.mention-item');
        if (!item) return;
        const name = item.dataset.name;
        const val  = textarea.value;
        const cursor = textarea.selectionStart;
        const before = val.slice(0, cursor);
        const after  = val.slice(cursor);
        const replaced = before.replace(/@\w*$/, '@' + name + ' ');
        textarea.value = replaced + after;
        textarea.selectionStart = textarea.selectionEnd = replaced.length;
        dropdown.classList.add('hidden');
        textarea.focus();
    });
    document.addEventListener('click', e => {
        if (!dropdown.contains(e.target) && e.target !== textarea) {
            dropdown.classList.add('hidden');
        }
    });
}
```

Call `loadTeamForMentions()` at init and `initMentionAutocomplete(textarea)` when the comment textarea is created.

**Step 3: Highlight @mentions on render**

In `renderComments()`, when rendering comment content:
```js
function highlightMentions(text) {
    return escapeHtml(text).replace(/@(\w+)/g,
        '<span style="color:#4f46e5;font-weight:600;background:#eef2ff;border-radius:3px;padding:0 2px;">@$1</span>');
}
```

Use `highlightMentions(c.content)` instead of `escapeHtml(c.content)`.

**Step 4: Add CSS in main.css**

```css
.mention-dropdown {
    position: fixed;
    z-index: 600;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    overflow: hidden;
}
.mention-item {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    cursor: pointer;
}
.mention-item:hover {
    background: #f3f4f6;
}
```

**Step 5: Verify**
- Type `@car` in comment → dropdown shows matching members
- Click member → `@Carlos ` inserted in textarea
- Saved comment renders with `@Carlos` highlighted in indigo

**Step 6: Commit**
```bash
git add app/pages/issues.php app/assets/css/main.css
git commit -m "feat: @mention autocomplete in comments with highlight on render"
```

---

## Task 14: Activity feed

**Files:**
- Create: `db/migrations/add_activity_log.sql`
- Create: `app/api/activity.php`
- Modify: `app/api/issues.php` (log on create/update/delete)
- Modify: `app/api/comments.php` (log on create)
- Modify: `app/api/pages.php` (log on create/update)
- Modify: `app/api/dashboard.php` (include activity in dashboard data)
- Modify: `app/assets/js/dashboard.js` (render activity feed)
- Modify: `app/pages/dashboard.php` (activity card)

**Context:**
Add an `activity_log` table and a dashboard widget showing the last 20 actions across issues, comments, and wiki pages.

**Step 1: Create migration**

`db/migrations/add_activity_log.sql`:
```sql
CREATE TABLE IF NOT EXISTS activity_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id    INT NOT NULL,
    action     VARCHAR(50) NOT NULL,  -- 'issue_created', 'issue_updated', 'comment_added', 'page_created', etc.
    entity_type VARCHAR(20) NOT NULL, -- 'issue', 'comment', 'page'
    entity_id  INT NOT NULL,
    entity_title VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_created (project_id, created_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Step 2: Run migration**
```bash
mysql -u root teamapp < db/migrations/add_activity_log.sql
```

**Step 3: Create activity.php helper**

`app/api/activity.php`:
```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

function log_activity(int $project_id, int $user_id, string $action, string $entity_type, int $entity_id, ?string $entity_title = null): void {
    try {
        db()->prepare(
            "INSERT INTO activity_log (project_id, user_id, action, entity_type, entity_id, entity_title) VALUES (?,?,?,?,?,?)"
        )->execute([$project_id, $user_id, $action, $entity_type, $entity_id, $entity_title]);
    } catch (Exception $e) {
        // Non-fatal: don't let logging break the main operation
    }
}

function get_recent_activity(int $project_id, int $limit = 20): array {
    return db()->query(
        "SELECT al.*, u.name AS user_name
         FROM activity_log al
         JOIN users u ON al.user_id = u.id
         WHERE al.project_id = $project_id
         ORDER BY al.created_at DESC
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);
}
```

**Step 4: Log in issues.php**

In `issues.php` API, after `create_issue()` success:
```php
require_once __DIR__ . '/activity.php';
log_activity($project_id, current_user()['id'], 'issue_created', 'issue', $id, $title);
```

After `update_issue()` success (on status change especially):
```php
log_activity($project_id, current_user()['id'], 'issue_updated', 'issue', $id, $title);
```

**Step 5: Log in comments.php**

After `create_comment()` success: fetch issue's project_id and title, then log.

**Step 6: Update dashboard.php API to include activity**

In `get_dashboard_data()`, add:
```php
'activity' => get_recent_activity($project_id, 20),
```

**Step 7: Add activity card in dashboard.php**

```html
<div class="card" style="padding:1.25rem;grid-column:1/-1;">
    <h4 style="margin:0 0 0.75rem;font-size:0.875rem;color:#374151;">Actividad reciente</h4>
    <div id="activity-feed"></div>
</div>
```

**Step 8: Render activity feed in dashboard.js**

```js
const ACTIVITY_ICONS = {
    issue_created: '✨', issue_updated: '✏️', issue_deleted: '🗑️',
    comment_added: '💬', page_created: '📄', page_updated: '📝'
};
const feedEl = document.getElementById('activity-feed');
if (!d.activity?.length) {
    feedEl.innerHTML = '<em style="color:#aaa;font-size:0.875rem;">Sin actividad reciente</em>';
} else {
    feedEl.innerHTML = d.activity.map(a =>
        `<div style="display:flex;gap:0.5rem;padding:0.35rem 0;border-bottom:1px solid #f3f4f6;align-items:flex-start;">
            <span style="font-size:1rem;flex-shrink:0;">${ACTIVITY_ICONS[a.action] || '•'}</span>
            <div style="flex:1;min-width:0;">
                <span style="font-size:0.875rem;">${escapeHtml(a.user_name)}</span>
                <span style="font-size:0.8rem;color:#6b7280;"> ${escapeHtml(a.action.replace('_',' '))} </span>
                <span style="font-size:0.875rem;font-weight:500;">${escapeHtml(a.entity_title || '#' + a.entity_id)}</span>
            </div>
            <span style="font-size:0.75rem;color:#9ca3af;flex-shrink:0;">${timeAgo(a.created_at)}</span>
        </div>`
    ).join('');
}
```

**Step 9: Verify**
- Create an issue → activity log entry created
- Dashboard shows activity feed with relative timestamps

**Step 10: Commit**
```bash
git add db/migrations/add_activity_log.sql app/api/activity.php app/api/issues.php app/api/comments.php app/api/dashboard.php app/assets/js/dashboard.js app/pages/dashboard.php
git commit -m "feat: activity feed — log table, API logging, dashboard widget"
```

---

## Task 15: Dark mode

**Files:**
- Modify: `app/assets/css/main.css`
- Modify: `app/includes/layout_top.php` (toggle button + persistence)

**Context:**
Refactor CSS to use CSS custom properties. Add a dark mode toggle button in the sidebar. Persist preference in localStorage.

**Step 1: Refactor main.css to use CSS variables**

At the top of `main.css`, add a `:root` block with light mode defaults, and a `[data-theme="dark"]` override:

```css
:root {
    --bg-main: #f9fafb;
    --bg-card: #ffffff;
    --bg-sidebar: #1e1e3f;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --text-sidebar: #c7c7d4;
    --border: #e5e7eb;
    --border-sidebar: #2d2d5e;
    --input-bg: #ffffff;
    --input-border: #d1d5db;
    --hover-bg: #f3f4f6;
    --card-shadow: 0 1px 3px rgba(0,0,0,0.08);
    --modal-overlay: rgba(0,0,0,0.5);
}

[data-theme="dark"] {
    --bg-main: #111827;
    --bg-card: #1f2937;
    --bg-sidebar: #0f172a;
    --text-primary: #f9fafb;
    --text-secondary: #9ca3af;
    --text-sidebar: #d1d5db;
    --border: #374151;
    --border-sidebar: #1e293b;
    --input-bg: #1f2937;
    --input-border: #374151;
    --hover-bg: #374151;
    --card-shadow: 0 1px 3px rgba(0,0,0,0.3);
    --modal-overlay: rgba(0,0,0,0.7);
}
```

Replace hardcoded colors in CSS with variables where appropriate (main background, card backgrounds, borders, text colors).

**Step 2: Add dark mode toggle button in layout_top.php**

In the sidebar, after the `<div class="sidebar-logo">WINOJI</div>`, add:
```html
<button id="theme-toggle" style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;cursor:pointer;font-size:1.1rem;color:#c7c7d4;" title="Cambiar tema">&#9790;</button>
```

Add JS in layout_top.php:
```html
<script>
(function() {
    const saved = localStorage.getItem('theme') || 'light';
    if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');

    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('theme-toggle');
        if (!btn) return;
        const update = () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            btn.innerHTML = isDark ? '&#9728;' : '&#9790;';
        };
        update();
        btn.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            }
            update();
        });
    });
})();
</script>
```

**Step 3: Verify**
- Page loads in saved theme (dark/light)
- Toggle button switches theme and persists across page loads
- Dark mode: cards have dark background, text is light

**Step 4: Commit**
```bash
git add app/assets/css/main.css app/includes/layout_top.php
git commit -m "feat: dark mode with CSS variables, toggle persisted in localStorage"
```

---

## Task 16: Timeline / Roadmap view

**Files:**
- Create: `app/pages/roadmap.php`
- Modify: `app/includes/layout_top.php` (add nav link)
- Modify: `app/assets/css/main.css`

**Context:**
A horizontal timeline showing issues with `due_date` set, grouped by month. Issues without a due date are shown in a separate "sin fecha" section. Clicking an issue opens its detail (deep-link to issues page).

**Step 1: Create roadmap.php**

```php
<?php
$page_title = 'Roadmap';
require __DIR__ . '/../includes/layout_top.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
    <h2 style="margin:0;">Roadmap</h2>
    <span style="font-size:0.8rem;color:#9ca3af;">Issues con fecha límite</span>
</div>
<div id="roadmap-container">
    <div style="color:#9ca3af;padding:2rem;text-align:center;">Cargando...</div>
</div>

<script>
(async function() {
    const pid = parseInt(localStorage.getItem('active_project_id') || '0');
    if (!pid) {
        document.getElementById('roadmap-container').innerHTML =
            '<div style="padding:2rem;color:#9ca3af;text-align:center;">Selecciona un proyecto para ver el roadmap.</div>';
        return;
    }
    const res  = await fetch(`${APP_URL}/app/api/issues.php?action=list&project_id=${pid}&per_page=500`);
    const data = await res.json();
    const all  = data.items || [];

    const withDate    = all.filter(i => i.due_date && i.status !== 'done').sort((a, b) => a.due_date.localeCompare(b.due_date));
    const withoutDate = all.filter(i => !i.due_date && i.status !== 'done');

    // Group by month
    const byMonth = {};
    withDate.forEach(i => {
        const month = i.due_date.slice(0, 7); // YYYY-MM
        if (!byMonth[month]) byMonth[month] = [];
        byMonth[month].push(i);
    });

    const PRIORITY_COLOR = { critical:'#7c3aed', high:'#dc2626', medium:'#d97706', low:'#16a34a' };
    const STATUS_LABEL   = { todo:'Pendiente', in_progress:'En curso', review:'Revisión', done:'Hecho' };
    const now = new Date().toISOString().slice(0, 10);

    let html = '<div class="roadmap-timeline">';
    Object.entries(byMonth).forEach(([month, issues]) => {
        const [y, m] = month.split('-');
        const monthName = new Date(y, m - 1, 1).toLocaleDateString('es', { month: 'long', year: 'numeric' });
        html += `<div class="roadmap-month">
            <div class="roadmap-month-label">${monthName}</div>
            <div class="roadmap-items">`;
        issues.forEach(i => {
            const overdue   = i.due_date < now ? 'roadmap-overdue' : '';
            const typeChip  = i.type_name
                ? `<span style="font-size:0.65rem;color:${escapeHtml(i.type_color||'#6b7280')};">${escapeHtml(i.type_name)}</span>`
                : '';
            html += `<a class="roadmap-card ${overdue}" href="${APP_URL}?page=issues&open_issue=${i.id}">
                <div class="roadmap-card-title">${escapeHtml(i.title)}</div>
                <div class="roadmap-card-meta">
                    ${typeChip}
                    <span class="badge badge-${i.priority}" style="font-size:0.65rem;">${i.priority}</span>
                    <span style="font-size:0.7rem;color:#9ca3af;">${i.due_date}</span>
                </div>
            </a>`;
        });
        html += '</div></div>';
    });
    html += '</div>';

    if (withoutDate.length) {
        html += `<div style="margin-top:1.5rem;">
            <h4 style="color:#9ca3af;font-size:0.875rem;margin-bottom:0.75rem;">Sin fecha límite (${withoutDate.length})</h4>
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">`;
        withoutDate.forEach(i => {
            html += `<a class="roadmap-card-small" href="${APP_URL}?page=issues&open_issue=${i.id}">
                #${i.id} ${escapeHtml(i.title)}
            </a>`;
        });
        html += '</div></div>';
    }

    if (!withDate.length && !withoutDate.length) {
        html = '<div style="padding:2rem;color:#9ca3af;text-align:center;">Sin issues pendientes en este proyecto.</div>';
    }

    document.getElementById('roadmap-container').innerHTML = html;
})();

function escapeHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
```

**Step 2: Add CSS in main.css**

```css
.roadmap-timeline {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    padding-bottom: 1rem;
    align-items: flex-start;
}
.roadmap-month {
    min-width: 200px;
    flex-shrink: 0;
}
.roadmap-month-label {
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #6b7280;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
    padding-bottom: 0.3rem;
    border-bottom: 2px solid #4f46e5;
}
.roadmap-items {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.roadmap-card {
    display: block;
    padding: 0.6rem 0.75rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 6px;
    text-decoration: none;
    color: var(--text-primary);
    transition: box-shadow 0.15s;
}
.roadmap-card:hover {
    box-shadow: 0 2px 8px rgba(79,70,229,0.15);
    border-color: #4f46e5;
}
.roadmap-card.roadmap-overdue {
    border-left: 3px solid #dc2626;
}
.roadmap-card-title {
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 0.3rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.roadmap-card-meta {
    display: flex;
    gap: 0.3rem;
    align-items: center;
    flex-wrap: wrap;
}
.roadmap-card-small {
    display: inline-block;
    padding: 0.3rem 0.6rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 0.8rem;
    text-decoration: none;
    color: var(--text-secondary);
    white-space: nowrap;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.roadmap-card-small:hover { border-color: #4f46e5; color: #4f46e5; }
```

**Step 3: Add "Roadmap" to sidebar nav**

In `layout_top.php`, add to the `<ul>`:
```html
<li><a href="<?= APP_URL ?>?page=roadmap">Roadmap</a></li>
```

**Step 4: Add route in router (public/index.php)**

In `index.php`, add:
```php
'roadmap' => 'app/pages/roadmap.php',
```

**Step 5: Verify**
- Sidebar shows "Roadmap" link
- Roadmap page loads with issues grouped by month
- Overdue issues have red left border
- Clicking an issue navigates to issues page with that issue open

**Step 6: Commit**
```bash
git add app/pages/roadmap.php app/assets/css/main.css app/includes/layout_top.php public/index.php
git commit -m "feat: roadmap/timeline view — issues grouped by month, overdue indicator"
```

---

## Summary

17 tasks total:
1. Fix PROJECT_ID hardcoded fallback
2. Sidebar active page indicator
3. Kanban improvements (type badge, column counter, drag feedback)
4. Relative dates helper
5. Dashboard general refresh button
6. Edit labels
7. Edit comments
8. Story points
9. Export issues CSV
10. Search result highlighting
11. Keyboard shortcuts
12. Avatar support
13. @mentions in comments
14. Activity feed
15. Dark mode
16. Timeline/Roadmap view
