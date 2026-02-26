const COLUMNS = ['todo', 'in_progress', 'review', 'done'];
const COLUMN_LABELS = { todo: 'Pendiente', in_progress: 'En curso', review: 'Revisión', done: 'Hecho' };
let issues = [];

function getActiveFilters() {
    return {
        priority:    document.getElementById('filter-priority')?.value || '',
        assigned_to: document.getElementById('filter-assignee')?.value || '',
    };
}

async function loadIssues() {
    if (!PROJECT_ID) return;
    document.getElementById('kanban-board').innerHTML = '<div style="padding:2rem;color:var(--text-tertiary);">Cargando tablero...</div>';
    const f = getActiveFilters();
    const params = new URLSearchParams({ action: 'list', project_id: PROJECT_ID, per_page: 500 });
    if (f.priority)    params.set('priority', f.priority);
    if (f.assigned_to) params.set('assigned_to', f.assigned_to);
    const res = await fetch(`${APP_URL}/app/api/issues.php?${params}`);
    const data = await res.json();
    issues = data.items || [];
    const total = data.total || 0;

    // Warn if results are capped
    const capWarn = document.getElementById('kanban-cap-warning');
    if (capWarn) capWarn.style.display = total > 500 ? 'block' : 'none';
    if (capWarn && total > 500) capWarn.textContent = `⚠ Mostrando 500 de ${total} issues. Usa los filtros para ver el resto.`;

    // Update filter count
    const countEl = document.getElementById('filter-count');
    if (countEl) countEl.textContent = issues.length > 0 ? `${issues.length} issues` : '';
    const clearBtn = document.getElementById('filter-clear');
    if (clearBtn) clearBtn.style.color = (f.priority || f.assigned_to) ? 'var(--color-primary)' : '#6b7280';

    renderBoard();
}

async function initFilterBar() {
    const res = await fetch(`${APP_URL}/app/api/team.php?action=members`);
    const data = await res.json();
    const sel = document.getElementById('filter-assignee');
    (data.data || []).forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.name;
        sel.appendChild(opt);
    });
    let filterDebounce;
    const reloadDebounced = () => { clearTimeout(filterDebounce); filterDebounce = setTimeout(loadIssues, 150); };
    document.getElementById('filter-priority').addEventListener('change', reloadDebounced);
    document.getElementById('filter-assignee').addEventListener('change', reloadDebounced);
    document.getElementById('filter-clear').addEventListener('click', () => {
        document.getElementById('filter-priority').value = '';
        document.getElementById('filter-assignee').value = '';
        loadIssues();
    });
}

function renderBoard() {
    const board = document.getElementById('kanban-board');
    board.innerHTML = '';
    COLUMNS.forEach(col => {
        const colEl = document.createElement('div');
        colEl.className = 'kanban-col';
        colEl.dataset.status = col;
        const colIssues = issues.filter(i => i.status === col);
        const colPts = colIssues.reduce((s, i) => s + (i.story_points || 0), 0);
        const ptsLabel = colPts > 0 ? `<span style="font-size:0.7rem;color:var(--text-tertiary);margin-left:0.35rem;">${colPts} pts</span>` : '';
        colEl.innerHTML = `<div class="kanban-col-header">
            ${COLUMN_LABELS[col]} <span class="col-count">${colIssues.length}</span>${ptsLabel}
            <button class="kanban-add-btn" data-col="${col}" title="Añadir issue" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;color:var(--text-tertiary);padding:0 0.15rem;">+</button>
        </div>`;
        if (!colIssues.length) {
            colEl.innerHTML += '<div class="kanban-empty">Sin issues</div>';
        }
        colIssues.forEach(issue => {
            const card = document.createElement('div');
            card.className = 'kanban-card';
            const today = new Date(); today.setHours(0,0,0,0);
            const isOverdue = issue.due_date && new Date(issue.due_date + 'T00:00:00') < today && issue.status !== 'done';
            if (isOverdue) card.classList.add('overdue');
            card.draggable = true;
            card.dataset.id = issue.id;
            const typeChip = issue.type_name
                ? `<span class="badge" style="background:${escapeHtml(issue.type_color||'#6b7280')}22;color:${escapeHtml(issue.type_color||'#6b7280')};border:1px solid ${escapeHtml(issue.type_color||'#6b7280')}44;font-size:0.65rem;padding:0.1rem 0.35rem;">${escapeHtml(issue.type_name)}</span>`
                : '';
            card.innerHTML = `${typeChip ? `<div style="margin-bottom:0.3rem;">${typeChip}</div>` : ''}
    <div class="card-title">${escapeHtml(issue.title)}</div>
    <div class="card-meta"><span class="badge badge-${issue.priority}">${issue.priority}</span>
    ${issue.assignee_name ? `<span>${escapeHtml(issue.assignee_name)}</span>` : ''}</div>
    ${issue.story_points ? `<div style="font-size:0.7rem;color:var(--text-secondary);margin-top:0.2rem;text-align:right;">${issue.story_points} pts</div>` : ''}`;
            card.addEventListener('dragstart', e => { card._dragging = true; e.dataTransfer.setData('text/plain', issue.id); });
            card.addEventListener('dragend', () => { setTimeout(() => { card._dragging = false; }, 0); });
            card.addEventListener('click', () => {
                if (card._dragging) return;
                window.location.href = `${APP_URL}?page=issues&open_issue=${issue.id}`;
            });
            colEl.appendChild(card);
        });
        colEl.addEventListener('dragover', e => { e.preventDefault(); colEl.classList.add('drag-over'); });
        colEl.addEventListener('dragleave', e => { if (!colEl.contains(e.relatedTarget)) colEl.classList.remove('drag-over'); });
        colEl.addEventListener('drop', async e => {
            e.preventDefault();
            colEl.classList.remove('drag-over');
            const id = e.dataTransfer.getData('text/plain');
            await fetch(`${APP_URL}/app/api/issues.php?action=update`, {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ id: parseInt(id), status: col })
            });
            loadIssues();
        });
        // Quick-add "+" button per column
        colEl.querySelector('.kanban-add-btn').addEventListener('click', e => {
            e.stopPropagation();
            openQuickAdd(colEl, col);
        });
        board.appendChild(colEl);
    });
}

function openQuickAdd(colEl, status) {
    // Remove any existing quick-add form
    document.querySelectorAll('.kanban-quick-add').forEach(el => el.remove());

    const form = document.createElement('div');
    form.className = 'kanban-quick-add';
    form.style.cssText = 'padding:0.5rem;border-top:1px solid var(--border);margin-top:auto;';
    form.innerHTML = `
        <input type="text" class="kanban-qa-input" placeholder="Título de la issue…"
            style="width:100%;padding:0.4rem 0.5rem;border:1px solid var(--input-border);border-radius:4px;font-size:0.8rem;
                   box-sizing:border-box;font-family:inherit;outline:none;">
        <div style="display:flex;gap:0.35rem;margin-top:0.35rem;">
            <button class="kanban-qa-save btn btn-primary" style="font-size:0.75rem;padding:0.25rem 0.6rem;">Añadir</button>
            <button class="kanban-qa-cancel btn btn-secondary" style="font-size:0.75rem;padding:0.25rem 0.6rem;">✕</button>
        </div>`;
    colEl.appendChild(form);
    const input = form.querySelector('.kanban-qa-input');
    input.focus();

    const close = () => form.remove();
    const save  = async () => {
        const title = input.value.trim();
        if (!title) { input.focus(); return; }
        const saveBtn = form.querySelector('.kanban-qa-save');
        saveBtn.disabled = true;
        await fetch(`${APP_URL}/app/api/issues.php?action=create`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ project_id: PROJECT_ID, title, status })
        });
        close();
        loadIssues();
    };

    form.querySelector('.kanban-qa-cancel').addEventListener('click', close);
    form.querySelector('.kanban-qa-save').addEventListener('click', save);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') save();
        if (e.key === 'Escape') close();
    });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

document.getElementById('new-issue-btn').addEventListener('click', () => {
    document.getElementById('issue-modal').classList.remove('hidden');
});
document.getElementById('modal-cancel').addEventListener('click', () => {
    document.getElementById('issue-modal').classList.add('hidden');
});
document.getElementById('modal-save').addEventListener('click', async () => {
    const title = document.getElementById('issue-title').value.trim();
    if (!title) return;
    const btn = document.getElementById('modal-save');
    btn.disabled = true; btn.classList.add('btn-loading');
    await fetch(`${APP_URL}/app/api/issues.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ project_id: PROJECT_ID, title, description: document.getElementById('issue-desc').value })
    });
    btn.disabled = false; btn.classList.remove('btn-loading');
    document.getElementById('issue-modal').classList.add('hidden');
    document.getElementById('issue-title').value = '';
    document.getElementById('issue-desc').value = '';
    loadIssues();
});

initFilterBar();
loadIssues();
