const SPRINT_COLS = ['todo', 'in_progress', 'review', 'done'];
const SPRINT_COL_LABELS = { todo: 'Pendiente', in_progress: 'En curso', review: 'Revisión', done: 'Hecho' };
let activeSprint        = null;
let sprintMembers       = [];
let activeUserFilter    = null; // null = Todos
let sprintLoading       = false;
let lastSprintProjectId = null;

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ─── Sprint page load ─────────────────────────────────────────────────────────

async function loadSprintPage() {
    if (sprintLoading) return;
    sprintLoading = true;
    if (PROJECT_ID !== lastSprintProjectId) {
        activeUserFilter    = null;
        lastSprintProjectId = PROJECT_ID;
    }
    if (!PROJECT_ID) {
        document.getElementById('sprint-kanban-wrap').innerHTML =
            '<div style="padding:2rem;text-align:center;color:var(--text-secondary);">Selecciona un proyecto en el menú lateral.</div>';
        sprintLoading = false;
        return;
    }
    try {
        const res     = await fetch(`${APP_URL}/app/api/sprints.php?action=list&project_id=${PROJECT_ID}`);
        const data    = await res.json();
        const sprints = data.data || [];
        activeSprint  = sprints.find(s => s.status === 'active') || null;

        if (!activeSprint) {
            renderNoActiveSprint();
        } else {
            const [sRes, membersRes] = await Promise.all([
                fetch(`${APP_URL}/app/api/sprints.php?action=get&id=${activeSprint.id}`),
                fetch(`${APP_URL}/app/api/team.php?action=members`)
            ]);
            const sData       = await sRes.json();
            const membersData = await membersRes.json();
            activeSprint  = sData.data;
            sprintMembers = membersData.data || [];
            renderSprintHeader(activeSprint);
            renderSwimlanesKanban(activeSprint.issues || [], sprintMembers);
        }
    } catch(e) {
        document.getElementById('sprint-kanban-wrap').innerHTML =
            '<div style="padding:2rem;text-align:center;color:var(--text-secondary);">Error al cargar el sprint.</div>';
    } finally {
        sprintLoading = false;
    }
    loadBacklog();
}

function renderNoActiveSprint() {
    document.getElementById('sprint-title').textContent = 'Sprint';
    document.getElementById('sprint-dates').textContent = '';
    document.getElementById('sprint-kanban-wrap').innerHTML = `
        <div class="card" style="padding:3rem;text-align:center;color:var(--text-secondary);">
            <div style="font-size:1.5rem;margin-bottom:0.5rem;">&#9654;</div>
            <p style="margin:0 0 1rem;">No hay ningún sprint activo.</p>
            <button class="btn btn-primary" onclick="openSprintModal()">Crear sprint</button>
        </div>`;
}

function renderSprintHeader(sprint) {
    document.getElementById('sprint-title').textContent = sprint.name;
    const [, sm, sd] = sprint.start_date.split('-');
    const [, em, ed] = sprint.end_date.split('-');
    document.getElementById('sprint-dates').textContent = `${sd}/${sm} – ${ed}/${em}`;

    // Story points stats
    const issues    = sprint.issues || [];
    const totalPts  = issues.reduce((s, i) => s + (parseInt(i.story_points, 10) || 0), 0);
    const donePts   = issues.filter(i => i.status === 'done').reduce((s, i) => s + (parseInt(i.story_points, 10) || 0), 0);
    const statsEl   = document.getElementById('sprint-stats');
    if (statsEl && totalPts > 0) {
        const pct     = Math.round((donePts / totalPts) * 100);
        const barFill = `<span style="display:inline-block;width:${pct}%;height:100%;background:var(--color-primary);border-radius:999px;transition:width 0.3s;"></span>`;
        const bar     = `<span style="display:inline-block;width:64px;height:6px;background:#e5e7eb;border-radius:999px;vertical-align:middle;overflow:hidden;">${barFill}</span>`;
        statsEl.innerHTML = `${bar} <strong>${donePts}</strong>/${totalPts} pts (${pct}%)`;
        statsEl.style.display = '';
    } else if (statsEl) {
        statsEl.style.display = 'none';
    }
}

// ─── Sprint Kanban con swimlanes ──────────────────────────────────────────────

function renderFilterChips(members) {
    const wrap     = document.getElementById('sprint-kanban-wrap');
    const chipsDiv = document.createElement('div');
    chipsDiv.style.cssText = 'display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.75rem;align-items:center;';

    const allActive = activeUserFilter === null;
    const allChip   = document.createElement('button');
    allChip.textContent = 'Todos';
    allChip.style.cssText = `padding:0.25rem 0.75rem;border-radius:999px;font-size:0.8rem;cursor:pointer;border:1px solid var(--border);background:${allActive ? 'var(--color-primary)' : 'var(--bg-card)'};color:${allActive ? '#fff' : 'var(--text-primary)'};`;
    allChip.addEventListener('click', () => {
        activeUserFilter = null;
        renderSwimlanesKanban(activeSprint.issues || [], sprintMembers);
    });
    chipsDiv.appendChild(allChip);

    members.forEach(m => {
        const isActive = activeUserFilter == m.id;
        const initial  = (m.name || '?').charAt(0).toUpperCase();
        const chip     = document.createElement('button');
        chip.style.cssText = `display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem 0.2rem 0.3rem;border-radius:999px;font-size:0.8rem;cursor:pointer;border:1px solid var(--border);background:${isActive ? 'var(--color-primary)' : 'var(--bg-card)'};color:${isActive ? '#fff' : 'var(--text-primary)'};`;
        const avatarHtml = m.avatar
            ? `<img src="${escapeHtml(m.avatar)}" style="width:18px;height:18px;border-radius:50%;object-fit:cover;" alt="">`
            : `<span style="width:18px;height:18px;border-radius:50%;background:${isActive ? 'rgba(255,255,255,0.3)' : 'var(--color-primary)'};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:700;">${escapeHtml(initial)}</span>`;
        chip.innerHTML = `${avatarHtml}${escapeHtml(m.name)}`;
        chip.addEventListener('click', () => {
            activeUserFilter = (activeUserFilter == m.id) ? null : m.id;
            renderSwimlanesKanban(activeSprint.issues || [], sprintMembers);
        });
        chipsDiv.appendChild(chip);
    });

    wrap.appendChild(chipsDiv);
}

function renderSwimlanesKanban(issues, members) {
    const wrap = document.getElementById('sprint-kanban-wrap');
    wrap.innerHTML = '';

    renderFilterChips(members);

    const rows = activeUserFilter !== null
        ? [members.find(m => m.id == activeUserFilter), { id: null, name: 'Sin asignar' }].filter(Boolean)
        : [...members, { id: null, name: 'Sin asignar' }];

    const scrollWrap = document.createElement('div');
    scrollWrap.style.cssText = 'overflow-x:auto;';

    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid;grid-template-columns:140px repeat(4,1fr);min-width:660px;gap:2px;';

    // Cabecera columnas
    const corner = document.createElement('div');
    corner.style.cssText = 'padding:0.4rem;';
    grid.appendChild(corner);
    SPRINT_COLS.forEach(col => {
        const h = document.createElement('div');
        h.style.cssText = 'padding:0.4rem 0.5rem;font-weight:600;font-size:0.8rem;color:var(--text-secondary);text-align:center;border-bottom:2px solid var(--border);';
        h.textContent = SPRINT_COL_LABELS[col];
        grid.appendChild(h);
    });

    // Filas swimlane
    rows.forEach(row => {
        const isUnassigned = row.id === null;
        const rowIssues    = issues.filter(i =>
            isUnassigned ? !i.assigned_to : String(i.assigned_to) === String(row.id)
        );

        // Header de fila
        const rowHeader = document.createElement('div');
        rowHeader.style.cssText = 'padding:0.4rem 0.3rem;display:flex;align-items:flex-start;gap:0.35rem;border-top:1px solid var(--border);padding-top:0.6rem;';
        const initial    = isUnassigned ? '?' : (row.name || '?').charAt(0).toUpperCase();
        const avatarHtml = (!isUnassigned && row.avatar)
            ? `<img src="${escapeHtml(row.avatar)}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">`
            : `<span style="width:22px;height:22px;border-radius:50%;background:${isUnassigned ? '#9ca3af' : 'var(--color-primary)'};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:700;flex-shrink:0;">${escapeHtml(initial)}</span>`;
        rowHeader.innerHTML = `${avatarHtml}<div style="min-width:0;"><div style="font-size:0.75rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:90px;">${escapeHtml(isUnassigned ? 'Sin asignar' : row.name)}</div><div style="font-size:0.65rem;color:var(--text-secondary);">${rowIssues.length} issues</div></div>`;
        grid.appendChild(rowHeader);

        // Celdas
        SPRINT_COLS.forEach(col => {
            const cell = document.createElement('div');
            cell.style.cssText = 'min-height:80px;padding:0.3rem;border-top:1px solid var(--border);border-left:1px solid var(--border);background:var(--bg-secondary,#f9fafb);border-radius:4px;display:flex;flex-direction:column;gap:0.3rem;';
            cell.dataset.col    = col;
            cell.dataset.userId = row.id ?? '';

            const cellIssues = rowIssues.filter(i => i.status === col);
            if (!cellIssues.length) {
                const empty = document.createElement('div');
                empty.style.cssText = 'border:2px dashed var(--border);border-radius:4px;flex:1;min-height:56px;opacity:0.35;';
                cell.appendChild(empty);
            }
            cellIssues.forEach(issue => cell.appendChild(buildSprintCard(issue)));

            cell.addEventListener('dragover', e => { e.preventDefault(); cell.style.outline = '2px dashed var(--color-primary)'; cell.style.outlineOffset = '-2px'; });
            cell.addEventListener('dragleave', e => { if (!cell.contains(e.relatedTarget)) cell.style.outline = ''; });
            cell.addEventListener('drop', async e => {
                e.preventDefault();
                cell.style.outline = '';
                try {
                    const parsed      = JSON.parse(e.dataTransfer.getData('text/plain'));
                    const newAssigned = row.id != null ? parseInt(row.id) : null;
                    const updates     = { id: parsed.id };

                    if (parsed.type === 'backlog-issue') {
                        const addRes = await apiFetch(`${APP_URL}/app/api/sprints.php?action=add_issue`, { sprint_id: activeSprint.id, issue_id: parsed.id });
                        if (!addRes.success) throw new Error(addRes.error || 'Error al añadir al sprint');
                        if (col !== 'todo') updates.status = col;
                        if (newAssigned !== null) updates.assigned_to = newAssigned;
                        if (Object.keys(updates).length > 1) {
                            await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, updates);
                        }
                    } else if (parsed.type === 'sprint-issue') {
                        const oldAssigned = parsed.assigned_to != null ? parseInt(parsed.assigned_to) : null;
                        if (col !== parsed.status)       updates.status      = col;
                        if (newAssigned !== oldAssigned) updates.assigned_to = newAssigned;
                        if (Object.keys(updates).length === 1) return; // no changes
                        await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, updates);
                    }
                    loadSprintPage();
                } catch(err) { showToast('Error al actualizar la issue', 'error'); }
            });
            grid.appendChild(cell);
        });
    });

    scrollWrap.appendChild(grid);
    wrap.appendChild(scrollWrap);
}

function buildSprintCard(issue) {
    const card = document.createElement('div');
    card.className = 'kanban-card';
    card.draggable = true;
    const today = new Date(); today.setHours(0,0,0,0);
    const isOverdue = issue.due_date && new Date(issue.due_date + 'T00:00:00') < today && issue.status !== 'done';
    if (isOverdue) card.classList.add('overdue');
    const typeChip = issue.type_name
        ? `<span class="badge" style="background:${escapeHtml(issue.type_color||'#6b7280')}22;color:${escapeHtml(issue.type_color||'#6b7280')};border:1px solid ${escapeHtml(issue.type_color||'#6b7280')}44;font-size:0.65rem;padding:0.1rem 0.35rem;">${escapeHtml(issue.type_name)}</span>`
        : '';
    card.innerHTML = `
        ${typeChip ? `<div style="margin-bottom:0.3rem;">${typeChip}</div>` : ''}
        <div class="card-title">${escapeHtml(issue.title)}</div>
        <div class="card-meta">
            <span class="badge badge-${escapeHtml(issue.priority)}">${escapeHtml(issue.priority)}</span>
            ${issue.assignee_name ? `<span>${escapeHtml(issue.assignee_name)}</span>` : ''}
        </div>
        ${issue.story_points ? `<div style="font-size:0.7rem;color:var(--text-secondary);margin-top:0.2rem;text-align:right;">${parseInt(issue.story_points, 10)} pts</div>` : ''}
        <div style="margin-top:0.4rem;text-align:right;">
            <button style="font-size:0.65rem;padding:0.1rem 0.35rem;background:var(--bg-secondary,#f3f4f6);color:var(--text-secondary);border:1px solid var(--border);border-radius:3px;cursor:pointer;"
                data-remove-issue="${issue.id}">← Backlog</button>
        </div>`;
    card.querySelector('[data-remove-issue]').addEventListener('click', e => {
        e.stopPropagation();
        removeIssueFromSprint(issue.id);
    });
    card.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'sprint-issue', id: issue.id, status: issue.status, assigned_to: issue.assigned_to ?? null }));
    });
    card.addEventListener('click', e => {
        if (e.target.closest('button,[data-remove-issue]')) return;
        window.location.href = `${APP_URL}?page=issues&open_issue=${issue.id}`;
    });
    return card;
}

async function removeIssueFromSprint(issueId) {
    const res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=remove_issue`, { issue_id: issueId });
    if (!res.success) { showToast(res.error || 'Error', 'error'); return; }
    loadSprintPage();
}

// ─── Backlog ──────────────────────────────────────────────────────────────────

async function loadBacklog() {
    const backlogEl = document.getElementById('sprint-backlog');
    if (!PROJECT_ID || !backlogEl) return;
    try {
        const res  = await fetch(`${APP_URL}/app/api/sprints.php?action=backlog&project_id=${PROJECT_ID}`);
        const data = await res.json();
        const items = data.data || [];

        if (!items.length) {
            backlogEl.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Backlog vacío ✓</div>';
            return;
        }

        backlogEl.innerHTML = items.map(issue => {
            const typeChip = issue.type_name
                ? `<span class="badge" style="background:${escapeHtml(issue.type_color||'#6b7280')}22;color:${escapeHtml(issue.type_color||'#6b7280')};border:1px solid ${escapeHtml(issue.type_color||'#6b7280')}44;font-size:0.65rem;">${escapeHtml(issue.type_name)}</span>`
                : '';
            const addBtn = activeSprint
                ? `<button class="btn btn-secondary" style="font-size:0.75rem;padding:0.2rem 0.5rem;flex-shrink:0;"
                    data-add-issue="${issue.id}">→ Sprint</button>`
                : '';
            return `<div class="backlog-row" draggable="true" data-issue-id="${issue.id}"
                style="display:flex;align-items:center;gap:0.75rem;padding:0.65rem 1rem;border-bottom:1px solid var(--border);">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.875rem;font-weight:500;">#${issue.id} ${escapeHtml(issue.title)}</div>
                    <div style="display:flex;gap:0.3rem;margin-top:0.2rem;flex-wrap:wrap;align-items:center;">
                        ${typeChip}
                        <span class="badge badge-${escapeHtml(issue.priority)}" style="font-size:0.65rem;">${escapeHtml(issue.priority)}</span>
                        ${issue.assignee_name ? `<span style="font-size:0.75rem;color:var(--text-secondary);">${escapeHtml(issue.assignee_name)}</span>` : ''}
                        ${issue.story_points ? `<span style="font-size:0.75rem;color:var(--text-secondary);">${parseInt(issue.story_points, 10)} pts</span>` : ''}
                    </div>
                </div>
                ${addBtn}
            </div>`;
        }).join('');

        backlogEl.querySelectorAll('[data-add-issue]').forEach(btn => {
            btn.addEventListener('click', () => addIssueToSprint(parseInt(btn.dataset.addIssue)));
        });
        backlogEl.querySelectorAll('.backlog-row').forEach(row => {
            row.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'backlog-issue', id: parseInt(row.dataset.issueId), assigned_to: null }));
            });
        });
    } catch(e) {
        backlogEl.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Error al cargar el backlog.</div>';
    }
}

async function addIssueToSprint(issueId) {
    if (!activeSprint) { showToast('No hay sprint activo', 'warning'); return; }
    const res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=add_issue`, { sprint_id: activeSprint.id, issue_id: issueId });
    if (!res.success) { showToast(res.error || 'Error', 'error'); return; }
    loadSprintPage();
}

// ─── Sprint management modal ──────────────────────────────────────────────────

let editingSprintId = null;

function openSprintModal() {
    document.getElementById('sprint-modal').classList.remove('hidden');
    loadSprintListModal();
}

function closeSprintModal() {
    document.getElementById('sprint-modal').classList.add('hidden');
    cancelEditSprint();
}

function cancelEditSprint() {
    editingSprintId = null;
    document.getElementById('sprint-form-title').textContent  = 'Nuevo sprint';
    document.getElementById('sprint-save-btn').textContent    = '+ Crear sprint';
    document.getElementById('sprint-cancel-edit-btn').style.display = 'none';
    document.getElementById('sprint-name-input').value  = '';
    document.getElementById('sprint-start-input').value = '';
    document.getElementById('sprint-end-input').value   = '';
}

async function loadSprintListModal() {
    const listEl = document.getElementById('sprint-list-modal');
    listEl.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-secondary);">Cargando...</div>';
    let sprints = [];
    try {
        const res  = await fetch(`${APP_URL}/app/api/sprints.php?action=list&project_id=${PROJECT_ID}`);
        const data = await res.json();
        sprints = data.data || [];
    } catch(e) {
        listEl.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-secondary);">Error al cargar los sprints.</div>';
        return;
    }

    if (!sprints.length) {
        listEl.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-secondary);font-size:0.875rem;">No hay sprints aún</div>';
        return;
    }

    const STATUS_LABEL = { planning: 'Planificando', active: 'Activo', completed: 'Completado' };
    const STATUS_COLOR = { planning: '#d97706', active: '#16a34a', completed: '#6b7280' };

    listEl.innerHTML = sprints.map(s => {
        const [, sm, sd] = s.start_date.split('-');
        const [, em, ed] = s.end_date.split('-');
        const dateStr = `${sd}/${sm} – ${ed}/${em}`;
        let actions = '';
        if (s.status === 'planning') {
            actions = `
                <button class="btn btn-primary" style="font-size:0.75rem;" data-start-sprint="${s.id}">▶ Iniciar</button>
                <button class="btn btn-secondary" style="font-size:0.75rem;" data-edit-sprint="${s.id}">Editar</button>
                <button class="btn btn-danger" style="font-size:0.75rem;" data-delete-sprint="${s.id}">Eliminar</button>`;
        } else if (s.status === 'active') {
            actions = `<button class="btn btn-secondary" style="font-size:0.75rem;" data-complete-sprint="${s.id}">&#10003; Cerrar sprint</button>`;
        }
        return `<div style="padding:0.75rem;border:1px solid var(--border);border-radius:8px;margin-bottom:0.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;flex-wrap:wrap;">
                <div>
                    <div style="font-weight:600;font-size:0.9rem;color:var(--text-primary);">${escapeHtml(s.name)}</div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);">${dateStr} · ${s.issue_count} issues</div>
                </div>
                <span style="font-size:0.75rem;color:${STATUS_COLOR[s.status]};font-weight:600;">${STATUS_LABEL[s.status]}</span>
            </div>
            ${actions ? `<div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-top:0.5rem;">${actions}</div>` : ''}
        </div>`;
    }).join('');

    listEl.querySelectorAll('[data-start-sprint]').forEach(btn =>
        btn.addEventListener('click', () => startSprint(parseInt(btn.dataset.startSprint))));
    listEl.querySelectorAll('[data-complete-sprint]').forEach(btn =>
        btn.addEventListener('click', () => completeSprint(parseInt(btn.dataset.completeSprint))));
    listEl.querySelectorAll('[data-delete-sprint]').forEach(btn =>
        btn.addEventListener('click', () => deleteSprint(parseInt(btn.dataset.deleteSprint))));
    listEl.querySelectorAll('[data-edit-sprint]').forEach(btn => {
        const sprintId = parseInt(btn.dataset.editSprint);
        const sprint   = sprints.find(s => s.id == sprintId);
        btn.addEventListener('click', () => {
            if (!sprint) return;
            editingSprintId = sprint.id;
            document.getElementById('sprint-form-title').textContent = 'Editar sprint';
            document.getElementById('sprint-save-btn').textContent   = 'Guardar cambios';
            document.getElementById('sprint-cancel-edit-btn').style.display = '';
            document.getElementById('sprint-name-input').value  = sprint.name;
            document.getElementById('sprint-start-input').value = sprint.start_date;
            document.getElementById('sprint-end-input').value   = sprint.end_date;
            document.getElementById('sprint-name-input').focus();
        });
    });
}

async function startSprint(id) {
    const res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=start`, { id });
    if (!res.success) { showToast(res.error || 'Error', 'error'); return; }
    showToast('Sprint iniciado', 'success');
    loadSprintListModal();
    loadSprintPage();
}

function completeSprint(id) {
    showConfirm(
        '¿Cerrar este sprint? Las issues no completadas volverán al backlog.',
        async () => {
            const res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=complete`, { id });
            if (!res.success) { showToast(res.error || 'Error', 'error'); return; }
            showToast('Sprint cerrado', 'success');
            loadSprintListModal();
            loadSprintPage();
        }
    );
}

function deleteSprint(id) {
    showConfirm(
        '¿Eliminar este sprint? Esta acción es irreversible.',
        async () => {
            const res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=delete`, { id });
            if (!res.success) { showToast(res.error || 'Error', 'error'); return; }
            showToast('Sprint eliminado', 'success');
            loadSprintListModal();
            loadSprintPage();
        },
        { requireWord: 'ELIMINAR' }
    );
}

// ─── Event listeners ──────────────────────────────────────────────────────────

document.getElementById('manage-sprints-btn').addEventListener('click', openSprintModal);
document.getElementById('sprint-modal-close').addEventListener('click', closeSprintModal);
document.getElementById('sprint-cancel-edit-btn').addEventListener('click', cancelEditSprint);

document.getElementById('sprint-modal').addEventListener('click', e => {
    if (e.target === document.getElementById('sprint-modal')) closeSprintModal();
});

document.getElementById('sprint-save-btn').addEventListener('click', async () => {
    const name  = document.getElementById('sprint-name-input').value.trim();
    const start = document.getElementById('sprint-start-input').value;
    const end   = document.getElementById('sprint-end-input').value;
    if (!name || !start || !end) { showToast('Completa todos los campos', 'warning'); return; }

    let res;
    if (editingSprintId) {
        res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=update`, { id: editingSprintId, name, start_date: start, end_date: end });
    } else {
        res = await apiFetch(`${APP_URL}/app/api/sprints.php?action=create`, { project_id: PROJECT_ID, name, start_date: start, end_date: end });
    }
    if (!res.success) { showToast(res.error || 'Error', 'error'); return; }
    showToast(editingSprintId ? 'Sprint actualizado' : 'Sprint creado', 'success');
    cancelEditSprint();
    loadSprintListModal();
    if (!editingSprintId) loadSprintPage();
});

// ─── Init ─────────────────────────────────────────────────────────────────────

if (PROJECT_ID) {
    loadSprintPage();
} else {
    document.getElementById('sprint-kanban-wrap').innerHTML =
        '<div style="padding:2rem;text-align:center;color:var(--text-secondary);">Selecciona un proyecto en el menú lateral.</div>';
    document.getElementById('sprint-backlog').innerHTML =
        '<div style="padding:1rem;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Selecciona un proyecto para ver el backlog.</div>';
}
