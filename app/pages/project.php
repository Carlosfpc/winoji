<?php
$page_title = 'Proyectos';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-content">

    <!-- ── Sección Proyectos ───────────────────────────────────────── -->
    <div class="page-header">
        <h2>Proyectos</h2>
    </div>

    <!-- Lista de proyectos -->
    <div id="projects-list" class="card card-flush mb-6">
        <div class="empty-state">Cargando proyectos...</div>
    </div>

    <!-- Crear nuevo proyecto -->
    <div class="card mb-8">
        <h3 class="section-title mb-3">Nuevo proyecto</h3>
        <div class="form-row">
            <input type="text" id="new-project-name" placeholder="Nombre del proyecto" class="form-input flex-1"
                style="min-width:180px;">
            <input type="text" id="new-project-desc" placeholder="Descripción (opcional)" class="form-input"
                style="flex:2;min-width:200px;">
            <button class="btn btn-primary nowrap" id="create-project-btn">+ Crear</button>
        </div>
    </div>

    <!-- ── Sección Tipos de Issue ─────────────────────────────────── -->
    <h2 class="mb-2">Tipos de Issue</h2>
    <p class="text-sm text-muted mb-4">
        Categoriza las issues del proyecto activo. Los tipos se muestran en el listado y sirven como filtro.
    </p>

    <div id="issue-types-list" class="card card-flush mb-4">
        <div class="empty-state">Cargando tipos...</div>
    </div>

    <!-- Crear nuevo tipo -->
    <div class="card mb-8">
        <h3 class="section-title mb-3">Nuevo tipo</h3>
        <div class="form-row">
            <div style="flex:2;min-width:130px;">
                <label class="form-label">Nombre</label>
                <input type="text" id="new-type-name" placeholder="ej. Epic" class="form-input w-full">
            </div>
            <div style="flex:3;min-width:160px;">
                <label class="form-label">Descripción (opcional)</label>
                <input type="text" id="new-type-desc" placeholder="Qué representa este tipo" class="form-input w-full">
            </div>
            <div class="flex-shrink-0 flex items-center gap-1" style="align-self:flex-end;padding-bottom:0.05rem;">
                <input type="color" id="new-type-color" value="#6b7280" title="Color"
                    style="height:2.1rem;width:2.6rem;padding:0.1rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;">
                <button class="btn btn-primary nowrap" id="create-type-btn">+ Crear</button>
            </div>
        </div>
    </div>

    <!-- ── Sección GitHub (proyecto activo) ───────────────────────── -->
    <h2 class="mb-2">GitHub</h2>
    <p class="text-sm text-muted mb-4">
        Conecta un repositorio al proyecto activo para crear ramas y hacer seguimiento de pull requests.
    </p>

    <div id="github-repo-card" class="card mb-6"></div>

    <?php if (has_role('admin')): ?>
        <div class="card mb-6">
            <h3 class="section-title mb-4" id="repo-form-title">Conectar Repositorio</h3>
            <div class="form-group">
                <label class="form-label">
                    Repositorio <span class="text-muted">(owner/repo-name)</span>
                </label>
                <input type="text" id="gh-repo-name" placeholder="acme/mi-proyecto" class="form-input w-full">
            </div>
            <div class="form-group mb-4">
                <label class="form-label">Token de Acceso Personal</label>
                <input type="password" id="gh-repo-token" placeholder="ghp_..." class="form-input w-full">
                <p class="form-hint">
                    GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)<br>
                    Scope requerido: <code>repo</code>
                </p>
            </div>
            <div class="flex gap-2 items-center">
                <button class="btn btn-primary" id="connect-repo-btn">Conectar Repositorio</button>
                <button class="btn btn-danger" id="disconnect-repo-btn" style="display:none;">Desconectar</button>
            </div>
            <p id="gh-connect-msg" class="text-sm mt-3" style="display:none;"></p>
        </div>
    <?php endif; ?>

    <div id="repo-branches-section" style="display:none;">
        <h3 class="section-title mb-2">Ramas del Repositorio</h3>
        <p class="text-sm text-muted mb-3">Todas las ramas del repositorio conectado.</p>
        <div id="repo-branches-list" class="card"></div>
    </div>

    <!-- Issue Templates -->
    <div class="card mt-6">
        <div class="section-header">
            <h3 class="section-title">Plantillas de Issues</h3>
            <button class="btn btn-primary btn-sm" id="new-template-btn">+ Nueva Plantilla</button>
        </div>
        <div id="templates-list"></div>

        <div id="template-form"
            style="display:none;border-top:1px solid var(--border);padding-top:1rem;margin-top:1rem;">
            <input type="hidden" id="tpl-id">
            <div class="flex-col gap-2">
                <input type="text" id="tpl-name" placeholder="Nombre de la plantilla *" class="form-input w-full">
                <input type="text" id="tpl-title" placeholder="Título pre-rellenado (opcional)"
                    class="form-input w-full">
                <textarea id="tpl-desc" placeholder="Descripción pre-rellenada (opcional)" rows="3"
                    class="form-textarea w-full"></textarea>
                <div class="flex gap-2">
                    <select id="tpl-type" class="form-select flex-1">
                        <option value="">Sin tipo</option>
                    </select>
                    <select id="tpl-priority" class="form-select flex-1">
                        <option value="low">Baja</option>
                        <option value="medium" selected>Media</option>
                        <option value="high">Alta</option>
                        <option value="critical">Crítica</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button class="btn btn-primary" id="tpl-save-btn">Guardar</button>
                    <button class="btn btn-secondary" id="tpl-cancel-btn">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

</div><!-- /page-content -->

<script>
    const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');

    /* ── Project list ─────────────────────────────────────────────── */
    async function loadProjects() {
        const container = document.getElementById('projects-list');
        const res = await fetch(`${APP_URL}/app/api/projects.php?action=list`);
        const data = await res.json();
        const projects = data.data || [];

        if (!projects.length) {
            container.innerHTML = '<div class="empty-state">Sin proyectos. Crea el primero abajo.</div>';
            return;
        }

        container.innerHTML = projects.map((p, i) => {
            const isActive = p.id === PROJECT_ID;
            const lastRow = i === projects.length - 1;
            return `
        <div data-pid="${p.id}" class="list-row" style="${lastRow ? 'border-bottom:none;' : ''}">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="proj-name" style="font-size:0.95rem;font-weight:${isActive ? '700' : '500'};color:${isActive ? 'var(--color-primary)' : 'var(--text-primary)'};">${p.name.replace(/</g, '&lt;')}</span>
                    ${isActive ? '<span class="badge" style="background:var(--color-primary-light);color:var(--color-primary);font-size:0.7rem;font-weight:600;">Activo</span>' : ''}
                </div>
                ${p.description ? `<div class="text-sm text-muted truncate mt-1">${p.description.replace(/</g, '&lt;')}</div>` : ''}
            </div>
            <div class="flex gap-1 flex-shrink-0 items-center">
                ${!isActive ? `<button onclick="setActiveProject(${p.id})" class="btn btn-secondary btn-xs">Activar</button>` : ''}
                <button onclick="startRename(${p.id}, this)" data-pid="${p.id}" class="btn btn-secondary btn-xs" title="Renombrar">✏️</button>
                <?php if (has_role('admin')): ?>
                <button onclick="deleteProject(${p.id}, '${p.name.replace(/'/g, "\\'")}')" class="btn btn-xs btn-danger-outline" title="Eliminar">🗑</button>
                <?php endif; ?>
            </div>
        </div>`;
        }).join('');
    }

    function setActiveProject(id) {
        localStorage.setItem('active_project_id', id);
        window.location.reload();
    }

    function startRename(id, btn) {
        const row = btn.closest('[data-pid]');
        const span = row.querySelector('.proj-name');
        const current = span.textContent;
        span.outerHTML = `<input id="rename-input-${id}" value="${current.replace(/"/g, '&quot;')}"
        class="form-input" style="font-size:0.95rem;width:180px;"
        onkeydown="handleRenameKey(event,${id})" onblur="cancelRename(this, '${current.replace(/'/g, "\\'")}')" autofocus>`;
        row.querySelector(`button[onclick*="startRename"]`).style.display = 'none';
    }

    async function handleRenameKey(e, id) {
        if (e.key === 'Escape') { window.location.reload(); return; }
        if (e.key !== 'Enter') return;
        const input = e.target;
        const name = input.value.trim();
        if (!name) return;
        const r = await fetch(`${APP_URL}/app/api/projects.php?action=update`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, name })
        });
        const d = await r.json();
        if (d.success) window.location.reload();
        else showToast(d.error || 'Error al renombrar', 'error');
    }

    function cancelRename(input, original) {
        if (document.activeElement !== input) window.location.reload();
    }

    function deleteProject(id, name) {
        showConfirm(
            `¿Eliminar el proyecto "${name}"? Se eliminarán todas sus páginas, issues y datos. Esta acción no se puede deshacer.`,
            async () => {
                const r = await fetch(`${APP_URL}/app/api/projects.php?action=delete`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const d = await r.json();
                if (d.success) {
                    if (id === PROJECT_ID) localStorage.removeItem('active_project_id');
                    window.location.reload();
                } else {
                    showToast(d.error || 'Error al eliminar', 'error');
                }
            },
            { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' }
        );
    }

    document.getElementById('create-project-btn').addEventListener('click', async () => {
        const nameInput = document.getElementById('new-project-name');
        const descInput = document.getElementById('new-project-desc');
        const name = nameInput.value.trim();
        if (!name) { showToast('El nombre del proyecto es obligatorio', 'error'); nameInput.focus(); return; }
        const btn = document.getElementById('create-project-btn');
        btn.disabled = true; btn.textContent = 'Creando...';
        const r = await fetch(`${APP_URL}/app/api/projects.php?action=create`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, description: descInput.value.trim() })
        });
        const d = await r.json();
        btn.disabled = false; btn.textContent = '+ Crear';
        if (d.success) {
            localStorage.setItem('active_project_id', d.id);
            window.location.reload();
        } else {
            showToast(d.error || 'Error al crear el proyecto', 'error');
        }
    });

    /* ── Issue types ──────────────────────────────────────────────── */
    async function loadIssueTypesList() {
        const container = document.getElementById('issue-types-list');
        if (!PROJECT_ID) {
            container.innerHTML = '<div class="empty-state">Activa un proyecto para ver sus tipos.</div>';
            return;
        }
        const res = await fetch(`${APP_URL}/app/api/issue_types.php?action=list&project_id=${PROJECT_ID}`);
        const data = await res.json();
        const types = data.data || [];

        if (!types.length) {
            container.innerHTML = '<div class="empty-state">Sin tipos. Crea el primero abajo.</div>';
            return;
        }

        container.innerHTML = types.map((t, i) => {
            const lastRow = i === types.length - 1;
            return `
        <div data-tid="${t.id}" class="list-row" style="${lastRow ? 'border-bottom:none;' : ''}">
            <span style="width:12px;height:12px;border-radius:50%;background:${escapeProjectHtml(t.color)};flex-shrink:0;display:inline-block;"></span>
            <div class="flex-1 min-w-0">
                <span class="font-semibold" style="font-size:0.9rem;">${escapeProjectHtml(t.name)}</span>
                ${t.description ? `<span class="text-sm text-muted ml-1">${escapeProjectHtml(t.description)}</span>` : ''}
            </div>
            <div class="flex gap-1 flex-shrink-0">
                <button onclick="editIssueType(${t.id}, '${escapeAttr(t.name)}', '${escapeAttr(t.color)}', '${escapeAttr(t.description || '')}')"
                    class="btn btn-secondary btn-xs" title="Editar">✏️</button>
                <button onclick="deleteIssueType(${t.id}, '${escapeAttr(t.name)}')"
                    class="btn btn-xs btn-danger-outline" title="Eliminar">🗑</button>
            </div>
        </div>`;
        }).join('');
    }

    function escapeProjectHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    function escapeAttr(str) {
        return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    function editIssueType(id, name, color, desc) {
        const row = document.querySelector(`[data-tid="${id}"]`);
        if (!row) return;
        row.innerHTML = `
        <input type="color" value="${escapeProjectHtml(color)}" id="et-color-${id}"
            style="height:1.8rem;width:2.2rem;padding:0.05rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;">
        <input type="text" value="${escapeProjectHtml(name)}" id="et-name-${id}"
            class="form-input flex-1" style="font-size:0.9rem;">
        <input type="text" value="${escapeProjectHtml(desc)}" id="et-desc-${id}" placeholder="Descripción"
            class="form-input" style="flex:2;font-size:0.875rem;">
        <button onclick="saveIssueType(${id})" class="btn btn-primary btn-sm nowrap">Guardar</button>
        <button onclick="loadIssueTypesList()" class="btn-link text-muted" style="font-size:1.1rem;">✕</button>`;
        row.classList.add('flex', 'gap-2', 'items-center');
        document.getElementById(`et-name-${id}`).focus();
    }

    async function saveIssueType(id) {
        const name = document.getElementById(`et-name-${id}`)?.value.trim();
        const color = document.getElementById(`et-color-${id}`)?.value || '#6b7280';
        const desc = document.getElementById(`et-desc-${id}`)?.value.trim() || null;
        if (!name) { showToast('El nombre es obligatorio', 'error'); return; }
        const r = await fetch(`${APP_URL}/app/api/issue_types.php?action=update`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, name, color, description: desc })
        });
        const d = await r.json();
        if (d.success) loadIssueTypesList();
        else showToast(d.error || 'Error al guardar', 'error');
    }

    function deleteIssueType(id, name) {
        showConfirm(`¿Eliminar el tipo "${name}"?`, async () => {
            const r = await fetch(`${APP_URL}/app/api/issue_types.php?action=delete`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) loadIssueTypesList();
            else showToast(d.error, 'error');
        }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger' });
    }

    document.getElementById('create-type-btn').addEventListener('click', async () => {
        const name = document.getElementById('new-type-name').value.trim();
        const desc = document.getElementById('new-type-desc').value.trim() || null;
        const color = document.getElementById('new-type-color').value || '#6b7280';
        if (!name) { showToast('El nombre del tipo es obligatorio', 'error'); document.getElementById('new-type-name').focus(); return; }
        if (!PROJECT_ID) { showToast('Activa un proyecto primero', 'error'); return; }
        const btn = document.getElementById('create-type-btn');
        btn.disabled = true; btn.textContent = 'Creando...';
        const r = await fetch(`${APP_URL}/app/api/issue_types.php?action=create`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: PROJECT_ID, name, color, description: desc })
        });
        const d = await r.json();
        btn.disabled = false; btn.textContent = '+ Crear';
        if (d.success) {
            document.getElementById('new-type-name').value = '';
            document.getElementById('new-type-desc').value = '';
            document.getElementById('new-type-color').value = '#6b7280';
            loadIssueTypesList();
        } else {
            showToast(d.error || 'Error al crear tipo', 'error');
        }
    });

    /* ── GitHub section ───────────────────────────────────────────── */
    async function loadRepoStatus() {
        if (!PROJECT_ID) {
            document.getElementById('github-repo-card').innerHTML =
                '<p class="text-muted">No hay proyecto activo. Activa uno arriba.</p>';
            return;
        }
        const res = await fetch(`${APP_URL}/app/api/github.php?action=repo_status&project_id=${PROJECT_ID}`);
        const data = await res.json();
        const card = document.getElementById('github-repo-card');
        const disconnectBtn = document.getElementById('disconnect-repo-btn');
        const branchSection = document.getElementById('repo-branches-section');
        const formTitle = document.getElementById('repo-form-title');

        if (data.connected) {
            card.innerHTML = `
            <div class="flex items-center gap-4">
                <span style="font-size:2rem;">&#128279;</span>
                <div class="flex-1">
                    <div class="font-semibold">${data.repo}</div>
                    <div class="text-sm text-muted mt-1">Conectado al proyecto activo</div>
                </div>
                <a href="https://github.com/${data.repo}" target="_blank"
                    class="text-sm" style="color:var(--color-primary);text-decoration:none;">
                    Ver en GitHub ↗
                </a>
            </div>`;
            if (disconnectBtn) disconnectBtn.style.display = 'inline-flex';
            if (formTitle) formTitle.textContent = 'Actualizar Repositorio';
            const nameInput = document.getElementById('gh-repo-name');
            if (nameInput) nameInput.value = data.repo;
            branchSection.style.display = 'block';
            loadRepoBranches();
        } else {
            card.innerHTML = '<p class="text-muted">No hay repositorio conectado a este proyecto.</p>';
            if (disconnectBtn) disconnectBtn.style.display = 'none';
            if (formTitle) formTitle.textContent = 'Conectar Repositorio';
            branchSection.style.display = 'none';
        }
    }

    async function loadRepoBranches() {
        const list = document.getElementById('repo-branches-list');
        list.innerHTML = '<span class="text-muted text-sm">Cargando ramas...</span>';
        const res = await fetch(`${APP_URL}/app/api/github.php?action=repo_branches&project_id=${PROJECT_ID}`);
        const data = await res.json();
        if (!data.success) {
            list.innerHTML = `<span class="text-sm text-danger">${data.error || 'No se pudieron cargar las ramas'}</span>`;
            return;
        }
        const branches = data.data || [];
        if (!branches.length) {
            list.innerHTML = '<span class="text-muted text-sm">No hay ramas en el repositorio.</span>';
            return;
        }
        list.innerHTML = `<div class="flex flex-wrap gap-1 p-3">` +
            branches.map(b => `
            <span class="badge badge-code text-xs">
                &#127807; ${b.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
            </span>`
            ).join('') + `</div>`;
    }

    <?php if (has_role('admin')): ?>
    document.getElementById('connect-repo-btn').addEventListener('click', async () => {
        const repo = document.getElementById('gh-repo-name').value.trim();
        const token = document.getElementById('gh-repo-token').value.trim();
        const msg = document.getElementById('gh-connect-msg');
        if (!repo || !token) { showToast('El nombre del repositorio y el token son obligatorios', 'error'); return; }
        const btn = document.getElementById('connect-repo-btn');
        btn.disabled = true; btn.textContent = 'Conectando...';
        const res = await fetch(`${APP_URL}/app/api/github.php?action=connect_repo`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: PROJECT_ID, repo_full_name: repo, access_token: token })
        });
        const data = await res.json();
        btn.disabled = false; btn.textContent = 'Conectar Repositorio';
        if (data.success) {
            document.getElementById('gh-repo-token').value = '';
            msg.style.color = '#059669';
            msg.textContent = `✓ Conectado a ${repo}`;
            msg.style.display = 'block';
            loadRepoStatus();
        } else {
            msg.style.color = '#dc2626';
            msg.textContent = data.error || 'Error al conectar';
            msg.style.display = 'block';
        }
    });

    document.getElementById('disconnect-repo-btn').addEventListener('click', () => {
        showConfirm('¿Desconectar este repositorio del proyecto?', async () => {
            await fetch(`${APP_URL}/app/api/github.php?action=disconnect_repo`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_id: PROJECT_ID })
            });
            document.getElementById('gh-repo-name').value = '';
            document.getElementById('gh-connect-msg').style.display = 'none';
            loadRepoStatus();
        });
    });
    <?php endif; ?>

    // ── Issue Templates ───────────────────────────────────────────────────────────
    let _tplCache = [];

    async function loadTemplatesList() {
        if (!PROJECT_ID) return;
        const res = await fetch(`${APP_URL}/app/api/templates.php?action=list&project_id=${PROJECT_ID}`);
        const data = await res.json();
        _tplCache = data.data || [];
        const list = document.getElementById('templates-list');
        if (!list) return;
        if (!_tplCache.length) {
            list.innerHTML = '<p class="text-muted text-sm" style="margin:0.5rem 0;">No hay plantillas todavía.</p>';
            return;
        }
        list.innerHTML = _tplCache.map(t => `
        <div class="list-row" style="border-bottom:1px solid var(--border);">
            <div class="flex-1">
                <strong style="font-size:0.9rem;">${escapeProjectHtml(t.name)}</strong>
                ${t.type_name ? `<span class="text-sm text-muted ml-2">${escapeProjectHtml(t.type_name)}</span>` : ''}
                <span class="text-sm text-muted ml-2">${escapeProjectHtml(t.priority)}</span>
            </div>
            <div class="flex gap-1">
                <button onclick="editTemplate(${t.id})" class="btn btn-secondary btn-xs">&#9998; Editar</button>
                <button onclick="deleteTemplate(${t.id})" class="btn btn-xs btn-danger-outline">&#128465;</button>
            </div>
        </div>`).join('');
    }

    function editTemplate(id) {
        const tpl = _tplCache.find(t => t.id == id);
        if (!tpl) return;
        document.getElementById('tpl-id').value = tpl.id;
        document.getElementById('tpl-name').value = tpl.name;
        document.getElementById('tpl-title').value = tpl.title || '';
        document.getElementById('tpl-desc').value = tpl.description || '';
        document.getElementById('tpl-type').value = tpl.type_id || '';
        document.getElementById('tpl-priority').value = tpl.priority;
        document.getElementById('template-form').style.display = 'block';
    }

    async function deleteTemplate(id) {
        showConfirm('¿Eliminar esta plantilla?', async () => {
            await apiFetch(`${APP_URL}/app/api/templates.php?action=delete`, { id });
            await loadTemplatesList();
            showToast('Plantilla eliminada', 'success');
        }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
    }

    document.getElementById('new-template-btn')?.addEventListener('click', () => {
        document.getElementById('tpl-id').value = '';
        ['tpl-name', 'tpl-title', 'tpl-desc'].forEach(id => { document.getElementById(id).value = ''; });
        document.getElementById('tpl-priority').value = 'medium';
        document.getElementById('tpl-type').value = '';
        document.getElementById('template-form').style.display = 'block';
    });
    document.getElementById('tpl-cancel-btn')?.addEventListener('click', () => {
        document.getElementById('template-form').style.display = 'none';
    });
    document.getElementById('tpl-save-btn')?.addEventListener('click', async () => {
        const id = document.getElementById('tpl-id').value;
        const name = document.getElementById('tpl-name').value.trim();
        if (!name) return showToast('El nombre es obligatorio', 'error');
        const payload = {
            name,
            title: document.getElementById('tpl-title').value.trim(),
            description: document.getElementById('tpl-desc').value.trim(),
            type_id: document.getElementById('tpl-type').value || null,
            priority: document.getElementById('tpl-priority').value,
        };
        const action = id ? 'update' : 'create';
        if (id) payload.id = parseInt(id);
        else payload.project_id = PROJECT_ID;
        const data = await apiFetch(`${APP_URL}/app/api/templates.php?action=${action}`, payload);
        if (data.success) {
            document.getElementById('template-form').style.display = 'none';
            await loadTemplatesList();
            showToast(id ? 'Plantilla actualizada' : 'Plantilla creada', 'success');
        } else {
            showToast(data.error || 'Error al guardar', 'error');
        }
    });

    // Populate tpl-type select with issue types
    fetch(`${APP_URL}/app/api/issue_types.php?action=list&project_id=${PROJECT_ID}`)
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('tpl-type');
            if (!sel) return;
            (data.data || []).forEach(t => {
                const o = document.createElement('option');
                o.value = t.id; o.textContent = t.name;
                sel.appendChild(o);
            });
        });

    loadTemplatesList();

    loadProjects();
    loadIssueTypesList();
    loadRepoStatus();

</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>