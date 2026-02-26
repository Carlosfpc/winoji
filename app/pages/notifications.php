<?php
$page_title = 'Notificaciones';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
    <h2>Notificaciones</h2>
    <button id="mark-all-btn" class="btn btn-secondary btn-sm">
        &#10003; Marcar todas como leídas
    </button>
</div>

<div class="flex gap-2 mb-4">
    <button class="btn notif-filter-btn active" data-filter="all">Todas</button>
    <button class="btn notif-filter-btn" data-filter="unread">No leídas</button>
</div>

<div id="notif-page-list" class="card card-flush">
    <div class="empty-state">Cargando...</div>
</div>

<script>
const NOTIF_ICONS = {
    issue_created:'✨', issue_updated:'✏️', issue_assigned:'👤',
    comment_added:'💬', page_created:'📄', page_updated:'📝', mention:'🔔'
};
const NOTIF_LABELS = {
    issue_created:'creó una issue', issue_updated:'actualizó una issue',
    issue_assigned:'te asignó', comment_added:'comentó en',
    page_created:'creó la página', page_updated:'editó la página', mention:'te mencionó en'
};
const NOTIF_URLS = {
    issue: id => `${APP_URL}?page=issues&open_issue=${id}`,
    comment: id => `${APP_URL}?page=issues`,
    page: id => `${APP_URL}?page=wiki&open_page=${id}`
};

let allNotifs     = [];
let currentFilter = 'all';
let currentPage   = 1;
let totalNotifs   = 0;
const PER_PAGE    = 20;

function escapeHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

async function loadNotifications(page = 1) {
    currentPage = page;
    const unreadParam = currentFilter === 'unread' ? '&unread=1' : '';
    const res   = await fetch(`${APP_URL}/app/api/notifications.php?action=list&page=${page}&per_page=${PER_PAGE}${unreadParam}`);
    const data  = await res.json();
    allNotifs   = data.data  || [];
    totalNotifs = data.total || 0;
    render();
}

function render() {
    const items = allNotifs;
    const list  = document.getElementById('notif-page-list');

    document.querySelectorAll('.notif-filter-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.filter === currentFilter);
    });

    if (!items.length) {
        list.innerHTML = '<div class="empty-state">Sin notificaciones</div>';
        renderPagination();
        return;
    }

    list.innerHTML = items.map(n => {
        const icon     = NOTIF_ICONS[n.type]  || '•';
        const label    = NOTIF_LABELS[n.type] || n.type.replace(/_/g, ' ');
        const url      = (NOTIF_URLS[n.entity_type] || (() => APP_URL))(n.entity_id);
        const unreadCls = !n.read_at ? ' notif-row-unread' : '';
        const timeStr  = typeof timeAgo === 'function' ? timeAgo(n.created_at) : new Date(n.created_at).toLocaleString('es');
        return `<a class="list-row-link${unreadCls}" href="${escapeHtml(url)}"
            data-id="${n.id}" data-read="${n.read_at ? '1' : '0'}">
            <span class="notif-item-icon">${escapeHtml(icon)}</span>
            <div class="flex-1 min-w-0">
                <div class="notif-item-text text-base">
                    <strong>${escapeHtml(n.actor_name)}</strong> ${escapeHtml(label)}
                    <em>${escapeHtml(n.entity_title || '#' + n.entity_id)}</em>
                </div>
                <div class="notif-item-time">${timeStr}</div>
            </div>
            ${!n.read_at ? '<span class="notif-unread-dot"></span>' : ''}
        </a>`;
    }).join('');

    list.querySelectorAll('.list-row-link[data-read="0"]').forEach(el => {
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
        pager.className = 'pagination';
        document.getElementById('notif-page-list').after(pager);
    }
    if (totalPages <= 1) { pager.classList.add('hidden'); return; }
    pager.classList.remove('hidden');
    pager.innerHTML = `
        <button class="btn btn-secondary btn-sm" ${currentPage <= 1 ? 'disabled style="opacity:0.4;"' : ''}
            onclick="loadNotifications(${currentPage - 1})">&#8592; Anterior</button>
        <span class="text-sm text-muted">Página ${currentPage} de ${totalPages} · ${totalNotifs} total</span>
        <button class="btn btn-secondary btn-sm" ${currentPage >= totalPages ? 'disabled style="opacity:0.4;"' : ''}
            onclick="loadNotifications(${currentPage + 1})">Siguiente &#8594;</button>`;
}

document.getElementById('mark-all-btn').addEventListener('click', async () => {
    await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_all_read`, {});
    await loadNotifications(1);
});

document.querySelectorAll('.notif-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        currentFilter = btn.dataset.filter;
        loadNotifications(1);
    });
});

loadNotifications();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
