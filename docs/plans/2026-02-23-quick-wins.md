# Quick Wins Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add server-side pagination to the notifications page and a 30-day burndown chart to the dashboard.

**Architecture:** Two independent features. Pagination: API returns `{data, total, page, per_page}`, page renders Anterior/Siguiente controls. Burndown: new `?action=burndown` endpoint queries `issue_status_log`, JS renders an inline SVG bar chart with tooltip—no external libraries.

**Tech Stack:** PHP 8.x vanilla · MySQL 8.x · Vanilla JS · Inline SVG

---

### Task 1: Notifications — server-side pagination

**Files:**
- Modify: `app/api/notifications.php:17-28`
- Modify: `app/pages/notifications.php:37-111`

**Step 1: Update API list action with pagination**

In `app/api/notifications.php`, replace the `list` arm of the `match` (lines 17-28):

Old:
```php
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
```

New:
```php
$method === 'GET' && $action === 'list' => (function() use ($uid) {
    $page     = max(1, (int)($_GET['page']     ?? 1));
    $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset   = ($page - 1) * $per_page;

    $cnt = get_db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $cnt->execute([$uid]);
    $total = (int)$cnt->fetchColumn();

    $rows = get_db()->prepare(
        "SELECT n.*, u.name AS actor_name, u.avatar AS actor_avatar
         FROM notifications n
         JOIN users u ON n.actor_id = u.id
         WHERE n.user_id = ?
         ORDER BY n.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $rows->execute([$uid, $per_page, $offset]);
    print json_encode([
        'success'  => true,
        'data'     => $rows->fetchAll(PDO::FETCH_ASSOC),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ]);
})(),
```

**Step 2: Verify PHP syntax**

Run: `php -l app/api/notifications.php`
Expected: `No syntax errors detected`

**Step 3: Update page JS — state vars + loadNotifications()**

In `app/pages/notifications.php`, replace lines 37-52 (state vars + loadNotifications):

Old:
```js
let allNotifs = [];
let currentFilter = 'all';

...

async function loadNotifications() {
    const res  = await fetch(`${APP_URL}/app/api/notifications.php?action=list`);
    const data = await res.json();
    allNotifs = data.data || [];
    render();
}
```

New:
```js
let allNotifs     = [];
let currentFilter = 'all';
let currentPage   = 1;
let totalNotifs   = 0;
const PER_PAGE    = 20;

async function loadNotifications(page = 1) {
    currentPage = page;
    const res   = await fetch(`${APP_URL}/app/api/notifications.php?action=list&page=${page}&per_page=${PER_PAGE}`);
    const data  = await res.json();
    allNotifs   = data.data  || [];
    totalNotifs = data.total || 0;
    render();
}
```

**Step 4: Replace render() to call renderPagination(), add renderPagination()**

In `app/pages/notifications.php`, replace the entire `render()` function (lines 54-95) with the version below, and add `renderPagination()` immediately after it:

```js
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
        renderPagination();
        return;
    }

    list.innerHTML = items.map(n => {
        const icon     = NOTIF_ICONS[n.type]  || '•';
        const label    = NOTIF_LABELS[n.type] || n.type.replace(/_/g, ' ');
        const url      = (NOTIF_URLS[n.entity_type] || (() => APP_URL))(n.entity_id);
        const unreadBg = !n.read_at ? 'background:rgba(79,70,229,0.06);' : '';
        const timeStr  = typeof timeAgo === 'function' ? timeAgo(n.created_at) : new Date(n.created_at).toLocaleString('es');
        return `<a class="notif-page-row" href="${escapeHtml(url)}"
            data-id="${n.id}" data-read="${n.read_at ? '1' : '0'}"
            style="display:flex;gap:0.75rem;padding:0.85rem 1.25rem;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text-primary);${unreadBg}transition:background 0.1s;">
            <span style="font-size:1.1rem;flex-shrink:0;">${escapeHtml(icon)}</span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.875rem;line-height:1.4;">
                    <strong>${escapeHtml(n.actor_name)}</strong> ${escapeHtml(label)}
                    <em>${escapeHtml(n.entity_title || '#' + n.entity_id)}</em>
                </div>
                <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.2rem;">${timeStr}</div>
            </div>
            ${!n.read_at ? '<span style="width:8px;height:8px;border-radius:50%;background:#4f46e5;flex-shrink:0;margin-top:0.35rem;"></span>' : ''}
        </a>`;
    }).join('');

    list.querySelectorAll('.notif-page-row[data-read="0"]').forEach(el => {
        el.addEventListener('click', async () => {
            await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_read`, { id: parseInt(el.dataset.id) });
        });
    });

    renderPagination();
}

function renderPagination() {
    const totalPages = Math.ceil(totalNotifs / PER_PAGE);
    let pager = document.getElementById('notif-pagination');
    if (!pager) {
        pager = document.createElement('div');
        pager.id = 'notif-pagination';
        pager.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;border-top:1px solid var(--border);';
        document.getElementById('notif-page-list').after(pager);
    }
    if (totalPages <= 1) { pager.style.display = 'none'; return; }
    pager.style.display = 'flex';
    pager.innerHTML = `
        <button class="btn btn-secondary" style="font-size:0.8rem;${currentPage <= 1 ? 'opacity:0.4;pointer-events:none;' : ''}"
            onclick="loadNotifications(${currentPage - 1})">&#8592; Anterior</button>
        <span style="font-size:0.8rem;color:var(--text-secondary);">Página ${currentPage} de ${totalPages} · ${totalNotifs} total</span>
        <button class="btn btn-secondary" style="font-size:0.8rem;${currentPage >= totalPages ? 'opacity:0.4;pointer-events:none;' : ''}"
            onclick="loadNotifications(${currentPage + 1})">Siguiente &#8594;</button>`;
}
```

**Step 5: Update mark-all-read to reload from page 1**

In `app/pages/notifications.php`, replace the mark-all-read handler (around line 97-101):

Old:
```js
document.getElementById('mark-all-btn').addEventListener('click', async () => {
    await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_all_read`, {});
    allNotifs = allNotifs.map(n => ({ ...n, read_at: new Date().toISOString() }));
    render();
});
```

New:
```js
document.getElementById('mark-all-btn').addEventListener('click', async () => {
    await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_all_read`, {});
    await loadNotifications(1);
});
```

**Step 6: Commit**

```bash
git add app/api/notifications.php app/pages/notifications.php
git commit -m "feat: server-side pagination in notifications (20/page, Anterior/Siguiente)"
```

---

### Task 2: Dashboard — 30-day burndown chart

**Files:**
- Modify: `app/api/dashboard.php` (add `get_burndown_data()` function + action routing)
- Modify: `app/pages/dashboard.php:47-48` (insert burndown card between stat cards and priority bar)
- Modify: `app/assets/js/dashboard.js` (add `loadBurndown()`, call it from `loadDashboard()`)

**Step 1: Add get_burndown_data() to app/api/dashboard.php**

Insert the following function immediately before line 99 (`if (php_sapi_name() !== 'cli') {`):

```php
function get_burndown_data(int $project_id): array {
    $pdo = get_db();

    $stmt = $pdo->prepare(
        "SELECT DATE(sl.changed_at) AS day, COALESCE(SUM(i.story_points), 0) AS points
         FROM issue_status_log sl
         JOIN issues i ON sl.issue_id = i.id
         WHERE i.project_id = ?
           AND sl.new_status = 'done'
           AND sl.changed_at >= CURDATE() - INTERVAL 29 DAY
         GROUP BY DATE(sl.changed_at)
         ORDER BY day ASC"
    );
    $stmt->execute([$project_id]);
    $byDay = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDay[$row['day']] = (int)$row['points'];
    }

    // Fill all 30 days, oldest first
    $result = [];
    for ($i = 29; $i >= 0; $i--) {
        $day      = date('Y-m-d', strtotime("-{$i} days"));
        $result[] = ['day' => $day, 'points' => $byDay[$day] ?? 0];
    }
    return $result;
}
```

**Step 2: Add action routing to dashboard.php HTTP section**

Replace lines 99-108:

Old:
```php
if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $user = current_user();
    $project_id = (int)($_GET['project_id'] ?? 0);
    if (!$project_id) { echo json_encode(['success' => false, 'error' => 'project_id required']); exit; }
    require_project_access($project_id);
    echo json_encode(['success' => true, 'data' => get_dashboard_stats($user['id'], $project_id)]);
    exit;
}
```

New:
```php
if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $user       = current_user();
    $project_id = (int)($_GET['project_id'] ?? 0);
    $action     = $_GET['action'] ?? 'summary';
    if (!$project_id) { echo json_encode(['success' => false, 'error' => 'project_id required']); exit; }
    require_project_access($project_id);
    if ($action === 'burndown') {
        echo json_encode(['success' => true, 'data' => get_burndown_data($project_id)]);
    } else {
        echo json_encode(['success' => true, 'data' => get_dashboard_stats($user['id'], $project_id)]);
    }
    exit;
}
```

**Step 3: Verify PHP syntax**

Run: `php -l app/api/dashboard.php`
Expected: `No syntax errors detected`

**Step 4: Add burndown card HTML to app/pages/dashboard.php**

Insert after line 47 (after the closing `</div>` of the stat cards grid, before `<!-- Priority bar -->`):

```html
<!-- Burndown chart -->
<div class="card" style="padding:1rem;margin-bottom:1.5rem;position:relative;">
    <h4 style="margin:0 0 0.75rem;font-size:0.95rem;color:#374151;">&#128200; Story points cerrados (últimos 30 días)</h4>
    <div id="burndown-chart" style="height:180px;position:relative;"></div>
</div>
```

**Step 5: Add loadBurndown() to app/assets/js/dashboard.js**

Append at the very end of `app/assets/js/dashboard.js` (after the refresh button IIFE):

```js
async function loadBurndown(projectId) {
    const container = document.getElementById('burndown-chart');
    if (!container) return;
    let res, data;
    try {
        res  = await fetch(`${APP_URL}/app/api/dashboard.php?action=burndown&project_id=${projectId}`);
        data = await res.json();
    } catch(e) {
        container.innerHTML = '<em style="color:#9ca3af;font-size:0.875rem;">Error al cargar</em>';
        return;
    }
    if (!data.success) {
        container.innerHTML = '<em style="color:#9ca3af;font-size:0.875rem;">Sin datos</em>';
        return;
    }

    const entries = data.data; // [{day, points}, ...] — always 30 items
    const maxPts  = Math.max(...entries.map(e => e.points), 1);
    const W        = Math.max(container.clientWidth || 600, 300);
    const H        = 160;
    const PAD_L    = 32;
    const PAD_B    = 28;
    const PAD_T    = 8;
    const chartW   = W - PAD_L;
    const chartH   = H - PAD_B - PAD_T;
    const barW     = Math.floor(chartW / entries.length);
    const barGap   = Math.max(1, Math.floor(barW * 0.15));
    const yMax     = maxPts <= 5 ? maxPts + 1 : Math.ceil(maxPts / 5) * 5;

    let bars = '', xLabels = '', hits = '';

    entries.forEach((e, i) => {
        const bH = Math.round((e.points / yMax) * chartH);
        const x  = PAD_L + i * barW + barGap / 2;
        const y  = PAD_T + chartH - bH;
        const bW = barW - barGap;
        bars += `<rect x="${x}" y="${y}" width="${bW}" height="${bH}" fill="#4f46e5" rx="2" opacity="0.85"/>`;
        if (i % 5 === 0) {
            const [, mm, dd] = e.day.split('-');
            xLabels += `<text x="${x + bW / 2}" y="${H - 6}" text-anchor="middle" font-size="9" fill="var(--text-secondary)">${dd}/${mm}</text>`;
        }
        hits += `<rect x="${x}" y="${PAD_T}" width="${bW}" height="${chartH}" fill="transparent"
            data-day="${escapeHtml(e.day)}" data-pts="${e.points}" class="bd-hit"/>`;
    });

    const axes = `
        <text x="${PAD_L - 4}" y="${PAD_T + chartH}" text-anchor="end" font-size="9" fill="var(--text-secondary)">0</text>
        <text x="${PAD_L - 4}" y="${PAD_T + 8}"      text-anchor="end" font-size="9" fill="var(--text-secondary)">${yMax}</text>
        <line x1="${PAD_L}" y1="${PAD_T}"           x2="${PAD_L}" y2="${PAD_T + chartH}" stroke="var(--border)" stroke-width="1"/>
        <line x1="${PAD_L}" y1="${PAD_T + chartH}"  x2="${W}"     y2="${PAD_T + chartH}" stroke="var(--border)" stroke-width="1"/>`;

    container.innerHTML = `
        <div id="bd-tip" style="display:none;position:absolute;background:var(--bg-card);border:1px solid var(--border);
            border-radius:6px;padding:0.3rem 0.6rem;font-size:0.8rem;pointer-events:none;z-index:50;
            box-shadow:0 2px 8px rgba(0,0,0,0.12);"></div>
        <svg width="100%" height="${H}" viewBox="0 0 ${W} ${H}" style="overflow:visible;">
            ${axes}${bars}${xLabels}${hits}
        </svg>`;

    const tip = container.querySelector('#bd-tip');
    container.querySelectorAll('.bd-hit').forEach(el => {
        el.addEventListener('mouseenter', () => {
            const [, mm, dd] = el.dataset.day.split('-');
            tip.textContent  = `${dd}/${mm}: ${el.dataset.pts} pts`;
            tip.style.display = 'block';
        });
        el.addEventListener('mousemove', ev => {
            const r = container.getBoundingClientRect();
            tip.style.left = (ev.clientX - r.left + 12) + 'px';
            tip.style.top  = (ev.clientY - r.top  - 32) + 'px';
        });
        el.addEventListener('mouseleave', () => { tip.style.display = 'none'; });
    });
}
```

**Step 6: Call loadBurndown() from loadDashboard()**

In `app/assets/js/dashboard.js`, find the closing `}` of `loadDashboard()` (line 163). Insert just before it:

```js
    // Burndown chart (independent fetch)
    loadBurndown(projectId);
```

The end of `loadDashboard()` should look like:

```js
    // ...recent issues block...
    }

    // Burndown chart (independent fetch)
    loadBurndown(projectId);
}

loadDashboard();
```

**Step 7: Commit**

```bash
git add app/api/dashboard.php app/pages/dashboard.php app/assets/js/dashboard.js
git commit -m "feat: 30-day burndown chart in dashboard (story points closed per day, inline SVG)"
```

---

## After both tasks

Merge to master so changes are testable in Laragon:

```bash
git log --oneline -4
```

Expected output: two new commits on top of `26f0fef`.
