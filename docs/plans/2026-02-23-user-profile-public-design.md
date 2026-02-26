# Public User Profile — Design Doc

**Fecha:** 2026-02-23

## Resumen

Página de perfil público `?page=user_profile&id=X` accesible solo para miembros del mismo equipo. Muestra avatar, nombre, email, rol, issues asignadas en el proyecto activo y actividad reciente. Solo lectura. Si el visitante no es del mismo equipo → 404 inline.

---

## Layout

```
┌─ Avatar + datos básicos ──────────────────────┐
│  [foto 64px]  Carlos García                   │
│               carlos@example.com              │
│               Rol: manager                    │
└───────────────────────────────────────────────┘

┌─ Issues asignadas (proyecto activo) ──────────┐
│  [tipo] Título                    In Progress │
│  [tipo] Título                    Todo        │
│  ... (últimas 20, enlaza a issues page)       │
└───────────────────────────────────────────────┘

┌─ Actividad reciente ──────────────────────────┐
│  hace 2h   creó la issue "Fix login"          │
│  hace 1d   comentó en "Dashboard bug"         │
│  ... (últimas 20 acciones)                    │
└───────────────────────────────────────────────┘
```

---

## Archivos

| Archivo | Cambio |
|---------|--------|
| `app/api/users.php` | Crear — endpoint `GET ?action=profile&id=X` |
| `app/pages/user_profile.php` | Crear — página HTML + JS |
| `public/index.php` | Modificar — añadir `'user_profile'` a `$allowed` |
| `app/assets/js/wiki.js` | Modificar — `@persona` click navega a `?page=user_profile&id=X` |

---

## API: `app/api/users.php?action=profile&id=X`

**Auth:** `require_auth()`. Solo GET.

**Control de acceso:**
```php
$me      = current_user();
$user_id = (int)($_GET['id'] ?? 0);

// Mismo usuario → redirigir a profile
if ($user_id === (int)$me['id']) {
    print json_encode(['success' => false, 'redirect' => 'profile']); exit;
}

// Verificar mismo equipo
$stmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email, u.avatar, u.role
     FROM users u
     JOIN team_members tm ON u.id = tm.user_id
     WHERE u.id = ? AND tm.team_id = ?'
);
$stmt->execute([$user_id, $me['team_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { /* 404 */ }
```

**Issues:** últimas 20 del proyecto activo asignadas al usuario:
```sql
SELECT i.id, i.title, i.status, i.priority, t.name AS type_name, t.color AS type_color
FROM issues i
LEFT JOIN issue_types t ON i.type_id = t.id
WHERE i.assigned_to = ? AND i.project_id = ?
ORDER BY i.updated_at DESC
LIMIT 20
```

**Actividad:** últimas 20 acciones del usuario en el proyecto activo:
```sql
SELECT action, entity_type, entity_id, entity_title, created_at
FROM activity_log
WHERE user_id = ? AND project_id = ?
ORDER BY created_at DESC
LIMIT 20
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user":     { "id", "name", "email", "avatar", "role" },
    "issues":   [ { "id", "title", "status", "priority", "type_name", "type_color" } ],
    "activity": [ { "action", "entity_type", "entity_id", "entity_title", "created_at" } ]
  }
}
```

---

## Página: `app/pages/user_profile.php`

- `PROJECT_ID` y `APP_URL` disponibles como en otras páginas
- Al cargar: `fetch ?action=profile&id=USER_ID&project_id=PROJECT_ID`
- Si `redirect: 'profile'` → `window.location.href = APP_URL + '?page=profile'`
- Si `success: false` → mostrar mensaje "Perfil no encontrado"
- Renderiza las tres secciones con el mismo estilo de cards que el resto de la app

---

## Wiki mention navigation

En `app/assets/js/wiki.js`, el click en `.wiki-mention-person`:
```js
// Antes:
window.location.href = `${APP_URL}?page=team`;
// Después:
const uid = personMention.dataset.userId;
window.location.href = `${APP_URL}?page=user_profile&id=${uid}`;
```
El `data-user-id` ya se incluye en el `<span>` al insertar la mención.

---

## Errores

| Caso | Comportamiento |
|------|---------------|
| `id` no numérico o 0 | 404 inline: "Perfil no encontrado" |
| Usuario no es del mismo equipo | 404 inline |
| Propio usuario | Redirige a `?page=profile` |
| Sin proyecto activo | Issues y actividad muestran sección vacía |
