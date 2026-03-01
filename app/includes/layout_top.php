<?php require_auth(); ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($page_title ?? 'WINOJI') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/app/assets/css/main.css">
    <script>
        // Apply saved theme immediately before render to avoid flash
        (function () {
            const t = localStorage.getItem('theme');
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <?= $extra_head ?? '' ?>
    <script src="<?= APP_URL ?>/app/assets/js/utils.js" defer></script>
</head>

<body>
    <!-- Toast container -->
    <div id="toast-container"></div>

    <!-- Notifications panel -->
    <div id="notif-panel" class="notif-panel hidden"></div>

    <!-- Confirm modal -->
    <div id="confirm-modal" class="modal hidden">
        <div class="modal-box" style="max-width:420px;">
            <p id="confirm-message" class="mb-4"></p>
            <div id="confirm-word-check" class="confirm-word-box" style="display:none;">
                <p class="text-sm text-muted mb-2">Escribe <strong class="text-danger font-mono">ELIMINAR</strong> para
                    confirmar:</p>
                <input type="text" id="confirm-word-input" placeholder="ELIMINAR" autocomplete="off"
                    class="form-input font-mono">
            </div>
            <div class="flex flex-end gap-2">
                <button class="btn btn-secondary" id="confirm-no">Cancelar</button>
                <button class="btn btn-danger" id="confirm-yes">Confirmar</button>
            </div>
        </div>
    </div>

    <?php
    $cu = current_user();
    $av = $cu['avatar'] ?? '';
    $initial = htmlspecialchars(mb_strtoupper(mb_substr($cu['name'], 0, 1)));
    ?>

    <div id="sidebar-overlay" class="sidebar-overlay"></div>
    <div class="app-root">
        <nav class="sidebar" id="main-sidebar">
            <div class="sidebar-logo flex flex-between items-center">
                <span>WINOJI</span>
            </div>
            <div class="project-switcher">
                <select id="project-select" class="form-select mb-1">
                    <option value="">Cargando proyectos...</option>
                </select>
            </div>
            <ul>
                <li><a href="<?= APP_URL ?>?page=dashboard">Dashboard</a></li>
                <li><a href="<?= APP_URL ?>?page=wiki">Wiki</a></li>
                <li><a href="<?= APP_URL ?>?page=issues">Issues</a></li>
                <li><a href="<?= APP_URL ?>?page=kanban">Kanban</a></li>
                <li><a href="<?= APP_URL ?>?page=sprint">Sprint</a></li>
                <li><a href="<?= APP_URL ?>?page=team">Equipo</a></li>
                <li><a href="<?= APP_URL ?>?page=project">Proyecto</a></li>
                <li><a href="<?= APP_URL ?>?page=roadmap">Roadmap</a></li>
                <li><a href="<?= APP_URL ?>?page=sonar">Sonar</a></li>
            </ul>
        </nav>
        <div class="main-column">
            <!-- Top header bar -->
            <header class="app-header">
                <button id="sidebar-toggle" class="btn-sidebar-toggle flex-shrink-0"
                    style="display:none;">&#9776;</button>
                <div class="app-header-search">
                    <input type="text" id="search-input" placeholder="Buscar..." autocomplete="off">
                    <div id="search-results" class="search-results hidden"></div>
                </div>
                <div class="app-header-right">
                    <!-- Dark mode toggle -->
                    <button id="theme-toggle" title="Cambiar tema">&#9790;</button>
                    <!-- Notification bell -->
                    <button id="notif-bell" title="Notificaciones">
                        &#128276;
                        <span id="notif-badge" class="notif-badge hidden">0</span>
                    </button>
                    <!-- User dropdown -->
                    <div class="header-user" id="header-user-btn">
                        <?php if ($av): ?>
                            <img src="<?= htmlspecialchars($av) ?>" class="avatar avatar-sm flex-shrink-0" alt="">
                        <?php else: ?>
                            <span class="avatar avatar-sm flex-shrink-0"><?= $initial ?></span>
                        <?php endif; ?>
                        <span class="header-user-name"><?= htmlspecialchars($cu['name']) ?></span>
                        <span class="text-xs text-muted">&#9660;</span>
                    </div>
                    <div class="header-user-dropdown hidden" id="header-user-dropdown">
                        <a href="<?= APP_URL ?>?page=profile">&#128100; Ver perfil</a>
                        <button id="logout-btn">Cerrar sesión</button>
                    </div>
                </div>
            </header>
            <main class="main-content">

                <script>
                    const APP_URL = '<?= APP_URL ?>';
                    const CURRENT_PAGE = '<?= htmlspecialchars($_GET['page'] ?? 'dashboard') ?>';
                    (async function initProjectSwitcher() {
                        const sel = document.getElementById('project-select');
                        if (!sel) return;
                        const res = await fetch(`${APP_URL}/app/api/projects.php?action=list`);
                        const data = await res.json();
                        const projects = data.data || [];
                        if (!projects.length) {
                            sel.innerHTML = '<option value="">Sin proyectos</option>';
                            sel.disabled = true;
                        } else {
                            sel.innerHTML = projects.map(p => `<option value="${p.id}">${p.name.replace(/</g, '&lt;')}</option>`).join('');
                            sel.disabled = false;
                        }
                        // Restore from localStorage
                        const saved = localStorage.getItem('active_project_id');
                        if (saved && projects.find(p => String(p.id) === saved)) {
                            sel.value = saved;
                        } else if (projects.length) {
                            localStorage.setItem('active_project_id', projects[0].id);
                            sel.value = projects[0].id;
                        }
                        sel.addEventListener('change', () => {
                            localStorage.setItem('active_project_id', sel.value);
                            window.location.reload();
                        });
                    })();
                </script>
                <script>
                    (function () {
                        const input = document.getElementById('search-input');
                        const results = document.getElementById('search-results');
                        if (!input || !results) return;
                        let timer;

                        function highlightQuery(text, query) {
                            if (!query) return escapeHtml(text);
                            const escaped = escapeHtml(text);
                            const escapedQ = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                            return escaped.replace(new RegExp('(' + escapedQ + ')', 'gi'),
                                '<mark style="background:#fef08a;color:#713f12;border-radius:2px;padding:0 1px;">$1</mark>');
                        }

                        input.addEventListener('input', () => {
                            clearTimeout(timer);
                            const q = input.value.trim();
                            if (q.length < 2) { results.classList.add('hidden'); return; }
                            timer = setTimeout(async () => {
                                const pid = localStorage.getItem('active_project_id') || 0;
                                const res = await fetch(`${APP_URL}/app/api/search.php?q=${encodeURIComponent(q)}&project_id=${pid}`);
                                const data = await res.json();
                                if (!data.results || !data.results.length) {
                                    results.innerHTML = '<div class="search-empty">Sin resultados</div>';
                                } else {
                                    results.innerHTML = data.results.map(r => {
                                        const href = r.type === 'issue'
                                            ? `${APP_URL}?page=issues&open_issue=${r.id}`
                                            : `${APP_URL}?page=wiki&open_page=${r.id}`;
                                        const icon = r.type === 'issue' ? '&#128027;' : '&#128196;';
                                        return `<a class="search-result-item" href="${href}" data-id="${r.id}" data-type="${r.type}">${icon} ${highlightQuery(r.title, q)}</a>`;
                                    }).join('');
                                }
                                results.classList.remove('hidden');
                            }, 300);
                        });
                        // Keyboard navigation: ArrowDown/Up to move between results, Enter to follow link
                        input.addEventListener('keydown', e => {
                            const items = results.querySelectorAll('.search-result-item');
                            if (!items.length || results.classList.contains('hidden')) return;
                            const active = results.querySelector('.search-result-item.search-active');
                            let idx = Array.from(items).indexOf(active);
                            if (e.key === 'ArrowDown') {
                                e.preventDefault();
                                if (active) active.classList.remove('search-active');
                                items[Math.min(idx + 1, items.length - 1)].classList.add('search-active');
                            } else if (e.key === 'ArrowUp') {
                                e.preventDefault();
                                if (active) active.classList.remove('search-active');
                                items[Math.max(idx - 1, 0)].classList.add('search-active');
                            } else if (e.key === 'Enter') {
                                const current = results.querySelector('.search-result-item.search-active');
                                if (current) { e.preventDefault(); window.location.href = current.href; }
                            } else if (e.key === 'Escape') {
                                results.classList.add('hidden');
                            }
                        });

                        document.addEventListener('click', e => {
                            if (!results.contains(e.target) && e.target !== input) {
                                results.classList.add('hidden');
                            }
                        });
                    })();
                </script>
                <script>
                    (function () {
                        document.querySelectorAll('.sidebar ul li a:not([data-no-nav])').forEach(function (a) {
                            try {
                                const url = new URL(a.href, location.origin);
                                const page = url.searchParams.get('page') || 'dashboard';
                                if (page === CURRENT_PAGE) {
                                    a.classList.add('active');
                                }
                            } catch (e) { }
                        });
                    })();
                </script>
                <script>
                    // Sidebar toggle for mobile
                    (function () {
                        const toggle = document.getElementById('sidebar-toggle');
                        const sidebar = document.getElementById('main-sidebar');
                        const overlay = document.getElementById('sidebar-overlay');
                        if (!toggle || !sidebar || !overlay) return;
                        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('visible'); }
                        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('visible'); }
                        toggle.addEventListener('click', openSidebar);
                        overlay.addEventListener('click', closeSidebar);
                        // Close on nav link click (mobile UX)
                        sidebar.querySelectorAll('ul li a').forEach(a => a.addEventListener('click', closeSidebar));
                    })();
                </script>
                <script>
                    // Dark mode toggle
                    (function () {
                        const btn = document.getElementById('theme-toggle');
                        if (!btn) return;
                        function updateBtn() {
                            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                            btn.innerHTML = isDark ? '&#9728;' : '&#9790;';
                            btn.title = isDark ? 'Modo claro' : 'Modo oscuro';
                        }
                        updateBtn();
                        btn.addEventListener('click', function () {
                            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                            if (isDark) {
                                document.documentElement.removeAttribute('data-theme');
                                localStorage.setItem('theme', 'light');
                            } else {
                                document.documentElement.setAttribute('data-theme', 'dark');
                                localStorage.setItem('theme', 'dark');
                            }
                            updateBtn();
                        });
                    })();
                </script>

                <script>
                    // Notifications bell
                    (function () {
                        const NOTIF_ICONS = {
                            issue_created: '✨', issue_updated: '✏️', issue_assigned: '👤',
                            comment_added: '💬', page_created: '📄', page_updated: '📝', mention: '🔔'
                        };
                        const NOTIF_LABELS = {
                            issue_created: 'creó una issue', issue_updated: 'actualizó una issue',
                            issue_assigned: 'te asignó una issue', comment_added: 'comentó en',
                            page_created: 'creó una página', page_updated: 'editó una página', mention: 'te mencionó en'
                        };
                        const NOTIF_URLS = {
                            issue: id => `${APP_URL}?page=issues&open_issue=${id}`,
                            comment: id => `${APP_URL}?page=issues`,
                            page: id => `${APP_URL}?page=wiki&open_page=${id}`
                        };

                        let cachedCount = -1;
                        const bell = document.getElementById('notif-bell');
                        const badge = document.getElementById('notif-badge');
                        const panel = document.getElementById('notif-panel');
                        if (!bell || !badge || !panel) return;

                        function escH(s) {
                            if (!s) return '';
                            const d = document.createElement('div');
                            d.textContent = s;
                            return d.innerHTML;
                        }

                        async function fetchCount() {
                            try {
                                const res = await fetch(`${APP_URL}/app/api/notifications.php?action=unread_count`);
                                const data = await res.json();
                                const n = data.count || 0;
                                if (n !== cachedCount) {
                                    cachedCount = n;
                                    badge.textContent = n > 99 ? '99+' : n;
                                    badge.classList.toggle('hidden', n === 0);
                                    if (!panel.classList.contains('hidden')) renderPanel();
                                }
                            } catch (e) { }
                        }

                        async function renderPanel() {
                            panel.innerHTML = '<div class="empty-state text-sm">Cargando...</div>';
                            try {
                                const res = await fetch(`${APP_URL}/app/api/notifications.php?action=list`);
                                const data = await res.json();
                                const items = (data.data || []).slice(0, 20);

                                let html = `<div class="notif-panel-header">
                <span>Notificaciones</span>
                <button id="notif-mark-all" class="btn-link text-xs text-primary-color">Marcar todas como leídas</button>
            </div>`;

                                if (!items.length) {
                                    html += '<div class="empty-state">Sin notificaciones</div>';
                                } else {
                                    items.forEach(n => {
                                        const icon = NOTIF_ICONS[n.type] || '•';
                                        const label = NOTIF_LABELS[n.type] || n.type.replace(/_/g, ' ');
                                        const url = (NOTIF_URLS[n.entity_type] || (() => APP_URL))(n.entity_id);
                                        const unread = !n.read_at ? 'unread' : '';
                                        const timeStr = typeof timeAgo === 'function' ? timeAgo(n.created_at) : n.created_at.slice(0, 10);
                                        html += `<a class="notif-item ${unread}" href="${escH(url)}" data-id="${n.id}" data-read="${n.read_at ? '1' : '0'}">
                        <span class="notif-item-icon">${icon}</span>
                        <div class="notif-item-body">
                            <div class="notif-item-text"><strong>${escH(n.actor_name)}</strong> ${escH(label)} <em>${escH(n.entity_title || '#' + n.entity_id)}</em></div>
                            <div class="notif-item-time">${timeStr}</div>
                        </div>
                    </a>`;
                                    });
                                }

                                html += `<div class="notif-panel-footer">
                <a href="${APP_URL}?page=notifications">Ver todas →</a>
            </div>`;

                                panel.innerHTML = html;

                                panel.querySelectorAll('.notif-item[data-read="0"]').forEach(el => {
                                    el.addEventListener('click', async () => {
                                        await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_read`, { id: parseInt(el.dataset.id) });
                                        cachedCount = -1;
                                        fetchCount();
                                    });
                                });

                                const markAllBtn = panel.querySelector('#notif-mark-all');
                                if (markAllBtn) {
                                    markAllBtn.addEventListener('click', async e => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        await apiFetch(`${APP_URL}/app/api/notifications.php?action=mark_all_read`, {});
                                        cachedCount = -1;
                                        fetchCount();
                                        renderPanel();
                                    });
                                }
                            } catch (e) {
                                panel.innerHTML = '<div class="empty-state text-sm text-danger">Error al cargar</div>';
                            }
                        }

                        bell.addEventListener('click', e => {
                            e.stopPropagation();
                            const isOpen = !panel.classList.contains('hidden');
                            panel.classList.toggle('hidden');
                            if (!isOpen) renderPanel();
                        });

                        document.addEventListener('click', e => {
                            if (!panel.contains(e.target) && e.target !== bell) {
                                panel.classList.add('hidden');
                            }
                        });

                        fetchCount();
                        setInterval(fetchCount, 30000);
                    })();
                </script>

                <script>
                    // Header user dropdown
                    (function () {
                        const btn = document.getElementById('header-user-btn');
                        const dropdown = document.getElementById('header-user-dropdown');
                        if (!btn || !dropdown) return;

                        btn.addEventListener('click', e => {
                            e.stopPropagation();
                            dropdown.classList.toggle('hidden');
                        });

                        document.addEventListener('click', e => {
                            if (!dropdown.contains(e.target) && e.target !== btn) {
                                dropdown.classList.add('hidden');
                            }
                        });
                    })();
                </script>