<?php
$page_title = 'Team';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header mb-6">
    <h2>Team Members</h2>
    <?php if (has_role('admin')): ?>
    <button class="btn btn-primary" id="invite-btn">+ Invite Member</button>
    <?php endif; ?>
</div>
<div id="members-list"></div>

<?php if (has_role('admin')): ?>
<div id="invite-modal" class="modal hidden">
    <div class="modal-box">
        <h3 class="mb-4">Invite Member</h3>
        <div class="form-group">
            <input type="text" id="invite-name" placeholder="Name" class="form-input w-full">
        </div>
        <div class="form-group">
            <input type="email" id="invite-email" placeholder="Email" class="form-input w-full">
        </div>
        <div class="form-group mb-3">
            <select id="invite-role" class="form-select w-full">
                <option value="employee">Employee</option>
                <option value="manager">Manager</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div id="invite-result" class="text-sm mb-3"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="invite-cancel">Cancel</button>
            <button class="btn btn-primary" id="invite-save">Invite</button>
        </div>
    </div>
</div>
<script>
document.getElementById('invite-btn').addEventListener('click', () => document.getElementById('invite-modal').classList.remove('hidden'));
document.getElementById('invite-cancel').addEventListener('click', () => document.getElementById('invite-modal').classList.add('hidden'));
document.getElementById('invite-save').addEventListener('click', async () => {
    const res = await fetch('<?= APP_URL ?>/app/api/team.php?action=invite', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ name: document.getElementById('invite-name').value, email: document.getElementById('invite-email').value, role: document.getElementById('invite-role').value })
    });
    const data = await res.json();
    const resultEl = document.getElementById('invite-result');
    if (data.success) {
        resultEl.style.color = '#059669';
        resultEl.textContent = 'User created. Temp password: ' + data.temp_password;
        loadMembers();
    } else {
        resultEl.style.color = '#dc2626';
        resultEl.textContent = data.error;
    }
});
</script>
<?php endif; ?>

<script>
const IS_ADMIN = <?= has_role('admin') ? 'true' : 'false' ?>;
const CURRENT_USER_ID = <?= (int)current_user()['id'] ?>;

function escTeam(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

async function loadMembers() {
    const res = await fetch(`${APP_URL}/app/api/team.php?action=members`);
    const data = await res.json();
    const list = document.getElementById('members-list');
    list.innerHTML = (data.data || []).map(m => {
        const roleColors = { admin: 'var(--color-primary)', manager: '#0891b2', employee: '#6b7280' };
        const roleColor  = roleColors[m.team_role] || '#6b7280';
        const roleSelect = IS_ADMIN && m.id !== CURRENT_USER_ID ? `
            <select onchange="changeRole(${m.id}, this.value)" class="form-select form-select-sm">
                <option value="employee" ${m.team_role === 'employee' ? 'selected' : ''}>Employee</option>
                <option value="manager"  ${m.team_role === 'manager'  ? 'selected' : ''}>Manager</option>
                <option value="admin"    ${m.team_role === 'admin'    ? 'selected' : ''}>Admin</option>
            </select>` : `<span class="badge" style="background:${roleColor}22;color:${roleColor};border:1px solid ${roleColor}44;">${escTeam(m.team_role)}</span>`;
        const removeBtn = IS_ADMIN && m.id !== CURRENT_USER_ID ? `
            <button onclick="removeMember(${m.id})" class="btn btn-danger btn-xs">Eliminar</button>` : '';
        const initial = (m.name || '?').charAt(0).toUpperCase();
        const avatarHtml = m.avatar
            ? `<img src="${escTeam(m.avatar)}" class="avatar flex-shrink-0" alt="">`
            : `<span class="avatar flex-shrink-0 text-sm font-bold">${initial}</span>`;
        const profileUrl = `${APP_URL}?page=user_profile&id=${m.id}`;
        return `<div class="card flex flex-between items-center gap-3 mb-2">
            <div class="flex items-center gap-2 min-w-0 flex-1">
                <a href="${profileUrl}">${avatarHtml}</a>
                <div class="min-w-0">
                    <a href="${profileUrl}" class="font-bold text-primary-color" style="text-decoration:none;">${escTeam(m.name)}</a>
                    <span class="text-sm text-muted ml-1">${escTeam(m.email)}</span>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                ${roleSelect}
                ${removeBtn}
            </div>
        </div>`;
    }).join('');
}

async function changeRole(userId, role) {
    const res = await fetch(`${APP_URL}/app/api/team.php?action=update_role`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ user_id: userId, role })
    });
    const data = await res.json();
    data.success ? showToast('Rol actualizado') : showToast(data.error || 'Error', 'error');
}

async function removeMember(userId) {
    showConfirm('¿Eliminar este miembro del equipo? Perderá acceso a la aplicación.', async () => {
        const res = await fetch(`${APP_URL}/app/api/team.php?action=remove`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ user_id: userId })
        });
        const data = await res.json();
        if (data.success) { showToast('Miembro eliminado'); loadMembers(); }
        else showToast(data.error || 'Error', 'error');
    }, { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' });
}

loadMembers();
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
