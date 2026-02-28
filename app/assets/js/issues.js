let currentIssueId = null;
let currentPage = 1;
let currentPRs = [];
let currentPRBranch = null;
let issueTypes = [];   // cached types for this project
let currentBranchSuggestedName = '';
let branchModalIssueId = null;
let branchModalOnSuccess = null;

function sonarQGChip(status, url) {
    if (!status || status === 'NONE') return '';
    const labels = { PASSED:'QG OK', FAILED:'QG FAIL', WARN:'QG WARN', ERROR:'QG ERROR' };
    const colors = { PASSED:'#16a34a', FAILED:'#dc2626', WARN:'#d97706', ERROR:'#dc2626' };
    const bgs    = { PASSED:'#dcfce7', FAILED:'#fee2e2', WARN:'#fef9c3', ERROR:'#fee2e2' };
    const icon   = status === 'PASSED' ? '✓' : '✗';
    const label  = labels[status] || status;
    const color  = colors[status] || 'var(--text-tertiary)';
    const bg     = bgs[status]    || 'var(--bg-secondary)';
    const link   = url ? ` <a href="${escapeHtml(url)}" target="_blank" style="color:${color};font-weight:700;">↗</a>` : '';
    return `<span style="font-size:0.7rem;background:${bg};color:${color};border-radius:999px;padding:0.1rem 0.5rem;font-weight:600;white-space:nowrap;">${icon} ${label}${link}</span>`;
}

function getActiveFilters() {
    return {
        status:      document.getElementById('filter-status')?.value || '',
        priority:    document.getElementById('filter-priority')?.value || '',
        assigned_to: document.getElementById('filter-assignee')?.value || '',
        type_id:     document.getElementById('filter-type')?.value || '',
    };
}

async function loadIssueTypes() {
    if (!PROJECT_ID) return;
    const res  = await fetch(`${APP_URL}/app/api/issue_types.php?action=list&project_id=${PROJECT_ID}`);
    const data = await res.json();
    issueTypes = data.data || [];

    const typeOpts = issueTypes.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

    // Populate filter, new-issue modal, and full-issue selects
    const filterSel = document.getElementById('filter-type');
    if (filterSel) filterSel.innerHTML = '<option value="">Tipo: Todos</option>' + typeOpts;

    const newSel = document.getElementById('new-type');
    if (newSel) newSel.innerHTML = '<option value="">Sin tipo</option>' + typeOpts;

    const fiSel = document.getElementById('fi-type');
    if (fiSel) fiSel.innerHTML = '<option value="">Sin tipo</option>' + typeOpts;
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

    const bulkSel = document.getElementById('bulk-assignee-sel');
    if (bulkSel) {
        (data.data || []).forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.name;
            bulkSel.appendChild(opt);
        });
    }

    let filterDebounce;
    ['filter-status', 'filter-priority', 'filter-assignee', 'filter-type'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => {
            clearTimeout(filterDebounce);
            filterDebounce = setTimeout(() => loadIssues(1), 150);
        });
    });

    document.getElementById('filter-clear').addEventListener('click', () => {
        document.getElementById('filter-status').value = '';
        document.getElementById('filter-priority').value = '';
        document.getElementById('filter-assignee').value = '';
        document.getElementById('filter-type').value = '';
        loadIssues(1);
    });

    document.getElementById('export-csv-btn').addEventListener('click', function() {
        const f = getActiveFilters();
        const params = new URLSearchParams({ action: 'export', project_id: PROJECT_ID });
        if (f.status)      params.set('status', f.status);
        if (f.priority)    params.set('priority', f.priority);
        if (f.assigned_to) params.set('assigned_to', f.assigned_to);
        if (f.type_id)     params.set('type_id', f.type_id);
        window.location.href = `${APP_URL}/app/api/issues.php?${params}`;
    });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function titleToSlug(title) {
    return (title || '')
        .normalize('NFD').replaceAll(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replaceAll(/[^a-z0-9\s]/g, '')
        .trim()
        .replaceAll(/\s+/g, '-')
        .replaceAll(/-+/g, '-')
        .substring(0, 60);
}

function highlightMentions(text) {
    return escapeHtml(text).replace(/@(\w+)/g,
        '<span style="color:var(--color-primary);font-weight:500;background:rgba(255,166,43,0.12);border-radius:3px;padding:0 2px;">@$1</span>');
}

async function loadIssues(page = 1) {
    if (!PROJECT_ID) return;
    currentPage = page;
    const list = document.getElementById('issue-list');
    list.innerHTML = '<span class="skeleton skeleton-card"></span><span class="skeleton skeleton-card"></span><span class="skeleton skeleton-card"></span>';
    const f = getActiveFilters();
    const params = new URLSearchParams({ action: 'list', project_id: PROJECT_ID, page });
    if (f.status)      params.set('status', f.status);
    if (f.priority)    params.set('priority', f.priority);
    if (f.assigned_to) params.set('assigned_to', f.assigned_to);
    if (f.type_id)     params.set('type_id', f.type_id);
    const res = await fetch(`${APP_URL}/app/api/issues.php?${params}`);
    const data = await res.json();
    list.innerHTML = '';
    const items = data.items || [];
    const total = data.total || 0;

    // Update filter count label
    const countEl = document.getElementById('filter-count');
    if (countEl) countEl.textContent = total > 0 ? `${total} issue${total !== 1 ? 's' : ''}` : '';

    // Highlight active filters
    const f2 = getActiveFilters();
    const hasFilter = f2.status || f2.priority || f2.assigned_to;
    const clearBtn = document.getElementById('filter-clear');
    if (clearBtn) clearBtn.style.color = hasFilter ? 'var(--color-primary)' : '#6b7280';

    if (!items.length && page === 1) {
        list.innerHTML = `<div style="text-align:center;padding:3rem;color:var(--text-tertiary);">
            <div style="font-size:2rem;margin-bottom:0.5rem;">&#128203;</div>
            <div style="font-size:1rem;margin-bottom:1rem;">Sin issues todavía</div>
            <button class="btn btn-primary" onclick="document.getElementById('new-issue-btn').click()">Crear primera issue</button>
        </div>`;
        return;
    }

    items.forEach(issue => {
        const el = document.createElement('div');
        el.className = 'card issue-row';
        const today = new Date(); today.setHours(0,0,0,0);
        const isOverdue = issue.due_date && new Date(issue.due_date + 'T00:00:00') < today && issue.status !== 'done';
        if (isOverdue) el.classList.add('overdue');
        el.style.cssText = 'cursor:pointer;display:flex;justify-content:space-between;align-items:center;';
        const labels = issue.labels_json ? JSON.parse(issue.labels_json) : [];
        const labelChips = labels.map(l => `<span class="label-chip" style="background:${escapeHtml(l.color)}22;color:${escapeHtml(l.color)};border:1px solid ${escapeHtml(l.color)};">${escapeHtml(l.name)}</span>`).join('');
        const typeChip = issue.type_name
            ? `<span style="padding:0.15rem 0.5rem;border-radius:999px;font-size:0.72rem;font-weight:600;background:${escapeHtml(issue.type_color)}22;color:${escapeHtml(issue.type_color)};border:1px solid ${escapeHtml(issue.type_color)};">${escapeHtml(issue.type_name)}</span>`
            : '';
        el.innerHTML = `
            <div style="display:flex;align-items:center;padding-right:0.6rem;flex-shrink:0;">
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
        el.addEventListener('click', () => openIssue(issue));
        list.appendChild(el);
    });

    // Reset bulk selection after rerender
    const selectAllCb = document.getElementById('select-all-cb');
    if (selectAllCb) selectAllCb.checked = false;
    if (typeof updateBulkBar === 'function') updateBulkBar();

    // Pagination controls
    const pag = document.getElementById('pagination');
    const totalPages = Math.ceil(total / 25);
    pag.innerHTML = '';
    if (totalPages > 1) {
        pag.innerHTML = `
            <button class="btn btn-secondary" onclick="loadIssues(${page - 1})" ${page === 1 ? 'disabled' : ''}>&#8592; Prev</button>
            <span style="font-size:0.875rem;color:var(--text-secondary);">Page ${page} of ${totalPages} (${total} total)</span>
            <button class="btn btn-secondary" onclick="loadIssues(${page + 1})" ${page === totalPages ? 'disabled' : ''}>Next &#8594;</button>
        `;
    }
}

async function openIssue(issue) {
    const id = issue.id;
    currentIssueId = id;

    document.getElementById('detail-title').textContent = `#${id} ${issue.title}`;

    // Build meta chips
    const typeChip = issue.type_name
        ? `<span style="padding:0.2rem 0.55rem;border-radius:999px;font-size:0.75rem;font-weight:600;background:${escapeHtml(issue.type_color)}22;color:${escapeHtml(issue.type_color)};border:1px solid ${escapeHtml(issue.type_color)};">${escapeHtml(issue.type_name)}</span>`
        : '';
    const statusLabel = { todo:'Pendiente', in_progress:'En curso', review:'Revisión', done:'Hecho' }[issue.status] || issue.status;
    const priorityLabel = { low:'Baja', medium:'Media', high:'Alta', critical:'Crítica' }[issue.priority] || issue.priority;
    const dueStr = issue.due_date
        ? new Date(issue.due_date + 'T00:00:00').toLocaleDateString('es-ES', { day:'2-digit', month:'short', year:'numeric' })
        : null;

    document.getElementById('detail-meta').innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:0.35rem;margin-bottom:0.6rem;align-items:center;">
            ${typeChip}
            <span class="badge badge-${escapeHtml(issue.priority)}">${priorityLabel}</span>
            <span class="badge" style="background:#e5e7eb;color:#374151;">${statusLabel}</span>
            ${issue.assignee_name ? `<span style="font-size:0.75rem;color:var(--text-secondary);">👤 ${escapeHtml(issue.assignee_name)}</span>` : ''}
            ${dueStr ? `<span style="font-size:0.75rem;color:var(--text-secondary);">📅 ${dueStr}</span>` : ''}
        </div>
        <div style="display:flex;gap:0.5rem;">
            <button onclick="openFullIssue(${id})" class="btn btn-secondary" style="font-size:0.8rem;padding:0.3rem 0.75rem;">↗ Ver completa</button>
            <button onclick="deleteCurrentIssue()" class="btn btn-danger" style="font-size:0.8rem;padding:0.3rem 0.75rem;">🗑 Eliminar</button>
        </div>`;

    document.getElementById('detail-desc').innerHTML = issue.description
        ? `<p style="font-size:0.875rem;line-height:1.6;margin:0;">${escapeHtml(issue.description)}</p>`
        : '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin descripción</em>';

    const slug = titleToSlug(issue.title);
    currentBranchSuggestedName = slug ? `issue-${id}-${slug}` : `issue-${id}`;
    document.getElementById('issue-detail').classList.remove('hidden');
    await loadPRs(id);
    await loadBranches(id);
    await loadComments(id);
    await loadIssueLabels(id);
    await loadAssigneePicker(id);
}

async function loadBranches(id) {
    const repoRes = await fetch(`${APP_URL}/app/api/github.php?action=repo_status&project_id=${PROJECT_ID}`);
    const repoData = await repoRes.json();
    const statusEl = document.getElementById('github-repo-status');
    const createArea = document.getElementById('create-branch-area');

    if (!repoData.connected) {
        statusEl.innerHTML = '<span style="color:#dc2626;">No repository connected.</span> <a href="?page=team" style="color:var(--color-primary);font-size:0.8rem;">Configure in Team →</a>';
        createArea.style.display = 'none';
        document.getElementById('branch-list').innerHTML = '';
        return;
    }
    statusEl.innerHTML = `&#128279; <a href="https://github.com/${repoData.repo}" target="_blank" style="color:var(--color-primary);">${repoData.repo}</a>`;
    createArea.style.display = 'block';

    const res = await fetch(`${APP_URL}/app/api/github.php?action=branches&issue_id=${id}`);
    const data = await res.json();
    const list = document.getElementById('branch-list');
    const branches = data.data || [];
    if (!branches.length) { list.innerHTML = '<em style="color:var(--text-tertiary)">Sin ramas aún</em>'; return; }

    const sonarResults = await Promise.all(branches.map(b =>
        fetch(`${APP_URL}/app/api/sonarqube.php?action=status&project_id=${PROJECT_ID}&branch=${encodeURIComponent(b.branch_name)}`)
            .then(r => r.json())
            .catch(() => ({ success: false }))
    ));

    list.innerHTML = branches.map((b, i) => {
        const sq = sonarResults[i] || {};
        const chip = sonarQGChip(sq.success ? sq.status : null, sq.url);
        const existingPR = currentPRs.find(pr => pr.branch === b.branch_name);
        const prBtn = existingPR
            ? `<button onclick="openDiffViewer(${existingPR.number})" style="font-size:0.75rem;color:#fff;background:var(--color-primary);border:none;border-radius:4px;padding:0.25rem 0.6rem;cursor:pointer;font-weight:600;">PR #${existingPR.number} &#128065;</button>`
            : `<button onclick="openCreatePRModal('${escapeHtml(b.branch_name)}')" style="font-size:0.75rem;color:#16a34a;background:none;border:1px solid #16a34a;border-radius:4px;padding:0.25rem 0.6rem;cursor:pointer;">&#8593; Create PR</button>`;
        return `<div class="branch-item" style="margin-bottom:0.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.4rem;">
                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">&#127807; ${escapeHtml(b.branch_name)} <span style="color:var(--text-tertiary);font-size:0.8rem">by ${escapeHtml(b.creator_name)}</span>${chip ? ' ' + chip : ''}</span>
                <div style="display:flex;gap:0.3rem;flex-shrink:0;">
                    ${prBtn}
                    <button onclick="toggleCommits(${id}, '${escapeHtml(b.branch_name)}', this)" style="font-size:0.75rem;color:var(--color-primary);background:none;border:none;cursor:pointer;">&#9654; Commits</button>
                </div>
            </div>
            <div class="commits-panel" style="display:none;margin-top:0.4rem;padding-left:0.75rem;border-left:2px solid #e5e7eb;"></div>
        </div>`;
    }).join('');
}

async function toggleCommits(issueId, branchName, btn) {
    const panel = btn.closest('.branch-item').querySelector('.commits-panel');
    if (panel.style.display === 'block') { panel.style.display = 'none'; btn.textContent = '\u25B6 Commits'; return; }
    panel.style.display = 'block';
    btn.textContent = '\u25BC Commits';
    panel.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Loading...</em>';
    const res = await fetch(`${APP_URL}/app/api/github.php?action=commits&issue_id=${issueId}&branch=${encodeURIComponent(branchName)}`);
    const data = await res.json();
    const commits = data.data || [];
    if (!commits.length) { panel.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">No commits</em>'; return; }
    panel.innerHTML = commits.map(c => `
        <div style="font-size:0.8rem;padding:0.2rem 0;border-bottom:1px solid var(--border);">
            <a href="${escapeHtml(c.url)}" target="_blank" style="color:var(--color-primary);font-family:monospace;">${escapeHtml(c.sha)}</a>
            ${escapeHtml(c.message)}
            <span style="color:var(--text-tertiary);"> — ${escapeHtml(c.author)}</span>
        </div>
    `).join('');
}

async function loadPRs(issueId) {
    const res = await fetch(`${APP_URL}/app/api/github.php?action=prs&issue_id=${issueId}`);
    const data = await res.json();
    const list = document.getElementById('prs-list');
    currentPRs = data.data || [];
    if (!currentPRs.length) { list.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin pull requests aún.</em>'; return; }
    list.innerHTML = currentPRs.map(pr => {
        const isOpen   = pr.state === 'open' && !pr.merged;
        const isMerged = pr.merged;
        const stateColor = isMerged ? '#7c3aed' : isOpen ? '#16a34a' : '#6b7280';
        const stateLabel = isMerged ? 'merged' : pr.state;
        const btnLabel   = isOpen ? '&#128065; Revisar y fusionar' : '&#128065; Ver diff';
        const btnStyle   = isOpen
            ? 'background:#16a34a;color:#fff;border:none;border-radius:4px;padding:0.35rem 0.7rem;font-size:0.8rem;cursor:pointer;font-weight:600;white-space:nowrap;'
            : 'background:none;color:var(--color-primary);border:1px solid var(--color-primary);border-radius:4px;padding:0.3rem 0.6rem;font-size:0.8rem;cursor:pointer;white-space:nowrap;';
        const border = isOpen ? 'border:1px solid #bbf7d0;background:#f0fdf4;' : 'border:1px solid #e5e7eb;';
        return `<div style="margin-bottom:0.5rem;padding:0.5rem 0.6rem;border-radius:6px;${border}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <div style="min-width:0;">
                    <span style="color:${stateColor};font-weight:700;font-size:0.8rem;">● ${stateLabel.toUpperCase()}</span>
                    <span style="font-size:0.875rem;margin-left:0.3rem;">#${pr.number} ${escapeHtml(pr.title)}</span>
                    <div style="font-size:0.75rem;color:var(--text-tertiary);font-family:monospace;">
                        &#127807; ${escapeHtml(pr.head || pr.branch)}
                        <span style="color:var(--text-tertiary);font-family:sans-serif;"> &#8594; </span>
                        ${escapeHtml(pr.base || 'main')}
                        <span style="font-family:sans-serif;color:var(--text-tertiary);"> &nbsp;&#183;&nbsp; by ${escapeHtml(pr.author)}</span>
                    </div>
                </div>
                <button onclick="openDiffViewer(${pr.number})" style="${btnStyle}">${btnLabel}</button>
            </div>
        </div>`;
    }).join('');
}

async function loadComments(issueId) {
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=list&issue_id=${issueId}`);
    const data = await res.json();
    const list = document.getElementById('comments-list');
    const comments = data.data || [];
    if (!comments.length) {
        list.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin comentarios aún.</em>';
        return;
    }
    const canEdit = c => c.user_id === CURRENT_USER_ID || CURRENT_USER_ROLE === 'admin';
    list.innerHTML = comments.map(c => `
        <div class="comment-item" data-id="${c.id}" style="padding:0.6rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:0.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.3rem;">
                <strong style="font-size:0.875rem;">${escapeHtml(c.author_name)}</strong>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <span title="${new Date(c.created_at || '').toLocaleString()}" style="font-size:0.75rem;color:var(--text-tertiary);">${timeAgo(c.created_at)}</span>
                    ${canEdit(c) ? `<button onclick="startCommentEdit(${c.id}, ${JSON.stringify(c.content)}, this.closest('.comment-item').querySelector('.comment-content-area'))" style="font-size:0.75rem;color:var(--color-primary);background:none;border:none;cursor:pointer;padding:0;">Editar</button>` : ''}
                    <button onclick="deleteComment(${c.id})" style="font-size:0.75rem;color:#dc2626;background:none;border:none;cursor:pointer;padding:0;">Eliminar</button>
                </div>
            </div>
            <div class="comment-content-area"><span style="font-size:0.875rem;white-space:pre-wrap;">${highlightMentions(c.content)}</span></div>
        </div>
    `).join('');
}

async function deleteComment(id) {
    showConfirm('¿Eliminar este comentario? Esta acción no se puede deshacer.', async () => {
        const res = await fetch(`${APP_URL}/app/api/comments.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.success) { await loadComments(currentIssueId); showToast('Comentario eliminado'); }
        else showToast(data.error, 'error');
    }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
}

async function deleteFiComment(id) {
    showConfirm('¿Eliminar este comentario? Esta acción no se puede deshacer.', async () => {
        const res = await fetch(`${APP_URL}/app/api/comments.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.success) { await loadFullIssueComments(currentFullIssueId); showToast('Comentario eliminado'); }
        else showToast(data.error, 'error');
    }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
}

function startCommentEdit(id, currentContent, contentEl) {
    contentEl.innerHTML = `
        <textarea style="width:100%;padding:0.4rem;border:1px solid var(--input-border);border-radius:4px;font-size:0.875rem;resize:vertical;box-sizing:border-box;" rows="3">${escapeHtml(currentContent)}</textarea>
        <div style="margin-top:0.4rem;display:flex;gap:0.4rem;">
            <button class="btn btn-primary" style="font-size:0.8rem;padding:0.3rem 0.8rem;"
                onclick="saveCommentEdit(${id}, this)">Guardar</button>
            <button class="btn btn-secondary" style="font-size:0.8rem;padding:0.3rem 0.8rem;"
                onclick="cancelCommentEdit(this, ${JSON.stringify(escapeHtml(currentContent))})">Cancelar</button>
        </div>`;
    contentEl.querySelector('textarea').focus();
}

async function saveCommentEdit(id, btn) {
    const contentEl = btn.closest('.comment-content-area');
    const textarea  = contentEl.querySelector('textarea');
    const content   = textarea.value.trim();
    if (!content) { showToast('El comentario no puede estar vacío', 'error'); return; }
    btn.disabled = true;
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=update`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, content })
    });
    const data = await res.json();
    if (data.success) {
        showToast('Comentario actualizado');
        if (currentFullIssueId) await loadFullIssueComments(currentFullIssueId);
        if (currentIssueId)     await loadComments(currentIssueId);
    } else {
        showToast(data.error || 'Error al actualizar', 'error');
        btn.disabled = false;
    }
}

function cancelCommentEdit(btn, originalText) {
    const contentEl = btn.closest('.comment-content-area');
    contentEl.innerHTML = `<span style="font-size:0.875rem;white-space:pre-wrap;">${originalText}</span>`;
}

async function loadAssigneePicker(issueId) {
    const [issueRes, membersRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/issues.php?action=get&id=${issueId}`),
        fetch(`${APP_URL}/app/api/team.php?action=members`)
    ]);
    const issueData = await issueRes.json();
    const membersData = await membersRes.json();
    const members = membersData.data || [];
    const currentAssignee = issueData.data?.assigned_to;

    const picker = document.getElementById('assignee-picker');
    picker.innerHTML = '<option value="">Sin asignar</option>' +
        members.map(m => `<option value="${m.id}" ${m.id == currentAssignee ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

    picker.onchange = async () => {
        const res = await fetch(`${APP_URL}/app/api/issues.php?action=update`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: issueId, assigned_to: picker.value ? parseInt(picker.value) : null })
        });
        const data = await res.json();
        if (data.success) { showToast('Assignee updated'); loadIssues(); }
        else showToast(data.error, 'error');
    };
}

async function loadIssueLabels(issueId) {
    const [labelsRes, allLabelsRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/labels.php?action=issue_labels&issue_id=${issueId}`),
        fetch(`${APP_URL}/app/api/labels.php?action=list&project_id=${PROJECT_ID}`)
    ]);
    const labelsData = await labelsRes.json();
    const allData = await allLabelsRes.json();
    const issueLabels = labelsData.data || [];
    const allLabels = allData.data || [];

    const list = document.getElementById('issue-labels-list');
    list.innerHTML = issueLabels.map(l => `
        <span class="label-chip" style="background:${escapeHtml(l.color)}22;color:${escapeHtml(l.color)};border:1px solid ${escapeHtml(l.color)};" data-id="${l.id}">
            ${escapeHtml(l.name)}
            <button onclick="removeLabelFromIssue(${l.id})" style="background:none;border:none;cursor:pointer;color:inherit;margin-left:0.2rem;">×</button>
        </span>
    `).join('') || '<em style="color:var(--text-tertiary);font-size:0.8rem;">No labels</em>';

    const existingIds = issueLabels.map(l => l.id);
    const picker = document.getElementById('label-picker');
    picker.innerHTML = '<option value="">Add label...</option>' +
        allLabels.filter(l => !existingIds.includes(l.id))
            .map(l => `<option value="${l.id}" style="color:${escapeHtml(l.color)}">${escapeHtml(l.name)}</option>`).join('');
}

async function removeLabelFromIssue(labelId) {
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=remove_from_issue`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentIssueId, label_id: labelId })
    });
    const data = await res.json();
    if (data.success) await loadIssueLabels(currentIssueId);
    else showToast(data.error, 'error');
}

document.getElementById('add-label-btn').addEventListener('click', async () => {
    const labelId = document.getElementById('label-picker').value;
    if (!labelId || !currentIssueId) return;
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=add_to_issue`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentIssueId, label_id: parseInt(labelId) })
    });
    const data = await res.json();
    if (data.success) await loadIssueLabels(currentIssueId);
    else showToast(data.error, 'error');
});

async function renderAllLabels() {
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=list&project_id=${PROJECT_ID}`);
    const data = await res.json();
    const list = document.getElementById('all-labels-list');
    const labels = data.data || [];
    if (!labels.length) { list.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">No labels yet</em>'; return; }
    list.innerHTML = labels.map(l => `
        <span data-label-id="${l.id}" style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.2rem 0.5rem;border-radius:999px;font-size:0.8rem;background:${escapeHtml(l.color)}22;color:${escapeHtml(l.color)};border:1px solid ${escapeHtml(l.color)};">
            ${escapeHtml(l.name)}
            <button onclick="startLabelEdit(${l.id}, ${JSON.stringify(escapeHtml(l.name))}, ${JSON.stringify(escapeHtml(l.color))})" title="Editar label" style="background:none;border:none;cursor:pointer;color:inherit;font-size:0.75rem;padding:0;line-height:1;">&#9998;</button>
            <button onclick="deleteLabel(${l.id})" title="Delete label" style="background:none;border:none;cursor:pointer;color:inherit;font-size:0.75rem;padding:0;line-height:1;">&#128465;</button>
        </span>
    `).join('');
}

function startLabelEdit(id, currentName, currentColor) {
    const chip = document.querySelector(`#all-labels-list [data-label-id="${id}"]`);
    if (!chip) return;
    chip.outerHTML = `
        <span data-label-id="${id}" style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.4rem;border-radius:6px;font-size:0.8rem;background:var(--bg-secondary);border:1px solid var(--input-border);">
            <input type="text" id="label-edit-name-${id}" value="${currentName}" style="padding:0.15rem 0.3rem;border:1px solid var(--input-border);border-radius:4px;font-size:0.78rem;width:7rem;">
            <input type="color" id="label-edit-color-${id}" value="${currentColor}" style="width:1.6rem;height:1.4rem;padding:0.05rem;border:1px solid var(--input-border);border-radius:4px;cursor:pointer;">
            <button onclick="saveLabelEdit(${id})" class="btn btn-primary" style="font-size:0.72rem;padding:0.15rem 0.4rem;">Guardar</button>
            <button onclick="renderAllLabels()" style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);font-size:1rem;line-height:1;">&#215;</button>
        </span>
    `;
    document.getElementById(`label-edit-name-${id}`)?.focus();
}

async function saveLabelEdit(id) {
    const nameInput  = document.getElementById(`label-edit-name-${id}`);
    const colorInput = document.getElementById(`label-edit-color-${id}`);
    if (!nameInput || !colorInput) return;
    const name  = nameInput.value.trim();
    const color = colorInput.value;
    if (!name) { showToast('El nombre no puede estar vacío', 'error'); return; }
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=update`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, name, color })
    });
    const data = await res.json();
    if (data.success) {
        showToast('Label actualizado');
        renderAllLabels();
        if (currentIssueId) await loadIssueLabels(currentIssueId);
    } else {
        showToast(data.error || 'Error al actualizar', 'error');
    }
}

async function deleteLabel(id) {
    showConfirm('¿Eliminar este label del proyecto? Se quitará de todas las issues. Esta acción no se puede deshacer.', async () => {
        const res = await fetch(`${APP_URL}/app/api/labels.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Label eliminado');
            renderAllLabels();
            if (currentIssueId) loadIssueLabels(currentIssueId);
        } else {
            showToast(data.error || 'Error al eliminar', 'error');
        }
    }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
}

document.getElementById('new-label-toggle').addEventListener('click', () => {
    const form = document.getElementById('new-label-form');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
    if (form.style.display === 'block') {
        document.getElementById('new-label-name').focus();
        renderAllLabels();
    }
});

document.getElementById('cancel-label-btn').addEventListener('click', () => {
    document.getElementById('new-label-form').style.display = 'none';
    document.getElementById('new-label-name').value = '';
});

document.getElementById('save-label-btn').addEventListener('click', async () => {
    const name  = document.getElementById('new-label-name').value.trim();
    const color = document.getElementById('new-label-color').value;
    if (!name) { showToast('Label name is required', 'error'); return; }
    const btn = document.getElementById('save-label-btn');
    btn.disabled = true; btn.textContent = 'Creating...';
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ project_id: PROJECT_ID, name, color })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Create';
    if (data.success) {
        showToast(`Label "${name}" created`);
        document.getElementById('new-label-name').value = '';
        renderAllLabels();
        if (currentIssueId) await loadIssueLabels(currentIssueId);
    } else {
        showToast(data.error || 'Failed to create label', 'error');
    }
});

function updateBcmPreview() {
    const type = document.getElementById('bcm-type').value;
    const name = document.getElementById('bcm-name').value.trim();
    document.getElementById('bcm-preview').textContent = name ? `${type}/${name}` : '';
}

function openBranchModal(issueId, suggestedName, onSuccess) {
    branchModalIssueId = issueId;
    branchModalOnSuccess = onSuccess;
    document.getElementById('bcm-name').value = suggestedName || '';
    document.getElementById('bcm-type').value = 'feature';
    updateBcmPreview();
    document.getElementById('branch-create-modal').classList.remove('hidden');
    document.getElementById('bcm-name').select();
}

document.getElementById('bcm-type').addEventListener('change', updateBcmPreview);
document.getElementById('bcm-name').addEventListener('input', updateBcmPreview);

document.getElementById('bcm-cancel').addEventListener('click', () => {
    document.getElementById('branch-create-modal').classList.add('hidden');
});

document.getElementById('bcm-confirm').addEventListener('click', async () => {
    const type = document.getElementById('bcm-type').value;
    const name = document.getElementById('bcm-name').value.trim();
    if (!name) { showToast('Escribe un nombre para la rama', 'error'); return; }
    if (!/^[a-zA-Z0-9._/-]+$/.test(name)) {
        showToast('Nombre de rama inválido. Usa solo letras, números, guiones y barras.', 'error');
        return;
    }
    const fullBranch = `${type}/${name}`;
    const btn = document.getElementById('bcm-confirm');
    btn.disabled = true; btn.textContent = 'Creando...';
    const res = await fetch(`${APP_URL}/app/api/github.php?action=create_branch`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: branchModalIssueId, branch_name: fullBranch, base_branch: '' })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Crear rama';
    if (data.success) {
        showToast(`Rama '${fullBranch}' creada`);
        document.getElementById('branch-create-modal').classList.add('hidden');
        if (branchModalOnSuccess) branchModalOnSuccess();
    } else {
        showToast(data.error || 'Error al crear la rama', 'error');
    }
});

document.getElementById('create-branch-btn').addEventListener('click', () => {
    openBranchModal(currentIssueId, currentBranchSuggestedName, () => loadBranches(currentIssueId));
});

async function deleteCurrentIssue() {
    showConfirm('¿Eliminar esta issue y todos sus comentarios, ramas y checklist? Esta acción no se puede deshacer.', async () => {
        const res = await fetch(`${APP_URL}/app/api/issues.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: currentIssueId })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('issue-detail').classList.add('hidden');
            document.getElementById('full-issue-view').classList.add('hidden');
            document.getElementById('issues-view').classList.remove('hidden');
            currentFullIssueId = null;
            showToast('Issue eliminada');
            loadIssues();
        } else {
            showToast(data.error || 'Error al eliminar', 'error');
        }
    }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
}

document.getElementById('close-detail').addEventListener('click', () => {
    document.getElementById('issue-detail').classList.add('hidden');
    currentIssueId = null;
});

document.getElementById('add-comment-btn').addEventListener('click', async () => {
    const body = document.getElementById('comment-input').value.trim();
    if (!body || !currentIssueId) return;
    const btn = document.getElementById('add-comment-btn');
    btn.disabled = true; btn.textContent = 'Enviando...';
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentIssueId, body })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Enviar';
    if (data.success) {
        document.getElementById('comment-input').value = '';
        await loadComments(currentIssueId);
    } else {
        showToast(data.error, 'error');
    }
});

// New issue modal
document.getElementById('new-issue-btn').addEventListener('click', async () => {
    document.getElementById('new-issue-modal').classList.remove('hidden');
    // Ensure team is loaded, then populate assignee select
    await loadTeamForMentions();
    const sel = document.getElementById('new-assigned');
    if (sel && sel.options.length <= 1) {
        mentionTeamCache.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.name;
            sel.appendChild(opt);
        });
    }
});
document.getElementById('new-title').addEventListener('input', function () {
    const branchEl = document.getElementById('new-branch-name');
    if (!branchEl) return;
    const slug = titleToSlug(this.value);
    branchEl.value = slug ? `issue-${slug}` : '';
});
document.getElementById('new-cancel').addEventListener('click', () => {
    document.getElementById('new-issue-modal').classList.add('hidden');
    document.getElementById('new-branch-name').value = '';
});
document.getElementById('new-save').addEventListener('click', async () => {
    const title = document.getElementById('new-title').value.trim();
    if (!title) { showToast('El título es obligatorio', 'error'); return; }
    const typeVal        = document.getElementById('new-type')?.value;
    const priorityVal    = document.getElementById('new-priority')?.value || 'medium';
    const assignedVal    = document.getElementById('new-assigned')?.value;
    const dueDateVal     = document.getElementById('new-due-date')?.value;
    const storyPtsVal    = document.getElementById('new-story-points')?.value;
    const btn = document.getElementById('new-save');
    btn.disabled = true; btn.classList.add('btn-loading');
    await fetch(`${APP_URL}/app/api/issues.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            project_id:   PROJECT_ID,
            title,
            description:  document.getElementById('new-desc').value,
            priority:     priorityVal,
            type_id:      typeVal ? parseInt(typeVal) : null,
            assigned_to:  assignedVal ? parseInt(assignedVal) : null,
            due_date:     dueDateVal  || null,
            story_points: storyPtsVal ? parseInt(storyPtsVal) : null,
        })
    });
    btn.disabled = false; btn.classList.remove('btn-loading');
    document.getElementById('new-issue-modal').classList.add('hidden');
    document.getElementById('new-title').value = '';
    document.getElementById('new-desc').value = '';
    if (document.getElementById('new-type'))         document.getElementById('new-type').value = '';
    if (document.getElementById('new-assigned'))     document.getElementById('new-assigned').value = '';
    if (document.getElementById('new-due-date'))     document.getElementById('new-due-date').value = '';
    if (document.getElementById('new-story-points')) document.getElementById('new-story-points').value = '';
    document.getElementById('new-branch-name').value = '';
    loadIssues();
});

// ── PR creation ──────────────────────────────────────────────────────────────

async function openCreatePRModal(branchName) {
    currentPRBranch = branchName;
    document.getElementById('pr-branch-display').textContent = branchName;
    const detailTitle = document.getElementById('detail-title')?.textContent?.replace(/^#\d+\s*/, '') || '';
    const issueTitle = detailTitle || document.getElementById('fi-title')?.value?.trim() || '';
    document.getElementById('pr-title').value = issueTitle;
    document.getElementById('pr-body').value = '';
    document.getElementById('create-pr-modal').classList.remove('hidden');
    document.getElementById('pr-title').select();

    // Load repo branches for base branch selector
    const sel = document.getElementById('pr-base-branch');
    sel.innerHTML = '<option value="">Loading...</option>';
    sel.disabled = true;
    try {
        const res = await fetch(`${APP_URL}/app/api/github.php?action=repo_branches&project_id=${PROJECT_ID}`);
        const data = await res.json();
        const branches = data.data || ['main'];
        sel.innerHTML = branches
            .filter(b => b !== branchName)
            .map(b => `<option value="${escapeHtml(b)}"${b === 'main' ? ' selected' : ''}>${escapeHtml(b)}</option>`)
            .join('');
    } catch {
        sel.innerHTML = '<option value="main">main</option>';
    }
    sel.disabled = false;
}

document.getElementById('pr-cancel').addEventListener('click', () => {
    document.getElementById('create-pr-modal').classList.add('hidden');
});

document.getElementById('pr-submit').addEventListener('click', async () => {
    const title = document.getElementById('pr-title').value.trim();
    if (!title || !currentPRBranch || !currentIssueId) return;
    const btn = document.getElementById('pr-submit');
    btn.disabled = true; btn.textContent = 'Creating...';
    const res = await fetch(`${APP_URL}/app/api/github.php?action=create_pr`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ issue_id: currentIssueId, branch: currentPRBranch, base: document.getElementById('pr-base-branch').value || 'main', title, body: document.getElementById('pr-body').value })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Create PR';
    if (data.success) {
        document.getElementById('create-pr-modal').classList.add('hidden');
        showToast(`PR #${data.pr_number} creado. Haz clic en "Revisar y fusionar" para ver el diff`);
        if (currentFullIssueId) {
            await Promise.all([loadFullIssuePRs(currentFullIssueId), loadFullIssueBranches(currentFullIssueId)]);
        } else {
            await loadPRs(currentIssueId);
            await loadBranches(currentIssueId);
            // Scroll to PR section
            document.querySelector('.prs-section')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    } else {
        showToast(data.error || 'Failed to create PR', 'error');
    }
});

// ── Diff viewer ───────────────────────────────────────────────────────────────

let currentDiffPRNumber = null;

async function openDiffViewer(prNumber) {
    currentDiffPRNumber = prNumber;
    const modal = document.getElementById('diff-viewer-modal');
    const content = document.getElementById('diff-content');
    const summary = document.getElementById('diff-summary');
    const titleEl = document.getElementById('diff-pr-title');
    const mergeArea = document.getElementById('diff-merge-area');
    const ghLink = document.getElementById('diff-gh-link');

    const pr = currentPRs.find(p => p.number === prNumber);
    titleEl.textContent = pr ? `PR #${pr.number}: ${pr.title}` : `PR #${prNumber} Diff`;
    // Show head → base under the title
    let routeEl = document.getElementById('diff-pr-route');
    if (!routeEl) {
        routeEl = document.createElement('div');
        routeEl.id = 'diff-pr-route';
        routeEl.style.cssText = 'font-size:0.8rem;font-family:monospace;color:var(--text-secondary);margin-top:0.2rem;';
        titleEl.after(routeEl);
    }
    if (pr?.head && pr?.base) {
        routeEl.innerHTML = `&#127807; ${escapeHtml(pr.head)} <span style="font-family:sans-serif;">&#8594;</span> ${escapeHtml(pr.base)}`;
    } else {
        routeEl.textContent = '';
    }

    // Show merge button only for open (non-merged) PRs
    const canMerge = pr && pr.state === 'open' && !pr.merged;
    mergeArea.style.display = canMerge ? 'flex' : 'none';
    if (pr?.url) {
        ghLink.href = pr.url;
        ghLink.style.display = 'inline';
    } else {
        ghLink.style.display = 'none';
    }
    document.getElementById('merge-pr-btn').disabled = false;
    document.getElementById('merge-pr-btn').textContent = '✓ Merge PR';

    modal.classList.remove('hidden');
    content.innerHTML = '<div style="color:var(--text-tertiary);text-align:center;padding:2rem;">Loading diff...</div>';
    summary.innerHTML = '';

    const res = await fetch(`${APP_URL}/app/api/github.php?action=pr_diff&issue_id=${currentIssueId}&pr_number=${prNumber}`);
    const data = await res.json();
    if (!data.success) {
        content.innerHTML = `<div style="color:#dc2626;padding:1rem;">Error: ${escapeHtml(data.error)}</div>`;
        return;
    }
    const files = data.files || [];
    const totalAdd = files.reduce((s, f) => s + f.additions, 0);
    const totalDel = files.reduce((s, f) => s + f.deletions, 0);
    summary.innerHTML = `${files.length} file${files.length !== 1 ? 's' : ''} changed &nbsp;
        <span style="color:#16a34a;font-weight:600;">+${totalAdd}</span> &nbsp;
        <span style="color:#dc2626;font-weight:600;">-${totalDel}</span>`;
    if (!files.length) {
        content.innerHTML = '<div style="color:var(--text-tertiary);text-align:center;padding:2rem;">No file changes found.</div>';
        return;
    }
    content.innerHTML = files.map(renderFileDiff).join('');
}

document.getElementById('diff-close').addEventListener('click', () => {
    document.getElementById('diff-viewer-modal').classList.add('hidden');
});

document.getElementById('merge-pr-btn').addEventListener('click', () => {
    const method = document.getElementById('merge-method').value;
    const methodLabel = { merge: 'Merge commit', squash: 'Squash & merge', rebase: 'Rebase & merge' }[method] || 'Merge';
    showConfirm(
        `¿Confirmar "${methodLabel}" de esta pull request?`,
        async () => {
            const btn = document.getElementById('merge-pr-btn');
            btn.disabled = true; btn.textContent = 'Merging...';
            const res = await fetch(`${APP_URL}/app/api/github.php?action=merge_pr`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ issue_id: currentIssueId, pr_number: currentDiffPRNumber, merge_method: method })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Pull request merged!');
                document.getElementById('diff-viewer-modal').classList.add('hidden');
                if (currentFullIssueId) {
                    await Promise.all([loadFullIssuePRs(currentFullIssueId), loadFullIssueBranches(currentFullIssueId)]);
                } else {
                    await loadPRs(currentIssueId);
                    await loadBranches(currentIssueId);
                }
                fetch(`${APP_URL}/app/api/github.php?action=sync_pr_status`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ issue_id: currentIssueId })
                }).then(r => r.json()).then(d => { if (d.synced) loadIssues(); });
            } else {
                btn.disabled = false; btn.textContent = '✓ Merge PR';
                if (data.conflict) {
                    showMergeConflictBanner(data.pr_url);
                } else {
                    const msg = data.error || 'Failed to merge PR';
                    showToast(msg + (data.pr_url ? ' — ver PR en GitHub' : ''), 'error');
                }
            }
        },
        { confirmLabel: 'Merge', confirmClass: 'btn-primary' }
    );
});

function showMergeConflictBanner(prUrl) {
    // Find conflict markers in already-loaded diff content
    const diffContent = document.getElementById('diff-content');
    const conflictFiles = [];
    diffContent.querySelectorAll('[data-filename]').forEach(el => {
        if (el.textContent.includes('<<<<<<<') || el.textContent.includes('=======')) {
            conflictFiles.push(el.dataset.filename);
        }
    });

    const banner = document.createElement('div');
    banner.style.cssText = 'background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:1rem;margin-bottom:1rem;';
    banner.innerHTML = `
        <div style="font-weight:700;color:#dc2626;margin-bottom:0.5rem;">&#9888; Conflictos de merge</div>
        <p style="font-size:0.875rem;color:#374151;margin:0 0 0.75rem;">
            Los ficheros marcados en rojo tienen conflictos con la rama destino.
            Debes resolverlos antes de hacer merge.
        </p>
        ${prUrl ? `<a href="${escapeHtml(prUrl)}" target="_blank"
            style="display:inline-block;padding:0.4rem 1rem;background:var(--color-primary);color:#fff;border-radius:6px;font-size:0.875rem;text-decoration:none;font-weight:600;">
            Resolver conflictos en GitHub &#8599;
        </a>` : ''}
    `;

    // Insert banner at top of diff content
    diffContent.insertBefore(banner, diffContent.firstChild);
    diffContent.scrollTop = 0;
}

function renderFileDiff(file) {
    const statusColors = { added: '#16a34a', removed: '#dc2626', modified: '#d97706', renamed: '#7c3aed' };
    const color = statusColors[file.status] || '#6b7280';
    const patchHtml = file.patch
        ? renderPatch(file.patch)
        : '<div style="color:var(--text-tertiary);padding:0.5rem;font-size:0.8rem;font-style:italic;">Binary or no diff available.</div>';
    return `<div data-filename="${escapeHtml(file.filename)}" style="margin-bottom:1.25rem;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
        <div style="padding:0.4rem 0.75rem;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.25rem;">
            <span style="font-family:monospace;font-size:0.82rem;font-weight:600;word-break:break-all;">${escapeHtml(file.filename)}</span>
            <div style="font-size:0.8rem;display:flex;gap:0.6rem;align-items:center;flex-shrink:0;">
                <span style="color:${color};font-weight:600;">${file.status}</span>
                <span style="color:#16a34a;">+${file.additions}</span>
                <span style="color:#dc2626;">-${file.deletions}</span>
            </div>
        </div>
        <div style="font-family:monospace;font-size:0.8rem;overflow-x:auto;">${patchHtml}</div>
    </div>`;
}

function renderPatch(patch) {
    return patch.split('\n').map(line => {
        let bg = '', color = '#374151';
        if (line.startsWith('+') && !line.startsWith('+++')) { bg = '#dcfce7'; color = '#166534'; }
        else if (line.startsWith('-') && !line.startsWith('---')) { bg = '#fee2e2'; color = '#991b1b'; }
        else if (line.startsWith('@@')) { bg = '#eff6ff'; color = '#1e40af'; }
        const content = escapeHtml(line) || '&nbsp;';
        return `<div style="white-space:pre;padding:0.05rem 0.75rem;background:${bg};color:${color};">${content}</div>`;
    }).join('');
}

// ── Full Issue View Modal ──────────────────────────────────────────────────

let currentFullIssueId = null;

async function openFullIssue(id) {
    currentFullIssueId = id;
    currentIssueId = id; // ensure diff viewer works from this view

    document.getElementById('issues-view').classList.add('hidden');
    document.getElementById('full-issue-view').classList.remove('hidden');
    window.scrollTo(0, 0);

    document.getElementById('fi-id').textContent = `#${id}`;
    document.getElementById('fi-title').value = '';
    document.getElementById('fi-desc').value = '';
    document.getElementById('fi-created-at').textContent = '';
    document.getElementById('fi-comments-list').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Cargando...</em>';
    document.getElementById('fi-tests-list').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Cargando...</em>';
    document.getElementById('fi-labels').innerHTML = '';
    document.getElementById('fi-branches').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Cargando...</em>';
    document.getElementById('fi-prs').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Cargando...</em>';
    document.getElementById('fi-checklist-items').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Cargando...</em>';
    document.getElementById('fi-checklist-progress').textContent = '';
    document.getElementById('fi-status-log').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Cargando...</em>';
    const fiDepsList = document.getElementById('fi-deps-list');
    if (fiDepsList) fiDepsList.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.85rem;">Cargando...</em>';

    const [issueRes, membersRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/issues.php?action=get&id=${id}`),
        fetch(`${APP_URL}/app/api/team.php?action=members`)
    ]);
    const issueData = await issueRes.json();
    const membersData = await membersRes.json();
    const issue = issueData.data;
    if (!issue) {
        showToast('Issue not found', 'error');
        document.getElementById('full-issue-view').classList.add('hidden');
        document.getElementById('issues-view').classList.remove('hidden');
        return;
    }
    const members = membersData.data || [];

    document.getElementById('fi-id').textContent = `#${issue.id}`;
    document.getElementById('fi-title').value = issue.title || '';
    document.getElementById('fi-desc').value = issue.description || '';
    document.getElementById('fi-status').value   = issue.status   || 'todo';
    document.getElementById('fi-priority').value = issue.priority || 'medium';
    const fiType = document.getElementById('fi-type');
    if (fiType) fiType.value = issue.type_id ? String(issue.type_id) : '';
    document.getElementById('fi-created-at').textContent = issue.created_at
        ? new Date(issue.created_at).toLocaleString('es-ES', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' })
        : '';
    document.getElementById('fi-due-date').value = issue.due_date ? issue.due_date.substring(0, 10) : '';
    document.getElementById('fi-points').value = issue.story_points || '';

    const assigneeSel = document.getElementById('fi-assignee');
    assigneeSel.innerHTML = '<option value="">Sin asignar</option>' +
        members.map(m => `<option value="${m.id}"${m.id == issue.assigned_to ? ' selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

    // Reset to Descripción tab on every open
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
}

async function loadFullIssueLabels(id) {
    const [labelsRes, allRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/labels.php?action=issue_labels&issue_id=${id}`),
        fetch(`${APP_URL}/app/api/labels.php?action=list&project_id=${PROJECT_ID}`)
    ]);
    const labelsData = await labelsRes.json();
    const allData = await allRes.json();
    const issueLabels = labelsData.data || [];
    const allLabels = allData.data || [];

    const el = document.getElementById('fi-labels');
    el.innerHTML = issueLabels.length
        ? issueLabels.map(l => `
            <span class="label-chip" style="background:${escapeHtml(l.color)}22;color:${escapeHtml(l.color)};border:1px solid ${escapeHtml(l.color)};font-size:0.75rem;display:inline-flex;align-items:center;gap:0.2rem;">
                ${escapeHtml(l.name)}
                <button onclick="removeLabelFromFullIssue(${l.id})" style="background:none;border:none;cursor:pointer;color:inherit;padding:0;font-size:0.9rem;line-height:1;">&#215;</button>
            </span>`).join('')
        : '<em style="color:var(--text-tertiary);font-size:0.75rem;">Sin labels</em>';

    const existingIds = issueLabels.map(l => l.id);
    const picker = document.getElementById('fi-label-picker');
    if (picker) {
        picker.innerHTML = '<option value="">Añadir label...</option>' +
            allLabels.filter(l => !existingIds.includes(l.id))
                .map(l => `<option value="${l.id}" style="color:${escapeHtml(l.color)}">${escapeHtml(l.name)}</option>`).join('');
    }
}

async function removeLabelFromFullIssue(labelId) {
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=remove_from_issue`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentFullIssueId, label_id: labelId })
    });
    const data = await res.json();
    if (data.success) await loadFullIssueLabels(currentFullIssueId);
    else showToast(data.error, 'error');
}

async function loadFullIssueBranches(id) {
    const [repoRes, prsRes] = await Promise.all([
        fetch(`${APP_URL}/app/api/github.php?action=repo_status&project_id=${PROJECT_ID}`),
        fetch(`${APP_URL}/app/api/github.php?action=prs&issue_id=${id}`)
    ]);
    const repoData = await repoRes.json();
    const prsData = await prsRes.json();
    const prs = prsData.data || [];
    currentPRs = prs;
    const createArea = document.getElementById('fi-create-branch-area');

    if (!repoData.connected) {
        document.getElementById('fi-branches').innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin repositorio conectado</em>';
        createArea.style.display = 'none';
        return;
    }

    createArea.style.display = 'block';

    const res = await fetch(`${APP_URL}/app/api/github.php?action=branches&issue_id=${id}`);
    const data = await res.json();
    const branches = data.data || [];
    const el = document.getElementById('fi-branches');
    if (!branches.length) { el.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin ramas aún</em>'; }
    else {
        const sonarResults = await Promise.all(branches.map(b =>
            fetch(`${APP_URL}/app/api/sonarqube.php?action=status&project_id=${PROJECT_ID}&branch=${encodeURIComponent(b.branch_name)}`)
                .then(r => r.json())
                .catch(() => ({ success: false }))
        ));
        el.innerHTML = branches.map((b, i) => {
            const sq = sonarResults[i] || {};
            const chip = sonarQGChip(sq.success ? sq.status : null, sq.url);
            const existingPR = prs.find(pr => pr.branch === b.branch_name);
            const prBtn = existingPR
                ? `<button onclick="openDiffViewer(${existingPR.number})" style="font-size:0.75rem;color:#fff;background:var(--color-primary);border:none;border-radius:4px;padding:0.25rem 0.6rem;cursor:pointer;font-weight:600;">PR #${existingPR.number} &#128065;</button>`
                : `<button onclick="openCreatePRModal('${escapeHtml(b.branch_name)}')" style="font-size:0.75rem;color:#16a34a;background:none;border:1px solid #16a34a;border-radius:4px;padding:0.25rem 0.6rem;cursor:pointer;">&#8593; Create PR</button>`;
            return `<div class="branch-item" style="padding:0.25rem 0;border-bottom:1px solid var(--border);">
                <div style="font-size:0.78rem;display:flex;align-items:center;justify-content:space-between;gap:0.4rem;overflow:hidden;">
                    <span style="font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">&#127807; ${escapeHtml(b.branch_name)}${chip ? ' ' + chip : ''}</span>
                    <div style="display:flex;gap:0.3rem;flex-shrink:0;">
                        ${prBtn}
                        <button onclick="toggleCommits(${id}, '${escapeHtml(b.branch_name)}', this)" style="font-size:0.75rem;color:var(--color-primary);background:none;border:none;cursor:pointer;">&#9654; Commits</button>
                    </div>
                </div>
                <div class="commits-panel" style="display:none;margin-top:0.4rem;padding-left:0.75rem;border-left:2px solid #e5e7eb;"></div>
            </div>`;
        }).join('');
    }
}

document.getElementById('fi-create-branch-btn')?.addEventListener('click', () => {
    const slug = titleToSlug(document.getElementById('fi-title').value);
    const suggested = slug ? `issue-${currentFullIssueId}-${slug}` : `issue-${currentFullIssueId}`;
    openBranchModal(currentFullIssueId, suggested, () => loadFullIssueBranches(currentFullIssueId));
});

async function loadFullIssuePRs(id) {
    const res = await fetch(`${APP_URL}/app/api/github.php?action=prs&issue_id=${id}`);
    const data = await res.json();
    const prs = data.data || [];
    currentPRs = prs; // keep in sync so openDiffViewer works from this modal
    const el = document.getElementById('fi-prs');
    if (!prs.length) { el.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin PRs</em>'; return; }
    el.innerHTML = prs.map(pr => {
        const isMerged = pr.merged;
        const isOpen = pr.state === 'open' && !isMerged;
        const color = isMerged ? '#7c3aed' : isOpen ? '#16a34a' : '#6b7280';
        const label = isMerged ? 'merged' : pr.state;
        return `<div style="padding:0.35rem 0;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.3rem;">
                <span style="font-size:0.8rem;"><span style="color:${color};font-weight:700;">&#11044; ${label}</span> <span style="font-family:monospace;">#${pr.number}</span></span>
                <button onclick="openDiffViewer(${pr.number})" style="font-size:0.72rem;color:var(--color-primary);background:none;border:1px solid rgba(255,166,43,0.35);border-radius:4px;padding:0.2rem 0.4rem;cursor:pointer;flex-shrink:0;">Diff &#128065;</button>
            </div>
            <div style="font-size:0.75rem;font-family:monospace;color:var(--text-tertiary);margin-top:0.1rem;">&#127807; ${escapeHtml(pr.head||pr.branch)} &#8594; ${escapeHtml(pr.base||'main')}</div>
        </div>`;
    }).join('');
}

async function loadFullIssueComments(id) {
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=list&issue_id=${id}`);
    const data = await res.json();
    const comments = data.data || [];
    const list = document.getElementById('fi-comments-list');
    if (!comments.length) { list.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin comentarios.</em>'; return; }
    const canEdit = c => c.user_id === CURRENT_USER_ID || CURRENT_USER_ROLE === 'admin';
    list.innerHTML = comments.map(c => `
        <div class="comment-item" data-id="${c.id}" style="padding:0.6rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:0.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.25rem;">
                <strong style="font-size:0.875rem;">${escapeHtml(c.author_name)}</strong>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <span title="${new Date(c.created_at || '').toLocaleString()}" style="font-size:0.75rem;color:var(--text-tertiary);">${timeAgo(c.created_at)}</span>
                    ${canEdit(c) ? `<button onclick="startCommentEdit(${c.id}, ${JSON.stringify(c.content)}, this.closest('.comment-item').querySelector('.comment-content-area'))" style="font-size:0.75rem;color:var(--color-primary);background:none;border:none;cursor:pointer;padding:0;">Editar</button>` : ''}
                    <button onclick="deleteFiComment(${c.id})" style="font-size:0.75rem;color:#dc2626;background:none;border:none;cursor:pointer;padding:0;">Eliminar</button>
                </div>
            </div>
            <div class="comment-content-area"><span style="font-size:0.875rem;white-space:pre-wrap;">${highlightMentions(c.content)}</span></div>
        </div>
    `).join('');
}

document.getElementById('fi-back').addEventListener('click', () => {
    document.getElementById('full-issue-view').classList.add('hidden');
    document.getElementById('issues-view').classList.remove('hidden');
    currentFullIssueId = null;
});

document.getElementById('fi-save').addEventListener('click', async () => {
    if (!currentFullIssueId) return;
    const btn = document.getElementById('fi-save');
    btn.disabled = true; btn.textContent = 'Guardando...';
    const res = await fetch(`${APP_URL}/app/api/issues.php?action=update`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id:           currentFullIssueId,
            title:        document.getElementById('fi-title').value.trim(),
            description:  document.getElementById('fi-desc').value,
            status:       document.getElementById('fi-status').value,
            priority:     document.getElementById('fi-priority').value,
            type_id:      document.getElementById('fi-type')?.value ? parseInt(document.getElementById('fi-type').value) : null,
            assigned_to:  document.getElementById('fi-assignee').value ? parseInt(document.getElementById('fi-assignee').value) : null,
            due_date:     document.getElementById('fi-due-date').value || null,
            story_points: (() => { const v = document.getElementById('fi-points').value; return v ? parseInt(v) : null; })(),
        })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Guardar cambios';
    if (data.success) {
        showToast('Issue actualizada');
        loadIssues();
    } else {
        showToast(data.error || 'Error al guardar', 'error');
    }
});

document.getElementById('fi-add-comment').addEventListener('click', async () => {
    const body = document.getElementById('fi-comment-input').value.trim();
    if (!body || !currentFullIssueId) return;
    const btn = document.getElementById('fi-add-comment');
    btn.disabled = true; btn.textContent = 'Enviando...';
    const res = await fetch(`${APP_URL}/app/api/comments.php?action=create`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ issue_id: currentFullIssueId, body })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Enviar';
    if (data.success) {
        document.getElementById('fi-comment-input').value = '';
        await loadFullIssueComments(currentFullIssueId);
        if (currentIssueId === currentFullIssueId) await loadComments(currentFullIssueId);
    } else {
        showToast(data.error, 'error');
    }
});

// ── Full Issue: Label management ──────────────────────────────────────────────

document.getElementById('fi-add-label-btn').addEventListener('click', async () => {
    const labelId = document.getElementById('fi-label-picker').value;
    if (!labelId || !currentFullIssueId) return;
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=add_to_issue`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentFullIssueId, label_id: parseInt(labelId) })
    });
    const data = await res.json();
    if (data.success) await loadFullIssueLabels(currentFullIssueId);
    else showToast(data.error, 'error');
});

document.getElementById('fi-new-label-toggle').addEventListener('click', () => {
    const form = document.getElementById('fi-new-label-form');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
    if (form.style.display === 'block') document.getElementById('fi-new-label-name').focus();
});

document.getElementById('fi-cancel-label-btn').addEventListener('click', () => {
    document.getElementById('fi-new-label-form').style.display = 'none';
    document.getElementById('fi-new-label-name').value = '';
});

document.getElementById('fi-save-label-btn').addEventListener('click', async () => {
    const name  = document.getElementById('fi-new-label-name').value.trim();
    const color = document.getElementById('fi-new-label-color').value;
    if (!name) { showToast('Nombre del label requerido', 'error'); return; }
    const btn = document.getElementById('fi-save-label-btn');
    btn.disabled = true; btn.textContent = 'Creando...';
    const res = await fetch(`${APP_URL}/app/api/labels.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ project_id: PROJECT_ID, name, color })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Crear';
    if (data.success) {
        showToast(`Label "${name}" creado`);
        document.getElementById('fi-new-label-name').value = '';
        document.getElementById('fi-new-label-form').style.display = 'none';
        if (currentFullIssueId) await loadFullIssueLabels(currentFullIssueId);
    } else {
        showToast(data.error || 'Error al crear label', 'error');
    }
});

// ── Full Issue: Checklist ──────────────────────────────────────────────────────

async function loadChecklist(issueId) {
    const res = await fetch(`${APP_URL}/app/api/checklist.php?action=list&issue_id=${issueId}`);
    const data = await res.json();
    const items = data.data || [];
    const container = document.getElementById('fi-checklist-items');
    const progress  = document.getElementById('fi-checklist-progress');

    const checked = items.filter(i => i.checked).length;
    if (progress) progress.textContent = items.length ? `${checked}/${items.length}` : '';

    if (!items.length) {
        container.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin elementos aún.</em>';
        return;
    }
    container.innerHTML = items.map(item => `
        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.35rem 0;border-bottom:1px solid var(--border);">
            <input type="checkbox" ${item.checked ? 'checked' : ''}
                onchange="toggleChecklistItem(${item.id}, this.checked)"
                style="width:1rem;height:1rem;cursor:pointer;flex-shrink:0;accent-color:var(--color-primary);">
            <span style="${item.checked ? 'text-decoration:line-through;color:var(--text-tertiary);' : ''}flex:1;font-size:0.875rem;">${escapeHtml(item.text)}</span>
            <button onclick="deleteChecklistItem(${item.id})"
                style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);font-size:1.1rem;padding:0;flex-shrink:0;line-height:1;" title="Eliminar">&#215;</button>
        </div>
    `).join('');
}

async function toggleChecklistItem(id, checked) {
    await fetch(`${APP_URL}/app/api/checklist.php?action=update`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, checked: checked ? 1 : 0 })
    });
    await loadChecklist(currentFullIssueId);
}

async function deleteChecklistItem(id) {
    showConfirm('¿Eliminar este elemento del checklist?', async () => {
        await fetch(`${APP_URL}/app/api/checklist.php?action=delete`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        await loadChecklist(currentFullIssueId);
    }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
}

document.getElementById('fi-checklist-add').addEventListener('click', async () => {
    const input = document.getElementById('fi-checklist-input');
    const text = input.value.trim();
    if (!text || !currentFullIssueId) return;
    input.disabled = true;
    const res = await fetch(`${APP_URL}/app/api/checklist.php?action=create`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ issue_id: currentFullIssueId, text })
    });
    const data = await res.json();
    input.disabled = false;
    if (data.success) {
        input.value = '';
        await loadChecklist(currentFullIssueId);
    } else {
        showToast(data.error, 'error');
    }
});

document.getElementById('fi-checklist-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('fi-checklist-add').click();
});

// ── Full Issue: Status log ─────────────────────────────────────────────────────

const STATUS_COLORS = { todo: '#6b7280', in_progress: '#2563eb', review: '#d97706', done: '#16a34a' };
const STATUS_LABELS = { todo: 'Pendiente', in_progress: 'En curso', review: 'Revisión', done: 'Hecho' };

async function loadStatusLog(issueId) {
    const res = await fetch(`${APP_URL}/app/api/issues.php?action=status_log&id=${issueId}`);
    const data = await res.json();
    const log  = data.data || [];
    const el   = document.getElementById('fi-status-log');

    if (!log.length) {
        el.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.8rem;">Sin cambios de estado registrados.</em>';
        return;
    }
    el.innerHTML = log.map((entry, i) => {
        const color = STATUS_COLORS[entry.new_status] || '#6b7280';
        const label = STATUS_LABELS[entry.new_status] || entry.new_status;
        const from  = entry.old_status ? ` desde <span style="color:var(--text-tertiary);">${STATUS_LABELS[entry.old_status] || entry.old_status}</span>` : '';
        const date  = new Date(entry.changed_at).toLocaleString('es-ES', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' });
        return `
            <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.35rem 0;${i < log.length - 1 ? 'border-bottom:1px solid var(--border);' : ''}">
                <div style="width:8px;height:8px;border-radius:50%;background:${color};flex-shrink:0;margin-top:0.3rem;"></div>
                <div>
                    <span style="font-weight:600;color:${color};">${label}</span>${from}
                    <div style="font-size:0.75rem;color:var(--text-tertiary);">${date} &middot; ${escapeHtml(entry.changed_by_name || 'Sistema')}</div>
                </div>
            </div>`;
    }).join('');
}

// ── Dependencies ──────────────────────────────────────────────────────────────
async function loadDependencies(issueId) {
    const res = await fetch(`${APP_URL}/app/api/dependencies.php?action=list&issue_id=${issueId}`);
    const data = await res.json();
    const list = document.getElementById('fi-deps-list');
    if (!list) return;
    const outgoing = data.outgoing || [];
    const incoming = data.incoming || [];
    if (!outgoing.length && !incoming.length) {
        list.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.85rem;">Sin dependencias.</em>';
        return;
    }
    const statusColor = s => ({ todo:'#9ca3af', in_progress:'#f59e0b', review:'#3b82f6', done:'#16a34a' }[s] || '#9ca3af');
    const renderRow = (dep, direction) => {
        const label = direction === 'out'
            ? (dep.type === 'blocks' ? 'Bloquea a' : 'Relacionada con')
            : (dep.type === 'blocks' ? 'Bloqueada por' : 'Relacionada con');
        return `<div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0.5rem;border:1px solid var(--border,#e5e7eb);border-radius:6px;margin-bottom:0.3rem;font-size:0.85rem;background:var(--bg-card,#fff);">
            <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <span style="color:var(--text-secondary);margin-right:0.3rem;">${label}:</span>
                <strong>#${escapeHtml(String(dep.issue_id))}</strong> ${escapeHtml(dep.title)}
                <span style="color:${statusColor(dep.status)};margin-left:0.3rem;">(${escapeHtml(dep.status)})</span>
            </div>
            <button onclick="removeDependency(${dep.id})" style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);font-size:1.1rem;line-height:1;flex-shrink:0;margin-left:0.4rem;" title="Eliminar">&times;</button>
        </div>`;
    };
    list.innerHTML = [
        ...outgoing.map(d => renderRow(d, 'out')),
        ...incoming.map(d => renderRow(d, 'in'))
    ].join('');
}

async function removeDependency(depId) {
    await apiFetch(`${APP_URL}/app/api/dependencies.php?action=remove`, { id: depId });
    await loadDependencies(currentFullIssueId);
}

function initDependencies() {
    document.getElementById('fi-add-dep-btn')?.addEventListener('click', async () => {
        const toId = parseInt(document.getElementById('fi-dep-issue-id').value);
        const type = document.getElementById('fi-dep-type').value;
        if (!toId || isNaN(toId)) return showToast('Escribe el ID de la issue', 'error');
        const data = await apiFetch(`${APP_URL}/app/api/dependencies.php?action=add`, {
            from_issue_id: currentFullIssueId,
            to_issue_id: toId,
            type
        });
        if (data.success) {
            document.getElementById('fi-dep-issue-id').value = '';
            await loadDependencies(currentFullIssueId);
            showToast('Dependencia añadida', 'success');
        } else {
            showToast(data.error || 'Error al añadir dependencia', 'error');
        }
    });
}

// --- @mentions ---
let mentionTeamCache = [];

async function loadTeamForMentions() {
    if (mentionTeamCache.length) return;
    try {
        const res  = await fetch(`${APP_URL}/app/api/team.php?action=members`);
        const data = await res.json();
        mentionTeamCache = (data.data || []);
    } catch(e) {}
}

function initMentionOnTextarea(textarea) {
    if (!textarea || textarea.dataset.mentionInit) return;
    textarea.dataset.mentionInit = '1';
    const dropdown = document.getElementById('mention-dropdown');

    textarea.addEventListener('input', function() {
        const val    = textarea.value;
        const cursor = textarea.selectionStart;
        const before = val.slice(0, cursor);
        const match  = before.match(/@(\w*)$/);
        if (!match) { dropdown.style.display = 'none'; return; }
        const query   = match[1].toLowerCase();
        const members = mentionTeamCache.filter(m => m.name.toLowerCase().includes(query)).slice(0, 6);
        if (!members.length) { dropdown.style.display = 'none'; return; }

        dropdown.innerHTML = members.map(m =>
            `<div class="mention-item" data-name="${escapeHtml(m.name)}" style="padding:0.5rem 0.75rem;cursor:pointer;font-size:0.875rem;">
                ${escapeHtml(m.name)}
            </div>`
        ).join('');
        dropdown.style.display = 'block';

        // Position near textarea
        const rect = textarea.getBoundingClientRect();
        dropdown.style.top  = (rect.bottom + window.scrollY + 4) + 'px';
        dropdown.style.left = Math.min(rect.left, window.innerWidth - 290) + 'px';
    });

    dropdown.addEventListener('click', function(e) {
        const item = e.target.closest('.mention-item');
        if (!item) return;
        const name   = item.dataset.name;
        const val    = textarea.value;
        const cursor = textarea.selectionStart;
        const before = val.slice(0, cursor);
        const after  = val.slice(cursor);
        const newBefore = before.replace(/@\w*$/, '@' + name + ' ');
        textarea.value = newBefore + after;
        textarea.selectionStart = textarea.selectionEnd = newBefore.length;
        dropdown.style.display = 'none';
        textarea.focus();
    });

    textarea.addEventListener('keydown', function(e) {
        if (dropdown.style.display !== 'none') {
            const items = dropdown.querySelectorAll('.mention-item');
            const active = dropdown.querySelector('.mention-item.mention-active');
            let idx = Array.from(items).indexOf(active);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (active) active.classList.remove('mention-active');
                items[Math.min(idx + 1, items.length - 1)].classList.add('mention-active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (active) active.classList.remove('mention-active');
                items[Math.max(idx - 1, 0)].classList.add('mention-active');
            } else if (e.key === 'Enter' && active) {
                e.preventDefault();
                active.click();
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
            }
        }
    });

    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && e.target !== textarea) {
            dropdown.style.display = 'none';
        }
    }, { once: false });
}

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
        showToast(`${ids.length} issue${ids.length > 1 ? 's' : ''} marcada${ids.length > 1 ? 's' : ''} como Hecho`, 'success');
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
        showToast(`Prioridad actualizada en ${ids.length} issue${ids.length > 1 ? 's' : ''}`, 'success');
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
        showToast(`Reasignadas ${ids.length} issue${ids.length > 1 ? 's' : ''}`, 'success');
        this.value = '';
        loadIssues(currentPage);
    });
}

// ── Issue templates ───────────────────────────────────────────────────────────
let issueTemplates = [];

async function loadTemplates() {
    if (!PROJECT_ID) return;
    const res  = await fetch(`${APP_URL}/app/api/templates.php?action=list&project_id=${PROJECT_ID}`);
    const data = await res.json();
    issueTemplates = data.data || [];
    const sel = document.getElementById('template-picker');
    if (!sel) return;
    // Clear and repopulate
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
        const descEl  = document.getElementById('new-desc');
        const typeEl  = document.getElementById('new-type');
        const prioEl  = document.getElementById('new-priority');
        if (titleEl && tpl.title)       titleEl.value = tpl.title;
        if (descEl  && tpl.description) descEl.value  = tpl.description;
        if (typeEl  && tpl.type_id)     typeEl.value  = tpl.type_id;
        if (prioEl  && tpl.priority)    prioEl.value  = tpl.priority;
        if (titleEl && tpl.title) {
            const slug = titleToSlug(tpl.title);
            const branchEl = document.getElementById('new-branch-name');
            if (branchEl) branchEl.value = slug ? `issue-${slug}` : '';
        }
    });
}

loadIssueTypes();
initFilterBar();
initBulkActions();
initDependencies();
loadTeamForMentions();
loadTemplates();
initTemplatePicker();
// Wire up both comment textareas (always in DOM as static HTML)
initMentionOnTextarea(document.getElementById('comment-input'));
initMentionOnTextarea(document.getElementById('fi-comment-input'));
loadIssues().then(() => {
    // Auto-open issue from URL param (e.g. from search navigation)
    const params = new URLSearchParams(window.location.search);
    const openId = parseInt(params.get('open_issue') || '0');
    if (openId) openFullIssue(openId);
});

// Keyboard shortcut: n → open new issue modal
document.addEventListener('keydown', e => {
    if (e.key !== 'n') return;
    const tag = document.activeElement ? document.activeElement.tagName : '';
    const editing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                 || (document.activeElement && document.activeElement.isContentEditable);
    if (editing) return;
    document.getElementById('new-issue-btn')?.click();
});

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

let testCasesCache = [];
let editingTestId  = null;

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

    return `<div style="padding:0.75rem 0;border-bottom:1px solid var(--border);">
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

let stepImagesMap  = {};   // uid → [dataUrl, ...]
let stepUidCounter = 0;

function buildStepRow(i, step, uid) {
    if (uid === undefined) uid = ++stepUidCounter;
    stepImagesMap[uid] = step.images ? [...step.images] : [];
    const thumbs = renderStepThumbsHtml(uid);
    return `<div class="tc-step-row flex gap-2 items-start mb-2" data-idx="${i}" data-uid="${uid}">
        <span class="text-xs text-muted flex-shrink-0" style="padding-top:0.5rem;width:1.2rem;text-align:right;">${i+1}.</span>
        <div class="flex-col gap-1 flex-1">
            <input type="text" class="form-input w-full form-input-sm tc-step-action" placeholder="Acción (qué hacer)" value="${escapeHtml(step.action||'')}">
            <input type="text" class="form-input w-full form-input-sm tc-step-expected" placeholder="Resultado esperado (opcional)" value="${escapeHtml(step.expected_result||'')}">
            <div id="step-imgs-${uid}" class="flex flex-wrap gap-1 mt-1">${thumbs}</div>
            <label class="btn btn-secondary btn-xs" style="cursor:pointer;margin-top:0.15rem;">
                &#128247; Añadir imagen
                <input type="file" accept="image/*" multiple style="display:none;" onchange="handleStepImages(this,${uid})">
            </label>
        </div>
        <button class="btn btn-danger btn-xs flex-shrink-0" style="margin-top:0.25rem;" onclick="removeStepRow(this,${uid})">&#10005;</button>
    </div>`;
}

function renderStepThumbsHtml(uid) {
    return (stepImagesMap[uid] || []).map((img, i) => `
        <div style="position:relative;width:48px;height:48px;flex-shrink:0;">
            <img src="${img}" style="width:48px;height:48px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border);cursor:zoom-in;" onclick="openEvidenceViewerUrl(this.src)">
            <button onclick="removeStepImg(${uid},${i})" style="position:absolute;top:-4px;right:-4px;width:16px;height:16px;background:#dc2626;color:#fff;border:none;border-radius:50%;font-size:10px;line-height:1;cursor:pointer;padding:0;">&#10005;</button>
        </div>`).join('');
}

function refreshStepThumbnailsByUid(uid) {
    const el = document.getElementById(`step-imgs-${uid}`);
    if (el) el.innerHTML = renderStepThumbsHtml(uid);
}

function removeStepImg(uid, idx) {
    if (stepImagesMap[uid]) { stepImagesMap[uid].splice(idx, 1); refreshStepThumbnailsByUid(uid); }
}

async function handleStepImages(input, uid) {
    const files = Array.from(input.files);
    input.value = '';
    if (!stepImagesMap[uid]) stepImagesMap[uid] = [];
    for (const file of files) {
        if (file.size > 10 * 1024 * 1024) { showToast(`"${file.name}" demasiado grande (máx 10MB)`, 'error'); continue; }
        try {
            const dataUrl = await resizeEvidenceImage(file, 1200);
            stepImagesMap[uid].push(dataUrl);
        } catch(e) { showToast('Error al procesar imagen', 'error'); }
    }
    refreshStepThumbnailsByUid(uid);
}

function refreshStepNumbers() {
    document.querySelectorAll('#tc-steps-list .tc-step-row').forEach((row, i) => {
        row.dataset.idx = i;
        row.querySelector('span').textContent = `${i+1}.`;
    });
}

function removeStepRow(btn, uid) {
    btn.closest('.tc-step-row').remove();
    delete stepImagesMap[uid];
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
    editingTestId  = tc ? tc.id : null;
    stepImagesMap  = {};
    stepUidCounter = 0;

    document.getElementById('test-case-modal-title').textContent = tc ? 'Editar Test Case' : 'Nuevo Test Case';
    document.getElementById('tc-title').value = tc ? tc.title : '';

    const sel = document.getElementById('tc-assignee');
    sel.innerHTML = '<option value="">Sin asignar</option>' +
        mentionTeamCache.map(m => `<option value="${m.id}"${tc && tc.assignee_id == m.id ? ' selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

    const stepsList = document.getElementById('tc-steps-list');
    const steps = tc ? (tc.steps || []) : [];
    stepsList.innerHTML = steps.length
        ? steps.map((s, i) => buildStepRow(i, s)).join('')
        : buildStepRow(0, {});

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
        const uid      = parseInt(row.dataset.uid);
        const action   = row.querySelector('.tc-step-action').value.trim();
        const expected = row.querySelector('.tc-step-expected').value.trim();
        if (action) steps.push({ action, expected_result: expected, images: stepImagesMap[uid] || [] });
    });

    const btn = document.getElementById('tc-save-btn');
    btn.disabled = true; btn.textContent = 'Guardando...';

    const body = { title, assignee_id: assignee ? parseInt(assignee) : null, steps };

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

// ══════════════════════════════════════════════════════════════════════════════
// EXECUTE TEST — step-by-step modal
// ══════════════════════════════════════════════════════════════════════════════

let execState = {
    testCaseId: null,
    steps: [],
    results: [],
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
        results: tc.steps.map(s => ({ step_id: s.id, result: '', comment: '', images: [] })),
        current: 0,
    };

    document.getElementById('exec-modal-title').textContent = escapeHtml(tc.title);
    renderExecStep();
    document.getElementById('test-execute-modal').classList.remove('hidden');
}

function renderExecStep() {
    const { steps, results, current } = execState;
    const step  = steps[current];
    const saved = results[current];
    const total = steps.length;
    const isLast = current === total - 1;

    document.getElementById('exec-modal-counter').textContent = `Paso ${current+1} de ${total}`;
    document.getElementById('exec-prev-btn').disabled = current === 0;
    document.getElementById('exec-next-btn').textContent = isLast ? '✓ Finalizar' : 'Siguiente →';

    document.getElementById('exec-step-content').innerHTML = `
        <div class="mb-3 p-3" style="background:var(--bg-secondary);border-radius:var(--radius-md);">
            <div class="text-label mb-1">Acción</div>
            <div style="font-size:0.9rem;">${escapeHtml(step.action)}</div>
            ${step.expected_result ? `<div class="text-label mt-2 mb-1">Resultado esperado</div><div style="font-size:0.875rem;color:var(--text-secondary);">${escapeHtml(step.expected_result)}</div>` : ''}
            ${step.images && step.images.length ? `<div class="text-label mt-2 mb-1">Imágenes de referencia</div><div class="flex flex-wrap gap-1">${step.images.map(img => `<img src="${img}" style="width:56px;height:56px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border);cursor:zoom-in;" onclick="openEvidenceViewerUrl(this.src)">`).join('')}</div>` : ''}
        </div>
        <div class="mb-3">
            <div class="text-label mb-2">Resultado</div>
            <div class="flex gap-2">
                <button class="btn flex-1 exec-result-btn ${saved.result==='pass'?'btn-primary':'btn-secondary'}" data-result="pass" onclick="setExecResult('pass')">✓ Pass</button>
                <button class="btn flex-1 exec-result-btn ${saved.result==='fail'?'btn-danger':'btn-secondary'}" data-result="fail" onclick="setExecResult('fail')">✗ Fail</button>
                <button class="btn flex-1 exec-result-btn btn-secondary ${saved.result==='skip'?'font-bold':''}" data-result="skip" onclick="setExecResult('skip')">— Skip</button>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Comentario (opcional)</label>
            <textarea id="exec-step-comment" class="form-textarea w-full" style="height:56px;" placeholder="Observaciones...">${escapeHtml(saved.comment||'')}</textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Evidencias (imágenes)</label>
            <div id="exec-evidence-list" class="flex flex-wrap gap-2 mb-2">${renderEvidenceThumbnailsHtml(saved.images||[])}</div>
            <label class="btn btn-secondary btn-sm" style="cursor:pointer;">
                &#128247; Añadir imagen
                <input type="file" accept="image/*" multiple style="display:none;" onchange="handleEvidenceFiles(this)">
            </label>
        </div>
    `;
}

function renderEvidenceThumbnailsHtml(images) {
    return images.map((img, i) => `
        <div style="position:relative;width:64px;height:64px;flex-shrink:0;">
            <img src="${img}" style="width:64px;height:64px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border);cursor:pointer;" onclick="openEvidenceViewer('${i}')">
            <button onclick="removeEvidence(${i})" style="position:absolute;top:-5px;right:-5px;width:18px;height:18px;background:#dc2626;color:#fff;border:none;border-radius:50%;font-size:11px;line-height:1;cursor:pointer;padding:0;">&#10005;</button>
        </div>`).join('');
}

function refreshEvidenceThumbnails() {
    const list = document.getElementById('exec-evidence-list');
    if (!list) return;
    list.innerHTML = renderEvidenceThumbnailsHtml(execState.results[execState.current].images || []);
}

function removeEvidence(i) {
    execState.results[execState.current].images.splice(i, 1);
    refreshEvidenceThumbnails();
}

function openEvidenceViewer(idx) {
    const images = execState.results[execState.current].images || [];
    const img = images[parseInt(idx)];
    if (!img) return;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
    overlay.innerHTML = `<img src="${img}" style="max-width:90vw;max-height:90vh;object-fit:contain;border-radius:var(--radius-md);">`;
    overlay.addEventListener('click', () => overlay.remove());
    document.body.appendChild(overlay);
}

function resizeEvidenceImage(file, maxSize) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('No se pudo leer el archivo'));
        reader.onload = e => {
            const img = new Image();
            img.onerror = () => reject(new Error('Formato de imagen inválido'));
            img.onload = () => {
                const canvas = document.createElement('canvas');
                const ratio  = Math.min(maxSize / img.width, maxSize / img.height, 1);
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

async function handleEvidenceFiles(input) {
    const files = Array.from(input.files);
    input.value = '';
    for (const file of files) {
        if (file.size > 10 * 1024 * 1024) { showToast(`"${file.name}" demasiado grande (máx 10MB)`, 'error'); continue; }
        try {
            const dataUrl = await resizeEvidenceImage(file, 1200);
            execState.results[execState.current].images.push(dataUrl);
        } catch(e) {
            showToast(`Error al procesar "${file.name}"`, 'error');
        }
    }
    refreshEvidenceThumbnails();
}

function setExecResult(result) {
    execState.results[execState.current].result = result;
    document.querySelectorAll('.exec-result-btn').forEach(btn => {
        const r = btn.dataset.result;
        btn.className = 'btn flex-1 exec-result-btn';
        if (r === result) {
            if (r === 'pass') btn.classList.add('btn-primary');
            else if (r === 'fail') btn.classList.add('btn-danger');
            else { btn.classList.add('btn-secondary'); btn.style.fontWeight = '700'; }
        } else {
            btn.classList.add('btn-secondary');
            btn.style.fontWeight = '';
        }
    });
}

function saveCurrentExecStep() {
    const comment = document.getElementById('exec-step-comment')?.value || '';
    execState.results[execState.current].comment = comment;
}

document.getElementById('exec-prev-btn').addEventListener('click', () => {
    saveCurrentExecStep();
    if (execState.current > 0) { execState.current--; renderExecStep(); }
});

document.getElementById('exec-next-btn').addEventListener('click', async () => {
    saveCurrentExecStep();
    const cur = execState.results[execState.current];
    if (!cur.result) { showToast('Marca el resultado de este paso antes de continuar', 'error'); return; }

    const isLast = execState.current === execState.steps.length - 1;
    if (!isLast) { execState.current++; renderExecStep(); return; }

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
        btn.textContent = '✓ Finalizar';
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

    const res   = await fetch(`${APP_URL}/app/api/tests.php?action=execution_detail&execution_id=${execId}`);
    const data  = await res.json();
    const steps = data.data || [];

    const resultIcons  = { pass:'✓', fail:'✗', skip:'—' };
    const resultColors = { pass:'#16a34a', fail:'#dc2626', skip:'#9ca3af' };

    document.getElementById('detail-modal-steps').innerHTML = steps.length
        ? steps.map((s, i) => {
            const evidenceHtml = s.evidence && s.evidence.length
                ? `<div class="flex flex-wrap gap-2 mt-2">${s.evidence.map(ev =>
                    `<img src="${ev.image}" style="width:64px;height:64px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border);cursor:zoom-in;" onclick="openEvidenceViewerUrl(this.src)">`
                ).join('')}</div>`
                : '';
            return `
            <div class="mb-3 p-3" style="border:1px solid var(--border);border-radius:var(--radius-md);">
                <div class="flex items-center gap-2 mb-1">
                    <span style="font-size:1rem;font-weight:700;color:${resultColors[s.result]||'#9ca3af'};">${resultIcons[s.result]||'—'}</span>
                    <span class="text-label">Paso ${i+1}</span>
                </div>
                <div style="font-size:0.875rem;margin-bottom:0.25rem;">${escapeHtml(s.action)}</div>
                ${s.expected_result ? `<div class="text-xs text-muted mb-1">Esperado: ${escapeHtml(s.expected_result)}</div>` : ''}
                ${s.comment ? `<div class="text-xs" style="color:var(--text-secondary);font-style:italic;">Comentario: ${escapeHtml(s.comment)}</div>` : ''}
                ${evidenceHtml}
            </div>`;
        }).join('')
        : '<div class="empty-state">Sin pasos en esta ejecución</div>';
}

document.getElementById('detail-modal-close').addEventListener('click', () => {
    document.getElementById('test-detail-modal').classList.add('hidden');
});

function openEvidenceViewerUrl(src) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
    overlay.innerHTML = `<img src="${escapeHtml(src)}" style="max-width:90vw;max-height:90vh;object-fit:contain;border-radius:var(--radius-md);">`;
    overlay.addEventListener('click', () => overlay.remove());
    document.body.appendChild(overlay);
}
