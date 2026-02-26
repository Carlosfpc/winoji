function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

const STATUS_COLOR  = { todo:'#6b7280', in_progress:'#2563eb', review:'#d97706', done:'#16a34a' };
const STATUS_LABEL  = { todo:'Pendiente', in_progress:'En curso', review:'Revisión', done:'Hecho' };
const PRIORITY_LABEL = { low:'Baja', medium:'Media', high:'Alta', critical:'Crítica' };
const PRIORITY_COLOR = { high:'#dc2626', critical:'#7c3aed', medium:'#d97706', low:'#16a34a' };

async function loadDashboard() {
    const projectId = parseInt(localStorage.getItem('active_project_id') || '0');
    if (!projectId) {
        document.querySelector('.main-content').innerHTML = '<div style="padding:2rem;color:var(--text-tertiary);">Selecciona un proyecto en el menú lateral.</div>';
        return;
    }
    let res, data;
    try {
        res = await fetch(`${APP_URL}/app/api/dashboard.php?project_id=${projectId}`);
        data = await res.json();
    } catch (e) {
        showToast('Error al cargar el dashboard', 'error');
        return;
    }
    if (!data.success) {
        showToast(data.error || 'Error al cargar el dashboard', 'error');
        return;
    }
    const d = data.data;

    // Project name in header
    const projSel = document.getElementById('project-select');
    const projName = projSel?.options[projSel?.selectedIndex]?.text || '';
    const nameEl = document.getElementById('dash-project-name');
    if (nameEl && projName) nameEl.textContent = projName;

    // Stat cards
    ['todo','in_progress','review','done'].forEach(s => {
        const el = document.getElementById(`stat-${s}`);
        if (el) el.textContent = d.stats[s] || 0;
    });
    document.getElementById('stat-prs').textContent     = d.open_prs.length;
    document.getElementById('stat-wiki').textContent    = d.wiki_count;
    document.getElementById('stat-members').textContent = d.members_count;
    const ptEl = document.getElementById('stat-points');
    if (ptEl) ptEl.textContent = d.story_points_total || 0;

    // Priority bar
    const pBar = document.getElementById('priority-bar');
    const totalOpen = (d.stats.todo||0) + (d.stats.in_progress||0) + (d.stats.review||0);
    if (!totalOpen) {
        pBar.innerHTML = '<span style="color:#16a34a;font-size:0.875rem;">&#10003; Todo cerrado</span>';
    } else {
        let barHtml = '';
        ['high','medium','low'].forEach(p => {
            const cnt = d.by_priority[p] || 0;
            if (!cnt) return;
            const pct = Math.round(cnt / totalOpen * 100);
            barHtml += `
                <div style="display:flex;align-items:center;gap:0.3rem;">
                    <div style="width:${Math.max(pct,4)}px;height:10px;border-radius:999px;background:${PRIORITY_COLOR[p]};min-width:10px;"></div>
                    <span style="font-size:0.8rem;color:${PRIORITY_COLOR[p]};font-weight:600;">${cnt} ${PRIORITY_LABEL[p]||p}</span>
                </div>`;
        });
        barHtml += `<span style="font-size:0.75rem;color:var(--text-tertiary);margin-left:0.25rem;">(${totalOpen} total abiertas)</span>`;
        pBar.innerHTML = barHtml;
    }

    // My issues
    const myEl = document.getElementById('my-issues');
    if (!d.my_issues.length) {
        myEl.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin issues asignadas &#10003;</em>';
    } else {
        myEl.innerHTML = d.my_issues.map(i => `
            <div style="padding:0.4rem 0;border-bottom:1px solid var(--border);">
                <div style="font-size:0.875rem;font-weight:500;">#${i.id} ${escapeHtml(i.title)}</div>
                <div style="display:flex;gap:0.3rem;margin-top:0.2rem;">
                    <span class="badge badge-${i.priority}" style="font-size:0.7rem;">${i.priority}</span>
                    <span class="badge" style="background:#e5e7eb;color:#374151;font-size:0.7rem;">${STATUS_LABEL[i.status]||i.status}</span>
                </div>
            </div>`).join('');
    }

    // Team workload
    const teamEl = document.getElementById('team-workload');
    if (!d.team_workload.length) {
        teamEl.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin datos</em>';
    } else {
        const maxCount = Math.max(...d.team_workload.map(m => m.open_count), 1);
        teamEl.innerHTML = d.team_workload.map(m => {
            const pct = Math.round((m.open_count / maxCount) * 100);
            const barColor = m.open_count === 0 ? '#d1fae5' : m.open_count >= 5 ? '#fca5a5' : '#bfdbfe';
            return `<div style="margin-bottom:0.6rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:0.2rem;">
                    <span style="font-weight:500;">${escapeHtml(m.name)}</span>
                    <span style="color:var(--text-secondary);">${m.open_count} open${m.high_count > 0 ? ` · <span style="color:#dc2626;">${m.high_count} high</span>` : ''}</span>
                </div>
                <div style="height:6px;background:var(--bg-secondary);border-radius:999px;overflow:hidden;">
                    <div style="height:100%;width:${pct||2}%;background:${barColor};border-radius:999px;"></div>
                </div>
            </div>`;
        }).join('');
    }

    // Open PRs
    const prEl = document.getElementById('open-prs');
    if (!d.open_prs.length) {
        prEl.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin pull requests</em>';
    } else {
        prEl.innerHTML = d.open_prs.map(pr => `
            <div style="padding:0.4rem 0;border-bottom:1px solid var(--border);">
                <div style="font-size:0.8rem;font-weight:500;color:var(--color-primary);font-family:monospace;">${escapeHtml(pr.branch_name)}</div>
                <div style="font-size:0.8rem;color:#374151;">&#128027; #${pr.issue_id} ${escapeHtml(pr.issue_title)}</div>
                <div style="font-size:0.75rem;color:var(--text-tertiary);">by ${escapeHtml(pr.creator_name)}
                    ${pr.pr_url ? `· <a href="${escapeHtml(pr.pr_url)}" target="_blank" style="color:var(--color-primary);">PR #${pr.pr_number} ↗</a>` : ''}
                </div>
            </div>`).join('');
    }

    // Activity feed (from activity_log table)
    const feedEl = document.getElementById('activity-feed');
    if (feedEl) {
        const ACTIVITY_ICONS = {
            issue_created: '✨', issue_updated: '✏️', issue_deleted: '🗑️',
            comment_added: '💬', page_created: '📄', page_updated: '📝'
        };
        if (!d.activity?.length) {
            feedEl.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin actividad reciente</em>';
        } else {
            feedEl.innerHTML = d.activity.map(a =>
                `<div style="display:flex;gap:0.5rem;padding:0.35rem 0;border-bottom:1px solid var(--border);align-items:flex-start;">
                    <span style="font-size:1rem;flex-shrink:0;">${ACTIVITY_ICONS[a.action] || '•'}</span>
                    <div style="flex:1;min-width:0;">
                        <span style="font-size:0.875rem;font-weight:500;">${escapeHtml(a.user_name)}</span>
                        <span style="font-size:0.8rem;color:var(--text-secondary);"> ${escapeHtml(a.action.replace(/_/g,' '))} </span>
                        <span style="font-size:0.875rem;">${escapeHtml(a.entity_title || '#' + a.entity_id)}</span>
                    </div>
                    <span style="font-size:0.75rem;color:var(--text-tertiary);flex-shrink:0;" title="${escapeHtml(new Date(a.created_at).toLocaleString())}">${timeAgo(a.created_at)}</span>
                </div>`
            ).join('');
        }
    }

    // Recent issues
    const recEl = document.getElementById('recent-issues');
    if (!d.recent_issues.length) {
        recEl.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin actividad</em>';
    } else {
        recEl.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:0.5rem;">` +
            d.recent_issues.map(i => {
                const color = STATUS_COLOR[i.status] || '#6b7280';
                return `<div style="padding:0.5rem 0.75rem;border:1px solid #e5e7eb;border-radius:6px;border-left:3px solid ${color};">
                    <div style="font-size:0.875rem;font-weight:500;">#${i.id} ${escapeHtml(i.title)}</div>
                    <div style="font-size:0.75rem;color:var(--text-tertiary);margin-top:0.2rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                        <span style="color:${color};">${STATUS_LABEL[i.status]||i.status}</span>
                        <span class="badge badge-${i.priority}" style="font-size:0.65rem;">${i.priority}</span>
                        ${i.assignee_name ? `<span>&#128100; ${escapeHtml(i.assignee_name)}</span>` : '<span style="color:var(--text-tertiary);">sin asignar</span>'}
                        <span title="${new Date(i.created_at).toLocaleString()}">${timeAgo(i.created_at)}</span>
                    </div>
                </div>`;
            }).join('') + '</div>';
    }

    // Burndown chart (independent fetch)
    loadBurndown(projectId);
}

loadDashboard();

(function() {
    var btn = document.getElementById('dash-refresh-btn');
    if (!btn) return;
    btn.addEventListener('click', function() {
        btn.disabled = true;
        btn.textContent = 'Actualizando...';
        loadDashboard().finally(function() {
            btn.disabled = false;
            btn.innerHTML = '&#8635; Actualizar';
        });
    });
})();

async function loadBurndown(projectId) {
    const container = document.getElementById('burndown-chart');
    if (!container) return;
    let res, data;
    try {
        res  = await fetch(`${APP_URL}/app/api/dashboard.php?action=burndown&project_id=${projectId}`);
        data = await res.json();
    } catch(e) {
        container.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Error al cargar</em>';
        return;
    }
    if (!data.success) {
        container.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin datos</em>';
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
        bars += `<rect x="${x}" y="${y}" width="${bW}" height="${bH}" fill="var(--color-primary)" rx="2" opacity="0.85"/>`;
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
