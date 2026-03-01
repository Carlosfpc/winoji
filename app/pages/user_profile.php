<?php
$page_title = 'Perfil de usuario';
require __DIR__ . '/../includes/layout_top.php';
?>
<div id="profile-loading" class="empty-state">Cargando...</div>
<div id="profile-error" class="empty-state" style="display:none;">Perfil no encontrado</div>
<div id="profile-content" class="page-content" style="display:none;">

    <!-- User header card -->
    <div class="card flex items-center gap-5 mb-5">
        <div id="profile-avatar" class="avatar avatar-lg flex-shrink-0"></div>
        <div>
            <div id="profile-name" class="font-bold text-xl mb-1"></div>
            <div id="profile-email" class="text-base text-muted mb-1"></div>
            <span id="profile-role" class="badge"></span>
        </div>
    </div>

    <!-- Assigned issues -->
    <div class="card mb-5">
        <div class="card-section-header">Issues asignadas</div>
        <div id="profile-issues"></div>
    </div>

    <!-- Recent activity -->
    <div class="card">
        <div class="card-section-header">Actividad reciente</div>
        <div id="profile-activity"></div>
    </div>
</div>

<script>
    const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
    const params = new URLSearchParams(window.location.search);
    const USER_ID = parseInt(params.get('id') || '0');

    const STATUS_LABELS = {
        todo: 'Todo', in_progress: 'En progreso', review: 'En revisión', done: 'Hecho'
    };
    const ACTION_LABELS = {
        issue_created: 'creó la issue',
        issue_updated: 'actualizó la issue',
        issue_deleted: 'eliminó la issue',
        comment_added: 'comentó en',
        page_created: 'creó la página',
        page_updated: 'editó la página'
    };

    function escapeHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function renderAvatar(user) {
        const el = document.getElementById('profile-avatar');
        if (user.avatar) {
            el.innerHTML = `<img src="${escapeHtml(user.avatar)}" alt="">`;
        } else {
            el.textContent = (user.name || '?')[0].toUpperCase();
        }
    }

    function renderIssues(issues) {
        const el = document.getElementById('profile-issues');
        if (!issues.length) {
            el.innerHTML = '<div class="empty-state">Sin issues asignadas en este proyecto</div>';
            return;
        }
        el.innerHTML = issues.map(i => {
            const typeColor = i.type_color || '#6b7280';
            const typeName = i.type_name || 'Issue';
            const statusLabel = STATUS_LABELS[i.status] || i.status;
            return `<a href="${APP_URL}?page=issues&open_issue=${i.id}" class="list-row-link">
            <span class="badge" style="background:${escapeHtml(typeColor)};color:#fff;">
                ${escapeHtml(typeName)}
            </span>
            <span class="flex-1 truncate text-base">${escapeHtml(i.title)}</span>
            <span class="text-sm text-muted nowrap">${escapeHtml(statusLabel)}</span>
        </a>`;
        }).join('');
    }

    function renderActivity(activity) {
        const el = document.getElementById('profile-activity');
        if (!activity.length) {
            el.innerHTML = '<div class="empty-state">Sin actividad reciente en este proyecto</div>';
            return;
        }
        el.innerHTML = activity.map(a => {
            const label = ACTION_LABELS[a.action] || a.action.replace(/_/g, ' ');
            const timeStr = typeof timeAgo === 'function'
                ? timeAgo(a.created_at)
                : new Date(a.created_at).toLocaleString('es');
            return `<div class="list-row">
            <span class="text-xs text-muted flex-shrink-0 nowrap">${escapeHtml(timeStr)}</span>
            <span class="text-base">${escapeHtml(label)} <em>${escapeHtml(a.entity_title || '#' + a.entity_id)}</em></span>
        </div>`;
        }).join('');
    }

    async function loadProfile() {
        if (!USER_ID) {
            document.getElementById('profile-loading').style.display = 'none';
            document.getElementById('profile-error').style.display = 'block';
            return;
        }

        try {
            const res = await fetch(`${APP_URL}/app/api/users.php?action=profile&id=${USER_ID}&project_id=${PROJECT_ID}`);
            const data = await res.json();

            document.getElementById('profile-loading').style.display = 'none';

            if (!data.success) {
                if (data.redirect === 'profile') {
                    window.location.href = `${APP_URL}?page=profile`;
                    return;
                }
                document.getElementById('profile-error').style.display = 'block';
                return;
            }

            const { user, issues, activity } = data.data;
            renderAvatar(user);
            document.getElementById('profile-name').textContent = user.name;
            document.getElementById('profile-email').textContent = user.email;
            document.getElementById('profile-role').textContent = user.role;
            renderIssues(issues);
            renderActivity(activity);
            document.getElementById('profile-content').style.display = 'block';
            document.title = user.name + ' — WINOJI';
        } catch (_) {
            document.getElementById('profile-loading').style.display = 'none';
            document.getElementById('profile-error').style.display = 'block';
        }
    }

    loadProfile();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>