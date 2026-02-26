<?php
$page_title = 'Perfil';
require __DIR__ . '/../includes/layout_top.php';
$user = current_user();
?>
<h2 class="mb-6">Mi Perfil</h2>

<!-- Avatar section -->
<div class="card flex items-center gap-5 mb-6 page-content">
    <div id="avatar-preview" class="avatar avatar-xl flex-shrink-0" style="cursor:pointer;" onclick="document.getElementById('avatar-file').click()">
        <?php
        $av = $user['avatar'] ?? '';
        if ($av): ?>
            <img src="<?= htmlspecialchars($av) ?>" alt="Avatar">
        <?php else: ?>
            <?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?>
        <?php endif; ?>
    </div>
    <div>
        <div class="font-semibold mb-1"><?= htmlspecialchars($user['name']) ?></div>
        <div class="text-sm text-muted mb-2"><?= htmlspecialchars($user['email']) ?></div>
        <label class="btn btn-secondary btn-sm" style="cursor:pointer;">
            Cambiar foto
            <input type="file" id="avatar-file" accept="image/*" style="display:none;">
        </label>
    </div>
</div>

<div class="grid-2 page-content">

    <!-- Información básica -->
    <div class="card card-compact">
        <h4 class="section-title mb-4">Información</h4>
        <div class="form-group">
            <label class="form-label">Nombre</label>
            <input id="profile-name" type="text" value="<?= htmlspecialchars($user['name']) ?>"
                class="form-input w-full">
        </div>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="text" value="<?= htmlspecialchars($user['email']) ?>" disabled
                class="form-input w-full">
        </div>
        <div class="form-group mb-4">
            <label class="form-label">Rol</label>
            <span class="badge text-xs" style="background:var(--bg-secondary);color:var(--text-primary);"><?= htmlspecialchars($user['role']) ?></span>
        </div>
        <button class="btn btn-primary w-full" id="save-profile-btn">Guardar nombre</button>
    </div>

    <!-- Cambiar contraseña -->
    <div class="card card-compact">
        <h4 class="section-title mb-4">Cambiar contraseña</h4>
        <div class="form-group">
            <label class="form-label">Contraseña actual</label>
            <input id="current-password" type="password" placeholder="••••••••"
                class="form-input w-full">
        </div>
        <div class="form-group">
            <label class="form-label">Nueva contraseña</label>
            <input id="new-password" type="password" placeholder="Mín. 6 caracteres"
                class="form-input w-full">
        </div>
        <div class="form-group mb-4">
            <label class="form-label">Confirmar contraseña</label>
            <input id="confirm-password" type="password" placeholder="Repite la contraseña"
                class="form-input w-full">
        </div>
        <button class="btn btn-primary w-full" id="change-password-btn">Cambiar contraseña</button>
    </div>

</div>

<script>
// Avatar upload handling
function resizeImageToDataURL(file, maxSize) {
    return new Promise(function(resolve, reject) {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('No se pudo leer el archivo'));
        reader.onload = function(e) {
            const img = new Image();
            img.onerror = () => reject(new Error('Formato de imagen no válido'));
            img.onload = function() {
                const canvas = document.createElement('canvas');
                const ratio = Math.min(maxSize / img.width, maxSize / img.height, 1);
                canvas.width  = Math.round(img.width  * ratio);
                canvas.height = Math.round(img.height * ratio);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                resolve(canvas.toDataURL('image/jpeg', 0.88));
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

document.getElementById('avatar-file').addEventListener('change', async function() {
    const file = this.files[0];
    if (!file) return;
    this.value = '';  // reset so same file can be re-selected

    if (file.size > 5 * 1024 * 1024) { showToast('Imagen demasiado grande (máx 5MB)', 'error'); return; }

    const label = document.querySelector('label[for], label:has(#avatar-file)') || document.querySelector('.btn-secondary');
    const preview = document.getElementById('avatar-preview');
    preview.style.opacity = '0.5';

    try {
        const dataUrl = await resizeImageToDataURL(file, 256);
        const res  = await fetch(`${APP_URL}/app/api/auth.php?action=update_avatar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ avatar: dataUrl })
        });
        const data = await res.json();
        if (data.success) {
            preview.innerHTML = `<img src="${dataUrl}" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
            showToast('Avatar actualizado');
        } else {
            showToast(data.error || 'Error al guardar avatar', 'error');
        }
    } catch(err) {
        showToast(err.message || 'Error al procesar la imagen', 'error');
    } finally {
        preview.style.opacity = '';
    }
});

document.getElementById('save-profile-btn').addEventListener('click', async () => {
    const name = document.getElementById('profile-name').value.trim();
    if (!name) { showToast('El nombre no puede estar vacío', 'error'); return; }
    const btn = document.getElementById('save-profile-btn');
    btn.disabled = true; btn.textContent = 'Guardando...';
    const res = await fetch(`${APP_URL}/app/api/auth.php?action=update_profile`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Guardar nombre';
    data.success ? showToast('Nombre actualizado') : showToast(data.error || 'Error al guardar', 'error');
});

document.getElementById('change-password-btn').addEventListener('click', async () => {
    const current  = document.getElementById('current-password').value;
    const nuevo    = document.getElementById('new-password').value;
    const confirma = document.getElementById('confirm-password').value;
    if (!current || !nuevo) { showToast('Rellena todos los campos', 'error'); return; }
    if (nuevo !== confirma)  { showToast('Las contraseñas no coinciden', 'error'); return; }
    if (nuevo.length < 6)    { showToast('Mínimo 6 caracteres', 'error'); return; }
    const btn = document.getElementById('change-password-btn');
    btn.disabled = true; btn.textContent = 'Cambiando...';
    const res = await fetch(`${APP_URL}/app/api/auth.php?action=change_password`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ current_password: current, new_password: nuevo })
    });
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Cambiar contraseña';
    if (data.success) {
        showToast('Contraseña cambiada correctamente');
        document.getElementById('current-password').value = '';
        document.getElementById('new-password').value = '';
        document.getElementById('confirm-password').value = '';
    } else {
        showToast(data.error || 'Error al cambiar contraseña', 'error');
    }
});
</script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
