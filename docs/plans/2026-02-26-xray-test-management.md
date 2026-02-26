# Xray Test Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow each issue to have multiple manual test cases with ordered steps, assignable to any team member, executable multiple times with full step-by-step history.

**Architecture:** New API file `app/api/tests.php` handles all test CRUD and execution. The full issue view left column gets a 3-tab layout (Descripción | Tests | Comentarios). Three new modals handle create/edit, step-by-step execution, and read-only execution history viewing. Four new DB tables store test cases, steps, executions and per-step execution results.

**Tech Stack:** PHP 8.x, MySQL 8.0, Vanilla JS, existing PDO singleton (`get_db()`), existing auth helpers (`require_auth()`, `verify_csrf()`, `current_user()`), existing CSS classes (`wiki-tabs`, `wiki-tab`, `modal`, `modal-box`, `card`, `form-*`, `btn-*`)

**Note:** No automated test suite in this project — skip TDD steps. Verify manually in browser at `http://localhost/teamapp/public`.

---

## Task 1: DB Migration — 4 New Tables

**Files:**
- Modify: `db/schema.sql` (append 4 tables at the end)
- Create: `db/migrations/add_test_management.sql`

**Step 1: Create migration file**

Create `db/migrations/add_test_management.sql` with this exact content:

```sql
-- Test management (Xray-style)
CREATE TABLE IF NOT EXISTS test_cases (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    issue_id    INT NOT NULL,
    title       VARCHAR(255) NOT NULL,
    assignee_id INT DEFAULT NULL,
    created_by  INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issue_id)    REFERENCES issues(id)  ON DELETE CASCADE,
    FOREIGN KEY (assignee_id) REFERENCES users(id)   ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id)   ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_steps (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    test_case_id    INT NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    action          TEXT NOT NULL,
    expected_result TEXT DEFAULT NULL,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_executions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    test_case_id INT NOT NULL,
    executed_by  INT NOT NULL,
    result       ENUM('pass','fail') NOT NULL,
    executed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (executed_by)  REFERENCES users(id)      ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_execution_steps (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    step_id      INT NOT NULL,
    result       ENUM('pass','fail','skip') NOT NULL,
    comment      TEXT DEFAULT NULL,
    FOREIGN KEY (execution_id) REFERENCES test_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id)      REFERENCES test_steps(id)      ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**Step 2: Run migration**

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp < db/migrations/add_test_management.sql
```

Expected: no output (success). If error, check that `issues` and `users` tables exist.

**Step 3: Append same SQL to db/schema.sql**

Add the 4 CREATE TABLE statements from step 1 at the end of `db/schema.sql` (after the `sonarqube_projects` table).

**Step 4: Commit**

```bash
git add db/migrations/add_test_management.sql db/schema.sql
git commit -m "feat: test management DB tables (test_cases, test_steps, test_executions, test_execution_steps)"
```

---

## Task 2: API — app/api/tests.php

**Files:**
- Create: `app/api/tests.php`

**Step 1: Create the file**

Create `app/api/tests.php` with this exact content:

```php
<?php
require_once __DIR__ . '/../bootstrap.php';
ini_set('display_errors', '0');

require_auth();
header('Content-Type: application/json');

$pdo    = get_db();
$user   = current_user();
$uid    = (int)$user['id'];
$action = $_GET['action'] ?? '';

// ── list: GET ?action=list&issue_id=N ────────────────────────────────────────
if ($action === 'list') {
    $issue_id = (int)($_GET['issue_id'] ?? 0);
    if (!$issue_id) { echo json_encode(['success' => false, 'error' => 'issue_id requerido']); exit; }

    $stmt = $pdo->prepare("
        SELECT tc.*,
               u.name   AS assignee_name,
               u.avatar AS assignee_avatar,
               cu.name  AS creator_name,
               (SELECT te.result
                FROM test_executions te
                WHERE te.test_case_id = tc.id
                ORDER BY te.executed_at DESC LIMIT 1) AS last_result,
               (SELECT te.executed_at
                FROM test_executions te
                WHERE te.test_case_id = tc.id
                ORDER BY te.executed_at DESC LIMIT 1) AS last_executed_at,
               (SELECT COUNT(*)
                FROM test_executions te
                WHERE te.test_case_id = tc.id)        AS execution_count
        FROM test_cases tc
        LEFT JOIN users u  ON u.id  = tc.assignee_id
        LEFT JOIN users cu ON cu.id = tc.created_by
        WHERE tc.issue_id = ?
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$issue_id]);
    $cases = $stmt->fetchAll();

    foreach ($cases as &$tc) {
        $s = $pdo->prepare('SELECT * FROM test_steps WHERE test_case_id = ? ORDER BY sort_order ASC, id ASC');
        $s->execute([$tc['id']]);
        $tc['steps'] = $s->fetchAll();
    }
    unset($tc);

    echo json_encode(['success' => true, 'data' => $cases]);
    exit;
}

// ── create: POST ?action=create ──────────────────────────────────────────────
if ($action === 'create') {
    verify_csrf();
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id   = (int)($data['issue_id']   ?? 0);
    $title      = trim($data['title']       ?? '');
    $assignee   = !empty($data['assignee_id']) ? (int)$data['assignee_id'] : null;
    $steps      = $data['steps']            ?? [];

    if (!$issue_id || !$title) {
        echo json_encode(['success' => false, 'error' => 'issue_id y title son requeridos']); exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO test_cases (issue_id, title, assignee_id, created_by) VALUES (?,?,?,?)')
            ->execute([$issue_id, $title, $assignee, $uid]);
        $tc_id = (int)$pdo->lastInsertId();

        foreach ($steps as $i => $step) {
            $act = trim($step['action'] ?? '');
            $exp = trim($step['expected_result'] ?? '');
            if (!$act) continue;
            $pdo->prepare('INSERT INTO test_steps (test_case_id, sort_order, action, expected_result) VALUES (?,?,?,?)')
                ->execute([$tc_id, $i, $act, $exp ?: null]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $tc_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── update: POST ?action=update ──────────────────────────────────────────────
if ($action === 'update') {
    verify_csrf();
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($data['id']          ?? 0);
    $title    = trim($data['title']        ?? '');
    $assignee = !empty($data['assignee_id']) ? (int)$data['assignee_id'] : null;
    $steps    = $data['steps']             ?? [];

    if (!$id || !$title) {
        echo json_encode(['success' => false, 'error' => 'id y title son requeridos']); exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE test_cases SET title = ?, assignee_id = ? WHERE id = ?')
            ->execute([$title, $assignee, $id]);
        $pdo->prepare('DELETE FROM test_steps WHERE test_case_id = ?')->execute([$id]);

        foreach ($steps as $i => $step) {
            $act = trim($step['action'] ?? '');
            $exp = trim($step['expected_result'] ?? '');
            if (!$act) continue;
            $pdo->prepare('INSERT INTO test_steps (test_case_id, sort_order, action, expected_result) VALUES (?,?,?,?)')
                ->execute([$id, $i, $act, $exp ?: null]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── delete: POST ?action=delete ──────────────────────────────────────────────
if ($action === 'delete') {
    verify_csrf();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'id requerido']); exit; }
    $pdo->prepare('DELETE FROM test_cases WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── execute: POST ?action=execute ────────────────────────────────────────────
if ($action === 'execute') {
    verify_csrf();
    $data         = json_decode(file_get_contents('php://input'), true) ?? [];
    $test_case_id = (int)($data['test_case_id'] ?? 0);
    $step_results = $data['step_results']        ?? [];  // [{step_id, result, comment}]

    if (!$test_case_id || empty($step_results)) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos']); exit;
    }

    // PASS only if NO step is 'fail'
    $overall = 'pass';
    foreach ($step_results as $sr) {
        if (($sr['result'] ?? '') === 'fail') { $overall = 'fail'; break; }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO test_executions (test_case_id, executed_by, result) VALUES (?,?,?)')
            ->execute([$test_case_id, $uid, $overall]);
        $exec_id = (int)$pdo->lastInsertId();

        foreach ($step_results as $sr) {
            $step_id = (int)($sr['step_id'] ?? 0);
            $result  = in_array($sr['result'] ?? '', ['pass','fail','skip']) ? $sr['result'] : 'skip';
            $comment = trim($sr['comment'] ?? '');
            if (!$step_id) continue;
            $pdo->prepare('INSERT INTO test_execution_steps (execution_id, step_id, result, comment) VALUES (?,?,?,?)')
                ->execute([$exec_id, $step_id, $result, $comment ?: null]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'execution_id' => $exec_id, 'result' => $overall]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── executions: GET ?action=executions&test_case_id=N ────────────────────────
if ($action === 'executions') {
    $tc_id = (int)($_GET['test_case_id'] ?? 0);
    if (!$tc_id) { echo json_encode(['success' => false, 'error' => 'test_case_id requerido']); exit; }
    $stmt = $pdo->prepare('
        SELECT te.*, u.name AS executor_name, u.avatar AS executor_avatar
        FROM test_executions te
        JOIN users u ON u.id = te.executed_by
        WHERE te.test_case_id = ?
        ORDER BY te.executed_at DESC
    ');
    $stmt->execute([$tc_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

// ── execution_detail: GET ?action=execution_detail&execution_id=N ────────────
if ($action === 'execution_detail') {
    $exec_id = (int)($_GET['execution_id'] ?? 0);
    if (!$exec_id) { echo json_encode(['success' => false, 'error' => 'execution_id requerido']); exit; }
    $stmt = $pdo->prepare('
        SELECT tes.*, ts.action, ts.expected_result, ts.sort_order
        FROM test_execution_steps tes
        JOIN test_steps ts ON ts.id = tes.step_id
        WHERE tes.execution_id = ?
        ORDER BY ts.sort_order ASC, ts.id ASC
    ');
    $stmt->execute([$exec_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
```

**Step 2: Verify PHP syntax**

```bash
php -l app/api/tests.php
```

Expected: `No syntax errors detected in app/api/tests.php`

**Step 3: Quick smoke test in browser**

Open `http://localhost/teamapp/public/app/api/tests.php?action=list&issue_id=1` — should return `{"success":true,"data":[]}` (empty array, no tests yet).

**Step 4: Commit**

```bash
git add app/api/tests.php
git commit -m "feat: test management API (list, create, update, delete, execute, executions, execution_detail)"
```

---

## Task 3: HTML — Tabs + Tests Tab + 3 Modals in issues.php

**Files:**
- Modify: `app/pages/issues.php`

**Context:** The full issue view (`#full-issue-view`) has a 2-column grid. The left column (lines 156-182) currently stacks 3 cards: Descripción, Checklist, Comentarios. We'll restructure it with tabs.

**Step 1: Replace the left column content (lines 157-182)**

Find this block:
```html
        <!-- Left: description + comments -->
        <div class="flex-col gap-5">
            <div class="card card-compact">
                <div class="text-label mb-2">Descripción</div>
                <textarea id="fi-desc" placeholder="Sin descripción..." class="form-textarea w-full" style="min-height:140px;"></textarea>
            </div>
            <div class="card card-compact">
                <div class="flex flex-between items-center mb-3">
                    <div class="text-label">Checklist</div>
                    <span id="fi-checklist-progress" class="text-xs text-muted"></span>
                </div>
                <div id="fi-checklist-items" class="mb-3"></div>
                <div class="flex gap-2">
                    <input type="text" id="fi-checklist-input" placeholder="Añadir elemento..."
                        class="form-input flex-1">
                    <button class="btn btn-secondary btn-sm" id="fi-checklist-add">+ Añadir</button>
                </div>
            </div>
            <div class="card card-compact">
                <div class="text-label mb-3">Comentarios</div>
                <div id="fi-comments-list" class="mb-3"></div>
                <div class="flex gap-2">
                    <textarea id="fi-comment-input" placeholder="Escribe un comentario..." class="form-textarea flex-1" style="height:60px;"></textarea>
                    <button class="btn btn-primary" id="fi-add-comment" style="align-self:flex-end;">Enviar</button>
                </div>
            </div>
        </div>
```

Replace with:
```html
        <!-- Left: tabs (Descripción | Tests | Comentarios) -->
        <div>
            <div class="wiki-tabs mb-3" id="fi-tabs">
                <button class="wiki-tab active" data-tab="desc">Descripción</button>
                <button class="wiki-tab" data-tab="tests">Tests <span id="fi-tests-badge" style="display:none;background:var(--color-primary);color:#fff;border-radius:999px;font-size:0.65rem;padding:0.05rem 0.35rem;font-weight:700;vertical-align:middle;"></span></button>
                <button class="wiki-tab" data-tab="comments">Comentarios</button>
            </div>

            <!-- Tab: Descripción -->
            <div id="fi-tab-desc" class="flex-col gap-4">
                <div class="card card-compact">
                    <div class="text-label mb-2">Descripción</div>
                    <textarea id="fi-desc" placeholder="Sin descripción..." class="form-textarea w-full" style="min-height:140px;"></textarea>
                </div>
                <div class="card card-compact">
                    <div class="flex flex-between items-center mb-3">
                        <div class="text-label">Checklist</div>
                        <span id="fi-checklist-progress" class="text-xs text-muted"></span>
                    </div>
                    <div id="fi-checklist-items" class="mb-3"></div>
                    <div class="flex gap-2">
                        <input type="text" id="fi-checklist-input" placeholder="Añadir elemento..."
                            class="form-input flex-1">
                        <button class="btn btn-secondary btn-sm" id="fi-checklist-add">+ Añadir</button>
                    </div>
                </div>
            </div>

            <!-- Tab: Tests -->
            <div id="fi-tab-tests" class="hidden">
                <div class="card card-compact">
                    <div class="flex flex-between items-center mb-3">
                        <div class="text-label">Test Cases</div>
                        <button class="btn btn-primary btn-sm" id="fi-test-new-btn">+ Nuevo Test</button>
                    </div>
                    <div id="fi-tests-list">
                        <div class="empty-state">Sin test cases</div>
                    </div>
                </div>
            </div>

            <!-- Tab: Comentarios -->
            <div id="fi-tab-comments" class="hidden">
                <div class="card card-compact">
                    <div class="text-label mb-3">Comentarios</div>
                    <div id="fi-comments-list" class="mb-3"></div>
                    <div class="flex gap-2">
                        <textarea id="fi-comment-input" placeholder="Escribe un comentario..." class="form-textarea flex-1" style="height:60px;"></textarea>
                        <button class="btn btn-primary" id="fi-add-comment" style="align-self:flex-end;">Enviar</button>
                    </div>
                </div>
            </div>
        </div>
```

**Step 2: Add 3 modals before the `<script>` block**

Find the line `<script>` near the end of the file (line ~388, right before `const PROJECT_ID`). Insert these 3 modals just BEFORE that `<script>` tag:

```html
<!-- ── Test Case Modal (create / edit) ──────────────────────────────────── -->
<div id="test-case-modal" class="modal hidden">
    <div class="modal-box" style="max-width:600px;width:min(600px,94vw);">
        <h3 class="mb-4" id="test-case-modal-title">Nuevo Test Case</h3>
        <div class="form-group">
            <label class="form-label">Título del test *</label>
            <input type="text" id="tc-title" class="form-input w-full" placeholder="Ej: Verificar login con credenciales válidas">
        </div>
        <div class="form-group mb-4">
            <label class="form-label">Asignado a</label>
            <select id="tc-assignee" class="form-select w-full">
                <option value="">Sin asignar</option>
            </select>
        </div>
        <div class="mb-3">
            <div class="flex flex-between items-center mb-2">
                <div class="text-label">Pasos</div>
                <button class="btn btn-secondary btn-sm" id="tc-add-step-btn">+ Añadir paso</button>
            </div>
            <div id="tc-steps-list"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="tc-cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="tc-save-btn">Guardar</button>
        </div>
    </div>
</div>

<!-- ── Execute Test Modal (step-by-step) ─────────────────────────────────── -->
<div id="test-execute-modal" class="modal hidden">
    <div class="modal-box" style="max-width:560px;width:min(560px,94vw);">
        <div class="flex flex-between items-center mb-4">
            <h3 id="exec-modal-title" class="mb-0">Ejecutar Test</h3>
            <span id="exec-modal-counter" class="text-sm text-muted"></span>
        </div>
        <div id="exec-step-content"></div>
        <div class="flex flex-between items-center mt-4 pt-3" style="border-top:1px solid var(--border);">
            <button class="btn btn-secondary" id="exec-prev-btn">&#8592; Anterior</button>
            <div class="flex gap-2">
                <button class="btn btn-secondary" id="exec-cancel-btn">Cancelar</button>
                <button class="btn btn-primary" id="exec-next-btn">Siguiente &#8594;</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Execution Detail Modal (read-only) ────────────────────────────────── -->
<div id="test-detail-modal" class="modal hidden">
    <div class="modal-box" style="max-width:560px;width:min(560px,94vw);max-height:80vh;display:flex;flex-direction:column;">
        <div class="flex flex-between items-center mb-4 flex-shrink-0">
            <div>
                <h3 id="detail-modal-title" class="mb-1">Detalle de Ejecución</h3>
                <div id="detail-modal-meta" class="text-sm text-muted"></div>
            </div>
            <button id="detail-modal-close" class="btn-link text-muted" style="font-size:1.5rem;line-height:1;">&times;</button>
        </div>
        <div id="detail-modal-steps" style="overflow-y:auto;flex:1;"></div>
    </div>
</div>
```

**Step 3: Verify PHP syntax**

```bash
php -l app/pages/issues.php
```

Expected: `No syntax errors detected`

**Step 4: Verify in browser**

Open any issue full view. You should see 3 tabs: Descripción, Tests, Comentarios. Click each — they switch. The Tests tab shows "Sin test cases" and a "+ Nuevo Test" button. The modals don't open yet (JS in next task).

**Step 5: Commit**

```bash
git add app/pages/issues.php
git commit -m "feat: tabs (Descripción/Tests/Comentarios) and test modals HTML in full issue view"
```

---

## Task 4: JS — Tab Switching + Test List + Test Form

**Files:**
- Modify: `app/assets/js/issues.js`

All code in this task appends to the END of `app/assets/js/issues.js`.

**Step 1: Add tab initialization and wire it into openFullIssue**

Find this line in `openFullIssue()`:
```js
    await Promise.all([
        loadFullIssueLabels(id),
        loadFullIssueBranches(id),
        loadFullIssuePRs(id),
        loadFullIssueComments(id),
        loadChecklist(id),
        loadStatusLog(id),
        loadDependencies(id),
    ]);
```

Replace with:
```js
    // Reset tabs to Descripción on open
    initFiTabs();
    switchFiTab('desc');

    await Promise.all([
        loadFullIssueLabels(id),
        loadFullIssueBranches(id),
        loadFullIssuePRs(id),
        loadFullIssueComments(id),
        loadChecklist(id),
        loadStatusLog(id),
        loadDependencies(id),
        loadTestCases(id),
    ]);
```

Also find the loading placeholder for fi-comments-list in openFullIssue:
```js
    document.getElementById('fi-comments-list').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Cargando...</em>';
```
Add right after it:
```js
    document.getElementById('fi-tests-list').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Cargando...</em>';
```

**Step 2: Append tab + test functions to end of issues.js**

Add this entire block at the end of `app/assets/js/issues.js`:

```js
// ══════════════════════════════════════════════════════════════════════════════
// TAB SWITCHING — Full Issue View
// ══════════════════════════════════════════════════════════════════════════════

let fiTabsInited = false;

function initFiTabs() {
    if (fiTabsInited) return;
    fiTabsInited = true;
    document.getElementById('fi-tabs').addEventListener('click', e => {
        const btn = e.target.closest('[data-tab]');
        if (!btn) return;
        switchFiTab(btn.dataset.tab);
    });
}

function switchFiTab(tab) {
    ['desc', 'tests', 'comments'].forEach(t => {
        document.getElementById(`fi-tab-${t}`).classList.toggle('hidden', t !== tab);
    });
    document.querySelectorAll('#fi-tabs .wiki-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
}

// ══════════════════════════════════════════════════════════════════════════════
// TEST CASES — list + CRUD
// ══════════════════════════════════════════════════════════════════════════════

let testCasesCache = [];   // [{id, title, assignee_id, steps:[], last_result, execution_count, ...}]
let editingTestId  = null; // null = creating, N = updating

async function loadTestCases(issueId) {
    const res  = await fetch(`${APP_URL}/app/api/tests.php?action=list&issue_id=${issueId}`);
    const data = await res.json();
    testCasesCache = data.data || [];

    const badge = document.getElementById('fi-tests-badge');
    if (testCasesCache.length) {
        badge.textContent = testCasesCache.length;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }

    const list = document.getElementById('fi-tests-list');
    if (!testCasesCache.length) {
        list.innerHTML = '<div class="empty-state">Sin test cases. Crea el primero con "+ Nuevo Test".</div>';
        return;
    }
    list.innerHTML = testCasesCache.map(tc => renderTestCase(tc)).join('');
}

function resultBadge(result) {
    if (!result) return '<span style="font-size:0.72rem;background:var(--bg-secondary);color:var(--text-secondary);border-radius:999px;padding:0.1rem 0.5rem;font-weight:600;">Sin ejecutar</span>';
    const ok = result === 'pass';
    return `<span style="font-size:0.72rem;background:${ok?'#dcfce7':'#fee2e2'};color:${ok?'#16a34a':'#dc2626'};border-radius:999px;padding:0.1rem 0.5rem;font-weight:600;">${ok?'✓ PASS':'✗ FAIL'}</span>`;
}

function renderTestCase(tc) {
    const avatarHtml = tc.assignee_avatar
        ? `<img src="${escapeHtml(tc.assignee_avatar)}" class="avatar avatar-xs flex-shrink-0" alt="">`
        : tc.assignee_name
            ? `<span class="avatar avatar-xs flex-shrink-0" style="font-size:0.6rem;">${escapeHtml(tc.assignee_name.charAt(0).toUpperCase())}</span>`
            : '';

    const assigneeHtml = tc.assignee_name
        ? `<span class="text-xs text-muted">${escapeHtml(tc.assignee_name)}</span>`
        : `<span class="text-xs text-muted">Sin asignar</span>`;

    const stepsCount = (tc.steps || []).length;
    const execCount  = parseInt(tc.execution_count || 0);

    return `<div class="list-row" style="flex-direction:column;align-items:stretch;padding:0.75rem 0;border-bottom:1px solid var(--border);">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold flex-1 min-w-0" style="font-size:0.9rem;">${escapeHtml(tc.title)}</span>
            ${resultBadge(tc.last_result)}
            <div class="flex gap-1 flex-shrink-0">
                <button class="btn btn-primary btn-xs" onclick="openExecuteModal(${tc.id})">&#9654; Ejecutar</button>
                <button class="btn btn-secondary btn-xs" onclick="openEditTestModal(${tc.id})">&#9998;</button>
                <button class="btn btn-danger btn-xs" onclick="deleteTestCase(${tc.id})">&#128465;</button>
            </div>
        </div>
        <div class="flex items-center gap-3 mt-1">
            ${avatarHtml}
            ${assigneeHtml}
            <span class="text-xs text-muted">${stepsCount} paso${stepsCount!==1?'s':''}</span>
            ${execCount > 0 ? `<button class="btn-link text-xs text-primary-color" onclick="toggleTestHistory(${tc.id}, this)">&#9660; Historial (${execCount})</button>` : ''}
        </div>
        ${execCount > 0 ? `<div id="tc-history-${tc.id}" class="hidden mt-2" style="padding-left:0.5rem;border-left:2px solid var(--border);"></div>` : ''}
    </div>`;
}

async function toggleTestHistory(tcId, btn) {
    const box = document.getElementById(`tc-history-${tcId}`);
    if (!box) return;
    const isOpen = !box.classList.contains('hidden');
    if (isOpen) {
        box.classList.add('hidden');
        btn.innerHTML = `&#9660; Historial`;
        return;
    }
    box.classList.remove('hidden');
    btn.innerHTML = `&#9650; Historial`;
    box.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Cargando...</em>';
    const res  = await fetch(`${APP_URL}/app/api/tests.php?action=executions&test_case_id=${tcId}`);
    const data = await res.json();
    const execs = data.data || [];
    if (!execs.length) { box.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin ejecuciones</em>'; return; }
    box.innerHTML = execs.map(e => {
        const ok  = e.result === 'pass';
        const dt  = new Date(e.executed_at).toLocaleString('es-ES', {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
        return `<div class="flex items-center gap-2 py-1" style="font-size:0.8rem;">
            <span style="color:${ok?'#16a34a':'#dc2626'};font-weight:700;">${ok?'✓':'✗'}</span>
            <span class="text-muted">${dt}</span>
            <span>${escapeHtml(e.executor_name)}</span>
            <button class="btn-link text-xs text-primary-color" onclick="openExecutionDetail(${e.id})">Ver</button>
        </div>`;
    }).join('');
}

// ── Test Case Modal (Create / Edit) ──────────────────────────────────────────

function buildStepRow(i, step) {
    return `<div class="tc-step-row flex gap-2 items-start mb-2" data-idx="${i}">
        <span class="text-xs text-muted flex-shrink-0" style="padding-top:0.5rem;width:1.2rem;text-align:right;">${i+1}.</span>
        <div class="flex-col gap-1 flex-1">
            <input type="text" class="form-input w-full form-input-sm tc-step-action" placeholder="Acción (qué hacer)" value="${escapeHtml(step.action||'')}">
            <input type="text" class="form-input w-full form-input-sm tc-step-expected" placeholder="Resultado esperado (opcional)" value="${escapeHtml(step.expected_result||'')}">
        </div>
        <button class="btn btn-danger btn-xs flex-shrink-0" style="margin-top:0.25rem;" onclick="removeStepRow(this)">&#10005;</button>
    </div>`;
}

function refreshStepNumbers() {
    document.querySelectorAll('#tc-steps-list .tc-step-row').forEach((row, i) => {
        row.dataset.idx = i;
        row.querySelector('span').textContent = `${i+1}.`;
    });
}

function removeStepRow(btn) {
    btn.closest('.tc-step-row').remove();
    refreshStepNumbers();
}

document.getElementById('tc-add-step-btn').addEventListener('click', () => {
    const list = document.getElementById('tc-steps-list');
    const idx  = list.querySelectorAll('.tc-step-row').length;
    list.insertAdjacentHTML('beforeend', buildStepRow(idx, {}));
});

document.getElementById('tc-cancel-btn').addEventListener('click', () => {
    document.getElementById('test-case-modal').classList.add('hidden');
});

document.getElementById('fi-test-new-btn').addEventListener('click', () => openTestCaseModal(null));

function openTestCaseModal(tc) {
    editingTestId = tc ? tc.id : null;
    document.getElementById('test-case-modal-title').textContent = tc ? 'Editar Test Case' : 'Nuevo Test Case';
    document.getElementById('tc-title').value = tc ? tc.title : '';

    // Populate assignee select from members
    const sel = document.getElementById('tc-assignee');
    sel.innerHTML = '<option value="">Sin asignar</option>' +
        mentionTeamCache.map(m => `<option value="${m.id}"${tc && tc.assignee_id == m.id ? ' selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

    // Render steps
    const stepsList = document.getElementById('tc-steps-list');
    const steps = tc ? (tc.steps || []) : [];
    stepsList.innerHTML = steps.map((s, i) => buildStepRow(i, s)).join('');
    if (!steps.length) {
        // Start with one empty step
        stepsList.innerHTML = buildStepRow(0, {});
    }

    document.getElementById('test-case-modal').classList.remove('hidden');
    document.getElementById('tc-title').focus();
}

function openEditTestModal(tcId) {
    const tc = testCasesCache.find(t => t.id == tcId);
    if (!tc) return;
    openTestCaseModal(tc);
}

document.getElementById('tc-save-btn').addEventListener('click', async () => {
    const title    = document.getElementById('tc-title').value.trim();
    const assignee = document.getElementById('tc-assignee').value;
    if (!title) { showToast('El título es obligatorio', 'error'); return; }

    const rows  = document.querySelectorAll('#tc-steps-list .tc-step-row');
    const steps = [];
    rows.forEach(row => {
        const action   = row.querySelector('.tc-step-action').value.trim();
        const expected = row.querySelector('.tc-step-expected').value.trim();
        if (action) steps.push({ action, expected_result: expected });
    });

    const btn = document.getElementById('tc-save-btn');
    btn.disabled = true; btn.textContent = 'Guardando...';

    const body = {
        title,
        assignee_id: assignee ? parseInt(assignee) : null,
        steps,
    };

    let url;
    if (editingTestId) {
        url = `${APP_URL}/app/api/tests.php?action=update`;
        body.id = editingTestId;
    } else {
        url = `${APP_URL}/app/api/tests.php?action=create`;
        body.issue_id = currentFullIssueId;
    }

    const res  = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body) });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Guardar';

    if (data.success) {
        document.getElementById('test-case-modal').classList.add('hidden');
        showToast(editingTestId ? 'Test actualizado' : 'Test creado');
        await loadTestCases(currentFullIssueId);
    } else {
        showToast(data.error || 'Error al guardar', 'error');
    }
});

async function deleteTestCase(tcId) {
    showConfirm('¿Eliminar este test case y todo su historial?', async () => {
        const res  = await fetch(`${APP_URL}/app/api/tests.php?action=delete`, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id: tcId })
        });
        const data = await res.json();
        if (data.success) { showToast('Test eliminado'); await loadTestCases(currentFullIssueId); }
        else showToast(data.error || 'Error', 'error');
    }, { confirmLabel:'Eliminar', confirmClass:'btn-danger', requireWord:'ELIMINAR' });
}
```

**Step 3: Commit this batch**

```bash
git add app/assets/js/issues.js
git commit -m "feat: tab switching + test case list + create/edit modal JS"
```

**Step 4: Verify in browser**

1. Open an issue's full view
2. Click the "Tests" tab → should see "Sin test cases"
3. Click "+ Nuevo Test" → modal opens with title field and 1 empty step
4. Click "+ Añadir paso" → adds step row
5. Fill in title + steps, click "Guardar" → test appears in list with "Sin ejecutar" badge
6. Click pencil icon → edit modal opens pre-filled
7. Click trash icon → confirm modal with ELIMINAR word appears

---

## Task 5: JS — Execute Modal + Execution Detail Modal

**Files:**
- Modify: `app/assets/js/issues.js`

Add this entire block at the end of `app/assets/js/issues.js`:

**Step 1: Add execute modal JS**

```js
// ══════════════════════════════════════════════════════════════════════════════
// EXECUTE TEST — step-by-step modal
// ══════════════════════════════════════════════════════════════════════════════

let execState = {
    testCaseId: null,
    steps: [],
    results: [],  // [{step_id, result, comment}]
    current: 0,
};

function openExecuteModal(tcId) {
    const tc = testCasesCache.find(t => t.id == tcId);
    if (!tc) return;
    if (!tc.steps || !tc.steps.length) {
        showToast('Este test no tiene pasos. Edítalo y añade al menos uno.', 'error');
        return;
    }

    execState = {
        testCaseId: tcId,
        steps: tc.steps,
        results: tc.steps.map(s => ({ step_id: s.id, result: '', comment: '' })),
        current: 0,
    };

    document.getElementById('exec-modal-title').textContent = escapeHtml(tc.title);
    renderExecStep();
    document.getElementById('test-execute-modal').classList.remove('hidden');
}

function renderExecStep() {
    const { steps, results, current } = execState;
    const step = steps[current];
    const saved = results[current];
    const total = steps.length;

    document.getElementById('exec-modal-counter').textContent = `Paso ${current+1} de ${total}`;
    document.getElementById('exec-prev-btn').disabled = current === 0;

    const isLast = current === total - 1;
    document.getElementById('exec-next-btn').textContent = isLast ? '✓ Finalizar' : 'Siguiente →';

    document.getElementById('exec-step-content').innerHTML = `
        <div class="mb-3 p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
            <div class="text-label mb-1">Acción</div>
            <div style="font-size:0.9rem;">${escapeHtml(step.action)}</div>
            ${step.expected_result ? `<div class="text-label mt-2 mb-1">Resultado esperado</div><div style="font-size:0.875rem;color:var(--text-secondary);">${escapeHtml(step.expected_result)}</div>` : ''}
        </div>
        <div class="mb-3">
            <div class="text-label mb-2">Resultado</div>
            <div class="flex gap-2">
                <button class="btn flex-1 exec-result-btn ${saved.result==='pass'?'btn-primary':''}" data-result="pass" onclick="setExecResult('pass')">✓ Pass</button>
                <button class="btn flex-1 exec-result-btn ${saved.result==='fail'?'btn-danger':''}" data-result="fail" onclick="setExecResult('fail')">✗ Fail</button>
                <button class="btn btn-secondary flex-1 exec-result-btn ${saved.result==='skip'?'btn-active':''}" data-result="skip" onclick="setExecResult('skip')">— Skip</button>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Comentario (opcional)</label>
            <textarea id="exec-step-comment" class="form-textarea w-full" style="height:56px;" placeholder="Observaciones...">${escapeHtml(saved.comment||'')}</textarea>
        </div>
    `;
}

function setExecResult(result) {
    execState.results[execState.current].result = result;
    // Update button styles
    document.querySelectorAll('.exec-result-btn').forEach(btn => {
        const r = btn.dataset.result;
        btn.className = 'btn flex-1 exec-result-btn';
        if (r === result) {
            if (r === 'pass') btn.classList.add('btn-primary');
            else if (r === 'fail') btn.classList.add('btn-danger');
            else btn.classList.add('btn-secondary');
        } else {
            btn.classList.add('btn-secondary');
        }
    });
}

function saveCurrentExecStep() {
    const comment = document.getElementById('exec-step-comment')?.value || '';
    execState.results[execState.current].comment = comment;
}

document.getElementById('exec-prev-btn').addEventListener('click', () => {
    saveCurrentExecStep();
    if (execState.current > 0) {
        execState.current--;
        renderExecStep();
    }
});

document.getElementById('exec-next-btn').addEventListener('click', async () => {
    saveCurrentExecStep();
    const cur = execState.results[execState.current];
    if (!cur.result) { showToast('Marca el resultado de este paso antes de continuar', 'error'); return; }

    const isLast = execState.current === execState.steps.length - 1;
    if (!isLast) {
        execState.current++;
        renderExecStep();
        return;
    }

    // Final step → submit
    const btn = document.getElementById('exec-next-btn');
    btn.disabled = true; btn.textContent = 'Guardando...';

    const res  = await fetch(`${APP_URL}/app/api/tests.php?action=execute`, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ test_case_id: execState.testCaseId, step_results: execState.results })
    });
    const data = await res.json();
    btn.disabled = false;

    if (data.success) {
        document.getElementById('test-execute-modal').classList.add('hidden');
        const ok = data.result === 'pass';
        showToast(`Ejecución completada: ${ok ? '✓ PASS' : '✗ FAIL'}`, ok ? 'success' : 'error');
        await loadTestCases(currentFullIssueId);
    } else {
        showToast(data.error || 'Error al guardar ejecución', 'error');
    }
});

document.getElementById('exec-cancel-btn').addEventListener('click', () => {
    document.getElementById('test-execute-modal').classList.add('hidden');
});

// ══════════════════════════════════════════════════════════════════════════════
// EXECUTION DETAIL MODAL — read-only
// ══════════════════════════════════════════════════════════════════════════════

async function openExecutionDetail(execId) {
    const modal = document.getElementById('test-detail-modal');
    document.getElementById('detail-modal-steps').innerHTML = '<em style="color:var(--text-tertiary);">Cargando...</em>';
    modal.classList.remove('hidden');

    const res  = await fetch(`${APP_URL}/app/api/tests.php?action=execution_detail&execution_id=${execId}`);
    const data = await res.json();
    const steps = data.data || [];

    const resultIcons = { pass:'✓', fail:'✗', skip:'—' };
    const resultColors = { pass:'#16a34a', fail:'#dc2626', skip:'#9ca3af' };

    document.getElementById('detail-modal-steps').innerHTML = steps.length
        ? steps.map((s, i) => `
            <div class="mb-3 p-3" style="border:1px solid var(--border);border-radius:var(--radius-md);">
                <div class="flex items-center gap-2 mb-1">
                    <span style="font-size:1rem;font-weight:700;color:${resultColors[s.result]||'#9ca3af'};">${resultIcons[s.result]||'—'}</span>
                    <span class="text-label">Paso ${i+1}</span>
                </div>
                <div style="font-size:0.875rem;margin-bottom:0.25rem;">${escapeHtml(s.action)}</div>
                ${s.expected_result ? `<div class="text-xs text-muted mb-1">Esperado: ${escapeHtml(s.expected_result)}</div>` : ''}
                ${s.comment ? `<div class="text-xs" style="color:var(--text-secondary);font-style:italic;">Comentario: ${escapeHtml(s.comment)}</div>` : ''}
            </div>`)
            .join('')
        : '<div class="empty-state">Sin pasos en esta ejecución</div>';
}

document.getElementById('detail-modal-close').addEventListener('click', () => {
    document.getElementById('test-detail-modal').classList.add('hidden');
});
```

**Step 2: Commit**

```bash
git add app/assets/js/issues.js
git commit -m "feat: execute test modal (step-by-step) and execution detail modal JS"
```

**Step 3: Full end-to-end verification**

1. Open a full issue view → Tests tab
2. Create a test case "Login básico" with 2 steps
3. Click "▶ Ejecutar" → modal opens with step 1
4. Click "✓ Pass" for step 1, "Siguiente →"
5. Click "✗ Fail" for step 2 with comment "No redirige", click "✓ Finalizar"
6. Toast shows "✗ FAIL"
7. Test card now shows "✗ FAIL" badge + "▼ Historial (1)"
8. Click "▼ Historial" → row appears with ✗ and "Ver" link
9. Click "Ver" → detail modal shows both steps with their results
10. Execute again → all Pass → badge becomes "✓ PASS", historial shows 2 entries

---

## Task 6: Verify Dark Mode + Commit Final

**Files:** None (CSS check only)

**Step 1: Dark mode spot check**

1. Toggle dark mode (moon icon in sidebar)
2. Open Tests tab → cards, buttons, badges should all use CSS variables (no hardcoded light colors)
3. Open execute modal → check background, text colors
4. Open detail modal → check readability

**Step 2: Edge cases**

- Test case with 0 steps → clicking Ejecutar shows toast "Este test no tiene pasos. Edítalo y añade al menos uno."
- Crear test sin título → toast "El título es obligatorio"
- Eliminar test → ELIMINAR confirm word required
- Multiple issues → each has its own test list (verify by opening different issues)

**Step 3: Final commit**

```bash
git log --oneline -5
```

Should show commits from tasks 1-5. If all look good:

```bash
git log --oneline -6
```

All 5 commits should be on master.
