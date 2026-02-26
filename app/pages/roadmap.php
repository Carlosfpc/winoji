<?php
$page_title = 'Roadmap';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
    <h2>Roadmap</h2>
    <div class="rm-cal-nav">
        <button id="rm-prev">&#8592;</button>
        <span class="rm-cal-month" id="rm-month-label"></span>
        <button id="rm-next">&#8594;</button>
        <button id="rm-today">Hoy</button>
    </div>
</div>
<div id="roadmap-container">
    <div class="empty-state">Cargando...</div>
</div>

<script>
(async function() {
    const pid = parseInt(localStorage.getItem('active_project_id') || '0');
    const container = document.getElementById('roadmap-container');

    if (!pid) {
        container.innerHTML = '<div class="empty-state">Selecciona un proyecto para ver el roadmap.</div>';
        return;
    }

    let allIssues = [];
    try {
        const res = await fetch(`${APP_URL}/app/api/issues.php?action=list&project_id=${pid}&per_page=500`);
        const data = await res.json();
        allIssues = data.items || [];
    } catch(e) {
        container.innerHTML = '<div class="empty-state text-danger">Error al cargar issues.</div>';
        return;
    }

    const PRIO_COLOR = { critical:'#dc2626', high:'#ea580c', medium:'#d97706', low:'#16a34a' };
    const today = new Date(); today.setHours(0,0,0,0);
    const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;

    let viewYear  = today.getFullYear();
    let viewMonth = today.getMonth();

    function escapeHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function renderCalendar() {
        const firstDay = new Date(viewYear, viewMonth, 1);
        const lastDay  = new Date(viewYear, viewMonth + 1, 0);
        const monthLabel = firstDay.toLocaleDateString('es', { month:'long', year:'numeric' });
        document.getElementById('rm-month-label').textContent =
            monthLabel.charAt(0).toUpperCase() + monthLabel.slice(1);

        const monthStr = `${viewYear}-${String(viewMonth+1).padStart(2,'0')}`;
        const byDay    = {};
        allIssues.forEach(i => {
            const dateKey = i.due_date || (i.status === 'done' && i.completed_at ? i.completed_at : null);
            if (!dateKey) return;
            if (!dateKey.startsWith(monthStr)) return;
            const d = dateKey.slice(8,10);
            if (!byDay[d]) byDay[d] = [];
            byDay[d].push(i);
        });

        const DAYS = ['L','M','X','J','V','S','D'];
        let html = '<div class="rm-cal-grid">';
        DAYS.forEach(d => { html += `<div class="rm-cal-dow">${d}</div>`; });

        let startOffset = (firstDay.getDay() + 6) % 7;
        for (let i = 0; i < startOffset; i++) {
            html += '<div class="rm-cal-day other-month"></div>';
        }

        for (let d = 1; d <= lastDay.getDate(); d++) {
            const ds      = `${monthStr}-${String(d).padStart(2,'0')}`;
            const isToday = ds === todayStr;
            html += `<div class="rm-cal-day${isToday ? ' today' : ''}">`;
            html += `<div class="rm-day-num">${d}</div>`;
            const dayIssues = byDay[String(d).padStart(2,'0')] || [];
            const visible   = dayIssues.slice(0, 3);
            const overflow  = dayIssues.length - visible.length;
            visible.forEach(i => {
                if (i.status === 'done') {
                    html += `<a class="rm-pill rm-pill-done" href="${APP_URL}?page=issues&open_issue=${i.id}"
                        title="${escapeHtml(i.title)}">✓ ${escapeHtml(i.title)}</a>`;
                } else {
                    const color   = PRIO_COLOR[i.priority] || '#6b7280';
                    const overdue = ds < todayStr ? ' overdue' : '';
                    html += `<a class="rm-pill${overdue}" href="${APP_URL}?page=issues&open_issue=${i.id}"
                        style="background:${color};" title="${escapeHtml(i.title)}">${escapeHtml(i.title)}</a>`;
                }
            });
            if (overflow > 0) {
                html += `<div class="rm-overflow">+${overflow} más</div>`;
            }
            html += '</div>';
        }

        const total = startOffset + lastDay.getDate();
        const trail = (7 - (total % 7)) % 7;
        for (let i = 0; i < trail; i++) {
            html += '<div class="rm-cal-day other-month"></div>';
        }
        html += '</div>';

        const noDates = allIssues.filter(i => !i.due_date && i.status !== 'done');
        if (noDates.length) {
            html += `<div class="mt-6">
                <h4 class="section-title mb-3 text-tertiary">Sin fecha límite (${noDates.length})</h4>
                <div class="flex flex-wrap gap-2">`;
            noDates.forEach(i => {
                html += `<a class="roadmap-card-small" href="${APP_URL}?page=issues&open_issue=${i.id}">#${i.id} ${escapeHtml(i.title)}</a>`;
            });
            html += '</div></div>';
        }

        const doneIssues = allIssues.filter(i => i.status === 'done');
        if (doneIssues.length) {
            html += `<div class="mt-6">
                <h4 class="section-title mb-3" style="color:#16a34a;">✓ Completadas (${doneIssues.length})</h4>
                <div class="flex flex-wrap gap-2">`;
            doneIssues.forEach(i => {
                html += `<a class="roadmap-card-small roadmap-card-done" href="${APP_URL}?page=issues&open_issue=${i.id}">#${i.id} ${escapeHtml(i.title)}</a>`;
            });
            html += '</div></div>';
        }

        container.innerHTML = html;
    }

    document.getElementById('rm-prev').addEventListener('click', () => {
        viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; }
        renderCalendar();
    });
    document.getElementById('rm-next').addEventListener('click', () => {
        viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; }
        renderCalendar();
    });
    document.getElementById('rm-today').addEventListener('click', () => {
        viewYear = today.getFullYear(); viewMonth = today.getMonth();
        renderCalendar();
    });

    renderCalendar();
})();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
