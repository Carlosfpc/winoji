# SonarQube Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Integrar SonarQube en la app: sección de configuración por proyecto, estado de quality gate + métricas, y chips de estado por rama en el panel de issues.

**Architecture:** Nueva tabla `sonarqube_projects` (igual que `github_repos`). Nuevo archivo `app/api/sonarqube.php`. La app llama a la API REST de SonarQube con el token guardado encriptado. UI en `project.php` y en el panel de branches de `issues.js`.

**Tech Stack:** PHP 8.x, SonarQube REST API v1 (autenticación Bearer), Vanilla JS, MySQL 8.

---

### Contexto del codebase

- Repo: `C:\Users\carlo\proyects\claude-skills`
- URL local: `http://localhost/teamapp/public`
- SonarQube típicamente en `http://localhost:9000`
- El token de SonarQube se encripta igual que el token de GitHub (AES-256-CBC, función `encrypt_token`)
- `ENCRYPT_KEY` está definida en `config/config.php` via `.env`
- No hay test suite — verificar en el navegador

---

### Task 1: Migración DB — tabla `sonarqube_projects`

**Files:**
- Create: `db/migrations/add_sonarqube.sql`
- Modify: `db/schema.sql` (añadir la tabla)

**Step 1: Crear el archivo de migración**

Crear `db/migrations/add_sonarqube.sql` con este contenido exacto:

```sql
CREATE TABLE IF NOT EXISTS sonarqube_projects (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id       INT UNSIGNED NOT NULL UNIQUE,
    sonar_url        VARCHAR(255) NOT NULL DEFAULT 'http://localhost:9000',
    sonar_token      TEXT NOT NULL,
    sonar_project_key VARCHAR(255) NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 2: Ejecutar la migración**

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -u root teamapp < db/migrations/add_sonarqube.sql
```

Esperado: sin errores (comando silencioso = éxito)

**Step 3: Añadir la tabla a schema.sql**

En `db/schema.sql`, al final (antes del EOF o del último comentario), añadir el mismo bloque CREATE TABLE del step 1.

**Step 4: Commit**

```bash
git add db/migrations/add_sonarqube.sql db/schema.sql
git commit -m "feat: add sonarqube_projects table migration"
```

---

### Task 2: Crear `app/api/sonarqube.php`

**Files:**
- Create: `app/api/sonarqube.php`

**Step 1: Crear el archivo completo**

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

/* ── Encryption (same algorithm as github.php) ── */
function sonar_encrypt(string $token): string {
    $iv = openssl_random_pseudo_bytes(16);
    $enc = openssl_encrypt($token, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function sonar_decrypt(string $stored): string {
    $data = base64_decode($stored);
    $iv   = substr($data, 0, 16);
    $enc  = substr($data, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
}

/* ── SonarQube HTTP helper ── */
function sonar_request(string $sonar_url, string $token, string $endpoint): array {
    $url = rtrim($sonar_url, '/') . '/api/' . $endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $response   = curl_exec($ch);
    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($response === false) return ['status' => 0, 'body' => null, 'error' => $curl_error];
    return ['status' => $status, 'body' => json_decode($response, true)];
}

/* ── Config CRUD ── */
function get_sonar_config(int $project_id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM sonarqube_projects WHERE project_id = ?');
    $stmt->execute([$project_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['sonar_token'] = sonar_decrypt($row['sonar_token']);
    return $row;
}

function save_sonar_config(int $project_id, string $sonar_url, string $token, string $project_key): array {
    if (empty($sonar_url) || empty($token) || empty($project_key)) {
        return ['success' => false, 'error' => 'Todos los campos son obligatorios'];
    }
    $encrypted = sonar_encrypt($token);
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'INSERT INTO sonarqube_projects (project_id, sonar_url, sonar_token, sonar_project_key)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE sonar_url=VALUES(sonar_url), sonar_token=VALUES(sonar_token), sonar_project_key=VALUES(sonar_project_key)'
    );
    $stmt->execute([$project_id, rtrim($sonar_url, '/'), $encrypted, $project_key]);
    return ['success' => true];
}

function delete_sonar_config(int $project_id): array {
    get_db()->prepare('DELETE FROM sonarqube_projects WHERE project_id = ?')->execute([$project_id]);
    return ['success' => true];
}

/* ── SonarQube data fetchers ── */

/**
 * Returns quality gate status + key metrics for a project (optionally filtered by branch).
 * Returns ['success'=>true, 'status'=>'PASSED'|'FAILED'|'WARN'|'ERROR'|'NONE', 'metrics'=>[...], 'url'=>'...']
 */
function get_sonar_project_status(int $project_id, string $branch = ''): array {
    $cfg = get_sonar_config($project_id);
    if (!$cfg) return ['success' => false, 'error' => 'SonarQube no configurado'];

    $key    = urlencode($cfg['sonar_project_key']);
    $branchQ = $branch ? '&branch=' . urlencode($branch) : '';

    // Quality gate
    $qg = sonar_request($cfg['sonar_url'], $cfg['sonar_token'],
        "qualitygates/project_status?projectKey={$key}{$branchQ}");

    if ($qg['status'] === 401) return ['success' => false, 'error' => 'Token inválido (401)'];
    if ($qg['status'] === 404) return ['success' => false, 'error' => 'Proyecto no encontrado en SonarQube'];
    if ($qg['status'] !== 200) return ['success' => false, 'error' => "SonarQube error HTTP {$qg['status']}"];

    $qgStatus = $qg['body']['projectStatus']['status'] ?? 'NONE';

    // Metrics
    $metrics = sonar_request($cfg['sonar_url'], $cfg['sonar_token'],
        "measures/component?component={$key}{$branchQ}&metricKeys=bugs,vulnerabilities,code_smells,duplicated_lines_density,coverage");

    $measures = [];
    if ($metrics['status'] === 200) {
        foreach ($metrics['body']['component']['measures'] ?? [] as $m) {
            $measures[$m['metric']] = $m['value'] ?? '—';
        }
    }

    // Build link to SonarQube
    $sonarLink = $cfg['sonar_url'] . '/dashboard?id=' . urlencode($cfg['sonar_project_key'])
               . ($branch ? '&branch=' . urlencode($branch) : '');

    return [
        'success'  => true,
        'status'   => $qgStatus,
        'metrics'  => $measures,
        'url'      => $sonarLink,
        'project_key' => $cfg['sonar_project_key'],
        'sonar_url'   => $cfg['sonar_url'],
    ];
}

/* ── HTTP routing ── */
if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        $method === 'GET'  && $action === 'config'        => print json_encode((function() {
            $pid = (int)($_GET['project_id'] ?? 0);
            require_project_access($pid);
            $cfg = get_sonar_config($pid);
            // Never expose the token to the frontend
            if ($cfg) unset($cfg['sonar_token']);
            return ['success' => true, 'data' => $cfg];
        })()),

        $method === 'GET'  && $action === 'status'        => print json_encode((function() {
            $pid    = (int)($_GET['project_id'] ?? 0);
            $branch = $_GET['branch'] ?? '';
            require_project_access($pid);
            return get_sonar_project_status($pid, $branch);
        })()),

        $method === 'POST' && $action === 'save'          => print json_encode((function() use ($b) {
            $pid = (int)($b['project_id'] ?? 0);
            require_project_access($pid);
            require_role('admin');
            return save_sonar_config($pid, trim($b['sonar_url'] ?? ''), trim($b['sonar_token'] ?? ''), trim($b['sonar_project_key'] ?? ''));
        })()),

        $method === 'POST' && $action === 'delete'        => print json_encode((function() use ($b) {
            $pid = (int)($b['project_id'] ?? 0);
            require_project_access($pid);
            require_role('admin');
            return delete_sonar_config($pid);
        })()),

        default => print json_encode(['success' => false, 'error' => 'Unknown action'])
    };
    exit;
}
```

**Step 2: Verificar sintaxis**

```bash
php -l app/api/sonarqube.php
```
Esperado: `No syntax errors detected in app/api/sonarqube.php`

**Step 3: Commit**

```bash
git add app/api/sonarqube.php
git commit -m "feat: sonarqube.php API — config CRUD + quality gate + metrics"
```

---

### Task 3: Sección SonarQube en project.php

**Files:**
- Modify: `app/pages/project.php`

Esta tarea tiene dos partes: HTML y JavaScript.

**Step 1: Añadir el HTML de la sección SonarQube**

En `app/pages/project.php`, localizar la línea que contiene `<!-- Issue Templates -->` (línea ~103). Insertar **antes** de ella:

```html
<!-- ── Sección SonarQube ──────────────────────────────────────── -->
<h2 class="mb-2 mt-6">SonarQube</h2>
<p class="text-sm text-muted mb-4">
    Conecta SonarQube para ver el estado de la quality gate y métricas de calidad del proyecto.
</p>

<div id="sonar-status-card" class="card mb-4">
    <p class="text-muted text-sm">Cargando...</p>
</div>

<?php if (has_role('admin')): ?>
<div class="card mb-6" id="sonar-config-card">
    <h3 class="section-title mb-4">Configurar SonarQube</h3>
    <div class="form-group">
        <label class="form-label">URL de SonarQube</label>
        <input type="text" id="sonar-url-input" placeholder="http://localhost:9000"
            class="form-input w-full">
    </div>
    <div class="form-group">
        <label class="form-label">Token de Acceso</label>
        <input type="password" id="sonar-token-input" placeholder="squ_..."
            class="form-input w-full">
        <p class="form-hint">SonarQube → My Account → Security → Generate Token</p>
    </div>
    <div class="form-group mb-4">
        <label class="form-label">Project Key</label>
        <input type="text" id="sonar-project-key-input" placeholder="mi-proyecto"
            class="form-input w-full">
        <p class="form-hint">Visible en SonarQube → Project → Project Information</p>
    </div>
    <div class="flex gap-2 items-center">
        <button class="btn btn-primary" id="sonar-save-btn">Guardar</button>
        <button class="btn btn-danger" id="sonar-delete-btn" style="display:none;">Desconectar</button>
    </div>
    <p id="sonar-config-msg" class="text-sm mt-3 hidden"></p>
</div>
<?php endif; ?>
```

**Step 2: Añadir las funciones JS de SonarQube**

En `app/pages/project.php`, localizar la línea `loadRepoStatus();` (penúltima línea antes del cierre `</script>`, línea ~565). Insertar **antes** de `loadRepoStatus();`:

```js
/* ── SonarQube ────────────────────────────────────────────────── */
const SONAR_STATUS_LABELS = { PASSED:'PASSED', FAILED:'FAILED', WARN:'WARN', ERROR:'ERROR', NONE:'Sin análisis' };
const SONAR_STATUS_COLORS = { PASSED:'#16a34a', FAILED:'#dc2626', WARN:'#d97706', ERROR:'#dc2626', NONE:'var(--text-tertiary)' };
const SONAR_STATUS_BG     = { PASSED:'#dcfce7', FAILED:'#fee2e2', WARN:'#fef9c3', ERROR:'#fee2e2', NONE:'var(--bg-secondary)' };

function sonarQGChip(status) {
    const label = SONAR_STATUS_LABELS[status] || status;
    const color = SONAR_STATUS_COLORS[status] || 'var(--text-tertiary)';
    const bg    = SONAR_STATUS_BG[status]    || 'var(--bg-secondary)';
    const icon  = status === 'PASSED' ? '&#10003;' : status === 'NONE' ? '○' : '&#10007;';
    return `<span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.75rem;border-radius:999px;background:${bg};color:${color};font-size:0.8rem;font-weight:700;">${icon} QG ${label}</span>`;
}

async function loadSonarStatus() {
    const card = document.getElementById('sonar-status-card');
    if (!PROJECT_ID || !card) return;

    // Load config to pre-fill form
    try {
        const cfgRes  = await fetch(`${APP_URL}/app/api/sonarqube.php?action=config&project_id=${PROJECT_ID}`);
        const cfgData = await cfgRes.json();
        if (cfgData.data) {
            const cfg = cfgData.data;
            const urlEl = document.getElementById('sonar-url-input');
            const keyEl = document.getElementById('sonar-project-key-input');
            const delBtn = document.getElementById('sonar-delete-btn');
            if (urlEl) urlEl.value = cfg.sonar_url || '';
            if (keyEl) keyEl.value = cfg.sonar_project_key || '';
            if (delBtn) delBtn.style.display = 'inline-flex';
        }
    } catch(e) {}

    // Load status
    const res  = await fetch(`${APP_URL}/app/api/sonarqube.php?action=status&project_id=${PROJECT_ID}`);
    const data = await res.json();

    if (!data.success) {
        card.innerHTML = `<p class="text-muted text-sm">${data.error === 'SonarQube no configurado'
            ? 'SonarQube no configurado para este proyecto.'
            : `Error: ${escapeHtml(data.error)}`}</p>`;
        return;
    }

    const m = data.metrics || {};
    const fmt = v => (v === undefined || v === '—') ? '—' : v;
    const coverage = m.coverage !== undefined ? parseFloat(m.coverage).toFixed(1) + '%' : '—';
    const dupl     = m.duplicated_lines_density !== undefined ? parseFloat(m.duplicated_lines_density).toFixed(1) + '%' : '—';

    card.innerHTML = `
        <div class="flex items-center gap-4 flex-wrap">
            <div class="flex-1">
                <div class="flex items-center gap-3 flex-wrap mb-3">
                    ${sonarQGChip(data.status)}
                    <span class="text-sm text-muted font-mono">${escapeHtml(data.project_key)}</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:0.75rem;">
                    <div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
                        <div style="font-size:1.5rem;font-weight:800;color:#dc2626;">${fmt(m.bugs)}</div>
                        <div class="text-xs text-muted mt-1">Bugs</div>
                    </div>
                    <div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
                        <div style="font-size:1.5rem;font-weight:800;color:#d97706;">${fmt(m.vulnerabilities)}</div>
                        <div class="text-xs text-muted mt-1">Vulnerabilidades</div>
                    </div>
                    <div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
                        <div style="font-size:1.5rem;font-weight:800;color:#7c3aed;">${fmt(m.code_smells)}</div>
                        <div class="text-xs text-muted mt-1">Code Smells</div>
                    </div>
                    <div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
                        <div style="font-size:1.5rem;font-weight:800;color:#0891b2;">${dupl}</div>
                        <div class="text-xs text-muted mt-1">Duplicados</div>
                    </div>
                    <div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
                        <div style="font-size:1.5rem;font-weight:800;color:#16a34a;">${coverage}</div>
                        <div class="text-xs text-muted mt-1">Cobertura</div>
                    </div>
                </div>
            </div>
            <a href="${escapeHtml(data.url)}" target="_blank" class="btn btn-secondary btn-sm nowrap">
                Ver en SonarQube &#8599;
            </a>
        </div>`;
}

// Save SonarQube config
const sonarSaveBtn = document.getElementById('sonar-save-btn');
if (sonarSaveBtn) {
    sonarSaveBtn.addEventListener('click', async () => {
        const url   = document.getElementById('sonar-url-input')?.value.trim();
        const token = document.getElementById('sonar-token-input')?.value.trim();
        const key   = document.getElementById('sonar-project-key-input')?.value.trim();
        const msg   = document.getElementById('sonar-config-msg');
        if (!url || !key) { showToast('URL y Project Key son obligatorios', 'error'); return; }
        if (!token) { showToast('Introduce el token de SonarQube', 'error'); return; }
        sonarSaveBtn.disabled = true; sonarSaveBtn.textContent = 'Guardando...';
        const res  = await apiFetch(`${APP_URL}/app/api/sonarqube.php?action=save`, {
            method: 'POST', body: JSON.stringify({ project_id: PROJECT_ID, sonar_url: url, sonar_token: token, sonar_project_key: key })
        });
        const data = await res.json();
        sonarSaveBtn.disabled = false; sonarSaveBtn.textContent = 'Guardar';
        if (data.success) {
            showToast('SonarQube configurado correctamente');
            document.getElementById('sonar-token-input').value = '';
            loadSonarStatus();
        } else {
            showToast(data.error || 'Error al guardar', 'error');
        }
    });
}

// Delete SonarQube config
const sonarDeleteBtn = document.getElementById('sonar-delete-btn');
if (sonarDeleteBtn) {
    sonarDeleteBtn.addEventListener('click', async () => {
        if (!confirm('¿Desconectar SonarQube de este proyecto?')) return;
        const res  = await apiFetch(`${APP_URL}/app/api/sonarqube.php?action=delete`, {
            method: 'POST', body: JSON.stringify({ project_id: PROJECT_ID })
        });
        const data = await res.json();
        if (data.success) {
            showToast('SonarQube desconectado');
            sonarDeleteBtn.style.display = 'none';
            document.getElementById('sonar-url-input').value = '';
            document.getElementById('sonar-project-key-input').value = '';
            loadSonarStatus();
        }
    });
}
```

Y en la última línea del `<script>`, después de `loadRepoStatus();`, añadir:
```js
loadSonarStatus();
```

**Step 3: Verificar sintaxis**

```bash
php -l app/pages/project.php
```
Esperado: `No syntax errors detected`

**Step 4: Probar en navegador**

1. Ir a `http://localhost/teamapp/public?page=project`
2. Debe aparecer la sección "SonarQube" con el formulario de configuración
3. Sin configurar: "SonarQube no configurado para este proyecto."
4. Rellenar URL (`http://localhost:9000`), token y project key → clic "Guardar"
5. Debe aparecer el card con métricas y botón "Ver en SonarQube ↗"

**Step 5: Commit**

```bash
git add app/pages/project.php
git commit -m "feat: SonarQube section in project settings — config + quality gate + metrics"
```

---

### Task 4: Chips QG por rama en `loadBranches()` — panel lateral de issue

**Files:**
- Modify: `app/assets/js/issues.js`

**Contexto:** `loadBranches(id)` en issues.js (línea ~228) renderiza el `#branch-list`. Hay que añadir fetch de estado Sonar por rama y mostrar el chip.

**Step 1: Añadir función helper `sonarQGChip` al inicio de issues.js (línea ~15)**

```js
function sonarQGChip(status, url) {
    if (!status || status === 'NONE') return '';
    const labels = { PASSED:'QG Passed', FAILED:'QG Failed', WARN:'QG Warn', ERROR:'QG Error' };
    const colors = { PASSED:'#16a34a', FAILED:'#dc2626', WARN:'#d97706', ERROR:'#dc2626' };
    const bgs    = { PASSED:'#dcfce7', FAILED:'#fee2e2', WARN:'#fef9c3', ERROR:'#fee2e2' };
    const icon   = status === 'PASSED' ? '✓' : '✗';
    const label  = labels[status] || status;
    const color  = colors[status] || 'var(--text-tertiary)';
    const bg     = bgs[status]    || 'var(--bg-secondary)';
    const link   = url ? ` <a href="${escapeHtml(url)}" target="_blank" style="color:${color};font-weight:700;">↗</a>` : '';
    return `<span style="font-size:0.7rem;background:${bg};color:${color};border-radius:999px;padding:0.1rem 0.5rem;font-weight:600;white-space:nowrap;">${icon} ${label}${link}</span>`;
}
```

**Step 2: Modificar `loadBranches(id)` para obtener QG de Sonar en paralelo**

Localizar (línea ~247):
```js
    const branches = data.data || [];
    if (!branches.length) { list.innerHTML = '<em style="color:var(--text-tertiary)">Sin ramas aún</em>'; return; }
    list.innerHTML = branches.map(b => {
```

Reemplazar el bloque completo hasta el cierre `}).join('');` con:

```js
    const branches = data.data || [];
    if (!branches.length) { list.innerHTML = '<em style="color:var(--text-tertiary)">Sin ramas aún</em>'; return; }

    // Fetch SonarQube QG for each branch in parallel (non-blocking)
    const sonarResults = await Promise.all(
        branches.map(b =>
            fetch(`${APP_URL}/app/api/sonarqube.php?action=status&project_id=${PROJECT_ID}&branch=${encodeURIComponent(b.branch_name)}`)
                .then(r => r.json())
                .catch(() => ({ success: false }))
        )
    );

    list.innerHTML = branches.map((b, i) => {
        const sq = sonarResults[i] || {};
        const existingPR = currentPRs.find(pr => pr.branch === b.branch_name);
        const prBtn = existingPR
            ? `<button onclick="openDiffViewer(${existingPR.number})" style="font-size:0.75rem;color:#fff;background:var(--color-primary);border:none;border-radius:4px;padding:0.25rem 0.6rem;cursor:pointer;font-weight:600;">PR #${existingPR.number} &#128065;</button>`
            : `<button onclick="openCreatePRModal('${escapeHtml(b.branch_name)}')" style="font-size:0.75rem;color:#16a34a;background:none;border:1px solid #16a34a;border-radius:4px;padding:0.25rem 0.6rem;cursor:pointer;">&#8593; Create PR</button>`;
        return `<div class="branch-item" style="margin-bottom:0.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.4rem;flex-wrap:wrap;">
                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">&#127807; ${escapeHtml(b.branch_name)} <span style="color:var(--text-tertiary);font-size:0.8rem">by ${escapeHtml(b.creator_name)}</span></span>
                <div style="display:flex;gap:0.3rem;flex-shrink:0;align-items:center;flex-wrap:wrap;">
                    ${sonarQGChip(sq.status, sq.url)}
                    ${prBtn}
                    <button onclick="toggleCommits(${id}, '${escapeHtml(b.branch_name)}', this)" style="font-size:0.75rem;color:var(--color-primary);background:none;border:none;cursor:pointer;">&#9654; Commits</button>
                </div>
            </div>
            <div class="commits-panel" style="display:none;margin-top:0.4rem;padding-left:0.75rem;border-left:2px solid #e5e7eb;"></div>
        </div>`;
    }).join('');
```

**Step 3: Probar en navegador**

1. Ir a `http://localhost/teamapp/public?page=issues`
2. Abrir una issue con ramas — si SonarQube está configurado y tiene datos para esa rama, debe aparecer el chip `✓ QG Passed` o `✗ QG Failed` con enlace a Sonar
3. Sin SonarQube configurado: no aparece nada (graceful)

**Step 4: Commit**

```bash
git add app/assets/js/issues.js
git commit -m "feat: show SonarQube QG status chip per branch in issue side panel"
```

---

### Task 5: Chips QG en `loadFullIssueBranches()` — modal issue expandida

**Files:**
- Modify: `app/assets/js/issues.js` (función `loadFullIssueBranches`, línea ~1040)

**Step 1: Modificar `loadFullIssueBranches(id)`**

Localizar el bloque completo (líneas ~1040-1048):
```js
async function loadFullIssueBranches(id) {
    const res = await fetch(`${APP_URL}/app/api/github.php?action=branches&issue_id=${id}`);
    const data = await res.json();
    const branches = data.data || [];
    const el = document.getElementById('fi-branches');
    if (!branches.length) { el.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin ramas</em>'; return; }
    el.innerHTML = branches.map(b =>
        `<div style="padding:0.25rem 0;border-bottom:1px solid var(--border);font-size:0.78rem;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">&#127807; ${escapeHtml(b.branch_name)}</div>`
    ).join('');
}
```

Reemplazar con:
```js
async function loadFullIssueBranches(id) {
    const res = await fetch(`${APP_URL}/app/api/github.php?action=branches&issue_id=${id}`);
    const data = await res.json();
    const branches = data.data || [];
    const el = document.getElementById('fi-branches');
    if (!branches.length) { el.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin ramas</em>'; return; }

    const sonarResults = await Promise.all(
        branches.map(b =>
            fetch(`${APP_URL}/app/api/sonarqube.php?action=status&project_id=${PROJECT_ID}&branch=${encodeURIComponent(b.branch_name)}`)
                .then(r => r.json())
                .catch(() => ({ success: false }))
        )
    );

    el.innerHTML = branches.map((b, i) => {
        const sq = sonarResults[i] || {};
        return `<div style="display:flex;align-items:center;gap:0.4rem;padding:0.3rem 0;border-bottom:1px solid var(--border);flex-wrap:wrap;">
            <span style="font-size:0.78rem;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;">&#127807; ${escapeHtml(b.branch_name)}</span>
            ${sonarQGChip(sq.status, sq.url)}
        </div>`;
    }).join('');
}
```

**Step 2: Probar en navegador**

Abrir una issue en modo expandido → sección "Ramas" debe mostrar el chip de SonarQube.

**Step 3: Commit**

```bash
git add app/assets/js/issues.js
git commit -m "feat: show SonarQube QG status chip in full issue modal branches"
```

---

### Verificación final

```bash
php -l app/api/sonarqube.php
php -l app/pages/project.php
```

1. **Sin SonarQube configurado** — project.php muestra "SonarQube no configurado", branches muestran nada → correcto
2. **Con SonarQube configurado y activo** — project.php muestra métricas + botón "Ver en SonarQube ↗", branches muestran chip de QG
3. **Token inválido** — muestra error "Token inválido (401)" en el status card
4. **SonarQube offline** — error graceful, no rompe el resto de la página
