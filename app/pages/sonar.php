<?php
$page_title = 'Sonar';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-content">

    <div class="page-header">
        <h2>SonarQube</h2>
    </div>

    <!-- Branch / PR selector (persistent, always shown once configured) -->
    <div id="branch-bar" class="flex items-center gap-2 mb-3" style="display:none!important;">
        <span class="text-sm text-muted flex-shrink-0">Análisis:</span>
        <select id="branch-select" class="form-input"
            style="font-size:0.85rem;padding:0.3rem 0.6rem;min-width:220px;max-width:100%;">
            <option value="">Cargando...</option>
        </select>
    </div>

    <!-- Quality Gate & summary -->
    <div id="sonar-status-card" class="card mb-4">
        <p class="text-muted text-sm">Cargando...</p>
    </div>

    <!-- Detailed metrics (hidden until loaded) -->
    <div id="sonar-detail" style="display:none;">

        <!-- Ratings -->
        <h3 class="section-title mb-2" style="text-align:center;">Ratings</h3>
        <div id="sonar-ratings"
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
        </div>

        <!-- Main issues -->
        <h3 class="section-title mb-2" style="text-align:center;">Issues y deuda técnica</h3>
        <div id="sonar-issues"
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
        </div>

        <!-- Coverage -->
        <h3 class="section-title mb-2" style="text-align:center;">Cobertura de pruebas</h3>
        <div id="sonar-coverage"
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
        </div>

        <!-- Duplication -->
        <h3 class="section-title mb-2" style="text-align:center;">Duplicación</h3>
        <div id="sonar-duplication"
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
        </div>

        <!-- Size -->
        <h3 class="section-title mb-2" style="text-align:center;">Tamaño del código</h3>
        <div id="sonar-size"
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
        </div>

        <!-- Complexity -->
        <h3 class="section-title mb-2" style="text-align:center;">Complejidad</h3>
        <div id="sonar-complexity"
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
        </div>

        <!-- New code -->
        <div id="sonar-newcode-section" style="display:none;margin-bottom:1.5rem;">
            <h3 class="section-title mb-2" style="text-align:center;">Código nuevo (período)</h3>
            <div id="sonar-newcode"
                style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;"></div>
        </div>

    </div>

    <!-- Admin config -->
    <?php if (has_role('admin')): ?>
        <div class="card mt-4" id="sonar-config-card">
            <h3 class="section-title mb-4">Configurar SonarQube</h3>
            <div class="form-group">
                <label class="form-label">URL de SonarQube</label>
                <input type="text" id="sonar-url-input" placeholder="http://localhost:9000" class="form-input w-full">
            </div>
            <div class="form-group">
                <label class="form-label">Token de Acceso</label>
                <input type="password" id="sonar-token-input" placeholder="squ_..." class="form-input w-full">
                <p class="form-hint">SonarQube → My Account → Security → Generate Token</p>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">Project Key</label>
                <input type="text" id="sonar-project-key-input" placeholder="mi-proyecto" class="form-input w-full">
                <p class="form-hint">Visible en SonarQube → Project → Project Information</p>
            </div>
            <div class="flex gap-2 items-center">
                <button class="btn btn-primary" id="sonar-save-btn">Guardar</button>
                <button class="btn btn-danger" id="sonar-delete-btn" style="display:none;">Desconectar</button>
            </div>
            <p id="sonar-config-msg" class="text-sm mt-3 hidden"></p>
        </div>
    <?php endif; ?>

</div><!-- /page-content -->

<script>
    const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
    let currentBranch = '';

    /* ── Helpers ──────────────────────────────────────────────────────────── */
    const SONAR_LABELS = { PASSED: 'PASSED', FAILED: 'FAILED', WARN: 'WARN', ERROR: 'ERROR', NONE: 'Sin análisis' };
    const SONAR_COLORS = { PASSED: '#16a34a', FAILED: '#dc2626', WARN: '#d97706', ERROR: '#dc2626', NONE: 'var(--text-tertiary)' };
    const SONAR_BGS = { PASSED: '#dcfce7', FAILED: '#fee2e2', WARN: '#fef9c3', ERROR: '#fee2e2', NONE: 'var(--bg-secondary)' };

    const RATING_L = { '1': 'A', '2': 'B', '3': 'C', '4': 'D', '5': 'E' };
    const RATING_C = { '1': '#16a34a', '2': '#65a30d', '3': '#d97706', '4': '#ea580c', '5': '#dc2626' };
    const RATING_B = { '1': '#dcfce7', '2': '#ecfccb', '3': '#fef9c3', '4': '#ffedd5', '5': '#fee2e2' };

    function qgChip(status) {
        const label = SONAR_LABELS[status] || status;
        const color = SONAR_COLORS[status] || 'var(--text-tertiary)';
        const bg = SONAR_BGS[status] || 'var(--bg-secondary)';
        const icon = status === 'PASSED' ? '✓' : status === 'NONE' ? '○' : '✗';
        return `<span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.3rem 0.9rem;border-radius:999px;background:${bg};color:${color};font-size:0.85rem;font-weight:700;">${icon} QG ${label}</span>`;
    }

    function ratingHtml(v) {
        if (!v || v === '—') return `<span style="font-size:2rem;font-weight:800;color:var(--text-tertiary);">—</span>`;
        const k = String(Math.round(parseFloat(v))); // normaliza "1.0" → "1"
        return `<span style="font-size:2rem;font-weight:800;background:${RATING_B[k] || 'var(--bg-secondary)'};color:${RATING_C[k] || 'var(--text-tertiary)'};border-radius:var(--radius-sm);padding:0.15rem 0.6rem;">${RATING_L[k] || v}</span>`;
    }

    function ratingBox(val, label, desc) {
        return `<div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
        <div>${ratingHtml(val)}</div>
        <div class="text-xs font-semibold mt-2" style="color:var(--text-primary);">${label}</div>
        <div class="text-xs mt-1" style="color:var(--text-tertiary);font-style:italic;line-height:1.3;">${desc}</div>
    </div>`;
    }

    function metricBox(value, label, color, desc) {
        return `<div class="text-center p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
        <div style="font-size:1.5rem;font-weight:800;color:${color};">${value}</div>
        <div class="text-xs font-semibold mt-1" style="color:var(--text-primary);">${label}</div>
        <div class="text-xs mt-1" style="color:var(--text-tertiary);font-style:italic;line-height:1.3;">${desc}</div>
    </div>`;
    }

    const fmt = v => (v === undefined || v === null || v === '—') ? '—' : v;
    const pct = v => v !== undefined && v !== null && v !== '—' ? parseFloat(v).toFixed(1) + '%' : '—';
    const num = v => v && v !== '—' ? parseInt(v).toLocaleString('es') : '—';
    const debt = v => {
        if (!v || v === '—') return '—';
        const mins = parseInt(v);
        if (mins < 60) return mins + 'min';
        const h = Math.floor(mins / 60);
        if (h < 8) return h + 'h' + (mins % 60 ? ' ' + (mins % 60) + 'min' : '');
        const d = Math.floor(h / 8);
        return d + 'd' + (h % 8 ? ' ' + (h % 8) + 'h' : '');
    };

    function formatDate(iso) {
        if (!iso) return null;
        try { return new Date(iso).toLocaleString('es-ES', { dateStyle: 'medium', timeStyle: 'short' }); }
        catch (e) { return iso; }
    }

    /* ── Branch / PR bar (run once) ──────────────────────────────────────── */
    function initBranchBar() {
        const bar = document.getElementById('branch-bar');
        const sel = document.getElementById('branch-select');
        if (!bar || !sel) return;

        bar.setAttribute('style', 'display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;');

        sel.addEventListener('change', () => {
            currentBranch = sel.value;
            loadSonarStatus();
        });

        fetch(`${APP_URL}/app/api/sonarqube.php?action=branches&project_id=${PROJECT_ID}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { sel.innerHTML = '<option value="">Error al cargar</option>'; return; }

                let opts = '';
                const branches = data.branches || [];
                if (branches.length > 0) {
                    opts += '<optgroup label="Ramas">';
                    branches.forEach(b => {
                        const label = b.isMain ? `${b.name} (principal)` : b.name;
                        opts += `<option value="branch:${b.name}">${label}</option>`;
                    });
                    opts += '</optgroup>';
                }

                const prs = data.pullRequests || [];
                if (prs.length > 0) {
                    opts += '<optgroup label="Pull Requests">';
                    prs.forEach(pr => {
                        const title = pr.title ? ` – ${pr.title}` : '';
                        opts += `<option value="pr:${pr.key}">PR #${pr.key}${title}</option>`;
                    });
                    opts += '</optgroup>';
                }

                if (!opts) { sel.innerHTML = '<option value="">Sin análisis disponibles</option>'; return; }
                sel.innerHTML = opts;

                // Pre-select main branch (already loaded by the initial loadSonarStatus call)
                const main = branches.find(b => b.isMain);
                if (main) {
                    sel.value = `branch:${main.name}`;
                    currentBranch = sel.value;
                }
            })
            .catch(() => { sel.innerHTML = '<option value="">Error</option>'; });
    }

    /* ── Main loader ──────────────────────────────────────────────────────── */
    async function loadSonarStatus() {
        const card = document.getElementById('sonar-status-card');
        if (!PROJECT_ID || !card) {
            if (card) card.innerHTML = '<p class="text-muted text-sm">Activa un proyecto para ver el análisis de SonarQube.</p>';
            return;
        }

        card.innerHTML = '<p class="text-muted text-sm">Cargando...</p>';
        document.getElementById('sonar-detail').style.display = 'none';

        // Pre-fill config form
        try {
            const cfgData = await fetch(`${APP_URL}/app/api/sonarqube.php?action=config&project_id=${PROJECT_ID}`).then(r => r.json());
            if (cfgData.data) {
                const urlEl = document.getElementById('sonar-url-input');
                const keyEl = document.getElementById('sonar-project-key-input');
                const delBtn = document.getElementById('sonar-delete-btn');
                if (urlEl) urlEl.value = cfgData.data.sonar_url || '';
                if (keyEl) keyEl.value = cfgData.data.sonar_project_key || '';
                if (delBtn) delBtn.style.display = 'inline-flex';
            }
        } catch (e) { }

        let branchParam = '', prParam = '';
        if (currentBranch.startsWith('pr:')) {
            prParam = currentBranch.slice(3);
        } else if (currentBranch.startsWith('branch:')) {
            branchParam = currentBranch.slice(7);
        }
        const sel = document.getElementById('branch-select');
        const contextLabel = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '';

        const url = `${APP_URL}/app/api/sonarqube.php?action=status&project_id=${PROJECT_ID}${branchParam ? '&branch=' + encodeURIComponent(branchParam) : ''}${prParam ? '&pull_request=' + encodeURIComponent(prParam) : ''}`;
        const data = await fetch(url).then(r => r.json());

        if (!data.success) {
            const contextNote = contextLabel ? ` (<code>${contextLabel}</code>)` : '';
            card.innerHTML = `<p class="text-sm" style="color:var(--text-danger,#dc2626);">${data.error === 'SonarQube no configurado'
                    ? 'SonarQube no configurado para este proyecto. Usa el formulario de abajo para conectarlo.'
                    : `Error${contextNote}: <strong>${data.error || 'desconocido'}</strong>`
                }</p>`;
            return;
        }

        const m = data.metrics || {};
        const la = data.last_analysis;
        const analysisInfo = la
            ? `<span class="text-sm text-muted">Último análisis: <strong>${formatDate(la.date)}</strong>${la.version ? ` · v${la.version}` : ''}</span>`
            : '';

        card.innerHTML = `
    <div class="flex items-center gap-3 flex-wrap">
        ${qgChip(data.status)}
        <span class="text-sm text-muted font-mono">${data.project_key}${contextLabel ? ' · ' + contextLabel : ''}</span>
        ${analysisInfo}
        <a href="${data.url}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm flex-shrink-0 ml-auto">Ver en SonarQube ↗</a>
    </div>`;

        // ── Ratings ──
        document.getElementById('sonar-ratings').innerHTML =
            ratingBox(m.security_rating, 'Seguridad', 'Riesgo de vulnerabilidades en el código (A = sin riesgo, E = crítico)') +
            ratingBox(m.reliability_rating, 'Fiabilidad', 'Probabilidad de fallos en producción (A = sin bugs, E = crítico)') +
            ratingBox(m.sqale_rating, 'Mantenibilidad', 'Esfuerzo para dejar el código limpio respecto al tamaño del proyecto') +
            ratingBox(m.security_review_rating, 'Revisión Hotspots', 'Proporción de hotspots de seguridad ya revisados (A = todos revisados)');

        // ── Issues & debt ──
        document.getElementById('sonar-issues').innerHTML =
            metricBox(fmt(m.bugs), 'Bugs', '#dc2626', 'Errores que pueden causar comportamientos incorrectos o fallos en producción') +
            metricBox(fmt(m.vulnerabilities), 'Vulnerabilidades', '#d97706', 'Puntos del código que podrían ser explotados por un atacante') +
            metricBox(fmt(m.security_hotspots), 'Hotspots', '#f59e0b', 'Código sensible a seguridad que requiere revisión manual') +
            metricBox(fmt(m.code_smells), 'Code Smells', '#7c3aed', 'Problemas de mantenibilidad que dificultan futuros cambios o lectura del código') +
            metricBox(debt(m.sqale_index), 'Deuda técnica', '#6b7280', 'Tiempo estimado para corregir todos los code smells del proyecto');

        // ── Coverage ──
        const hasCoverage = m.coverage !== undefined && m.coverage !== '—';
        const hasTests = m.tests !== undefined && m.tests !== '—';
        let covHtml =
            metricBox(pct(m.coverage), 'Cobertura', '#16a34a', 'Porcentaje de líneas de código ejecutadas durante las pruebas automatizadas') +
            metricBox(num(m.lines_to_cover), 'Líneas a cubrir', '#0891b2', 'Número de líneas que deberían estar cubiertas por algún test') +
            metricBox(num(m.uncovered_lines), 'Sin cubrir', '#dc2626', 'Líneas no ejecutadas en ningún test; son puntos ciegos en la calidad');
        if (hasTests) {
            covHtml +=
                metricBox(num(m.tests), 'Tests totales', '#6366f1', 'Número total de casos de prueba ejecutados en el análisis') +
                metricBox(pct(m.test_success_density), 'Éxito de tests', '#16a34a', 'Porcentaje de tests que pasan correctamente sobre el total ejecutado') +
                metricBox(fmt(m.test_failures), 'Fallos', '#dc2626', 'Tests que fallaron: la ejecución terminó pero el resultado no era el esperado') +
                metricBox(fmt(m.test_errors), 'Errores', '#d97706', 'Tests que lanzaron una excepción no controlada durante su ejecución') +
                metricBox(fmt(m.skipped_tests), 'Omitidos', '#6b7280', 'Tests marcados como ignorados o pendientes de implementar');
        }
        document.getElementById('sonar-coverage').innerHTML = hasCoverage
            ? covHtml
            : '<p class="text-muted text-sm">Sin datos de cobertura en este análisis. Asegúrate de configurar el coverage report en tu CI.</p>';

        // ── Duplication ──
        document.getElementById('sonar-duplication').innerHTML =
            metricBox(pct(m.duplicated_lines_density), 'Densidad', '#0891b2', 'Porcentaje de líneas del proyecto que son copias de otras partes del código') +
            metricBox(num(m.duplicated_lines), 'Líneas duplicadas', '#6b7280', 'Número total de líneas que aparecen duplicadas en el proyecto') +
            metricBox(fmt(m.duplicated_blocks), 'Bloques', '#6b7280', 'Número de bloques de código repetidos; cada bloque tiene ≥6 líneas por defecto') +
            metricBox(fmt(m.duplicated_files), 'Ficheros', '#6b7280', 'Ficheros que contienen al menos un bloque de código duplicado');

        // ── Size ──
        document.getElementById('sonar-size').innerHTML =
            metricBox(num(m.ncloc), 'Líneas de código', 'var(--text-primary)', 'Líneas de código sin contar comentarios ni líneas en blanco (NCLOC)') +
            metricBox(num(m.lines), 'Líneas totales', 'var(--text-secondary)', 'Total de líneas incluyendo comentarios, líneas en blanco y código') +
            metricBox(num(m.statements), 'Sentencias', 'var(--text-secondary)', 'Número de sentencias del lenguaje: asignaciones, llamadas, bucles, etc.') +
            metricBox(num(m.functions), 'Funciones', 'var(--text-secondary)', 'Número de funciones, métodos o procedimientos definidos en el proyecto') +
            metricBox(num(m.classes), 'Clases', 'var(--text-secondary)', 'Número de clases, interfaces o tipos definidos en el proyecto') +
            metricBox(num(m.files), 'Ficheros', 'var(--text-secondary)', 'Número de ficheros de código fuente analizados por SonarQube');

        // ── Complexity ──
        document.getElementById('sonar-complexity').innerHTML =
            metricBox(num(m.complexity), 'Complejidad ciclomática', '#7c3aed', 'Número de caminos de ejecución independientes; a mayor valor, más difícil de testear') +
            metricBox(num(m.cognitive_complexity), 'Complejidad cognitiva', '#a855f7', 'Qué tan difícil es de leer y entender el código para un desarrollador humano');

        // ── New code ──
        const hasNew = ['new_bugs', 'new_vulnerabilities', 'new_code_smells'].some(k => m[k] !== undefined && m[k] !== '—');
        if (hasNew) {
            document.getElementById('sonar-newcode').innerHTML =
                metricBox(fmt(m.new_bugs), 'Nuevos bugs', '#dc2626', 'Bugs introducidos en el código nuevo desde el inicio del período de referencia') +
                metricBox(fmt(m.new_vulnerabilities), 'Nuevas vulnerabilidades', '#d97706', 'Vulnerabilidades introducidas en el código añadido en el período actual') +
                metricBox(fmt(m.new_code_smells), 'Nuevos code smells', '#7c3aed', 'Code smells introducidos en el código nuevo del período') +
                metricBox(pct(m.new_coverage), 'Cobertura nueva', '#16a34a', 'Cobertura de tests sobre el código nuevo añadido en el período') +
                metricBox(pct(m.new_duplicated_lines_density), 'Duplicados nuevos', '#0891b2', 'Porcentaje de duplicación en el código nuevo del período');
            document.getElementById('sonar-newcode-section').style.display = 'block';
        }

        document.getElementById('sonar-detail').style.display = 'block';
    }

    /* ── Config save / delete ─────────────────────────────────────────────── */
    <?php if (has_role('admin')): ?>
        document.getElementById('sonar-save-btn').addEventListener('click', async () => {
            const url = document.getElementById('sonar-url-input')?.value.trim();
            const token = document.getElementById('sonar-token-input')?.value.trim();
            const key = document.getElementById('sonar-project-key-input')?.value.trim();
            if (!url || !key) { showToast('URL y Project Key son obligatorios', 'error'); return; }
            if (!token) { showToast('Introduce el token de SonarQube', 'error'); return; }
            const btn = document.getElementById('sonar-save-btn');
            btn.disabled = true; btn.textContent = 'Guardando...';
            const data = await fetch(`${APP_URL}/app/api/sonarqube.php?action=save`, {
                method: 'POST',
                body: JSON.stringify({ project_id: PROJECT_ID, sonar_url: url, sonar_token: token, sonar_project_key: key })
            }).then(r => r.json());
            btn.disabled = false; btn.textContent = 'Guardar';
            if (data.success) {
                showToast('SonarQube configurado correctamente');
                document.getElementById('sonar-token-input').value = '';
                loadSonarStatus();
            } else {
                showToast(data.error || 'Error al guardar', 'error');
            }
        });

        document.getElementById('sonar-delete-btn').addEventListener('click', () => {
            showConfirm('¿Desconectar SonarQube de este proyecto?', async () => {
                const data = await fetch(`${APP_URL}/app/api/sonarqube.php?action=delete`, {
                    method: 'POST',
                    body: JSON.stringify({ project_id: PROJECT_ID })
                }).then(r => r.json());
                if (data.success) {
                    showToast('SonarQube desconectado');
                    document.getElementById('sonar-delete-btn').style.display = 'none';
                    const urlEl = document.getElementById('sonar-url-input');
                    const keyEl = document.getElementById('sonar-project-key-input');
                    if (urlEl) urlEl.value = '';
                    if (keyEl) keyEl.value = '';
                    document.getElementById('sonar-detail').style.display = 'none';
                    loadSonarStatus();
                }
            });
        });
    <?php endif; ?>

    initBranchBar();
    loadSonarStatus();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>