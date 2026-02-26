# Sprint Swimlanes — Design Doc

**Fecha:** 2026-02-23

## Resumen

Añadir swimlanes por usuario al kanban del sprint activo. Cada fila representa un miembro del equipo (más una fila "Sin asignar"). Los chips de filtro en la parte superior permiten colapsar a un solo usuario. El drag & drop entre celdas actualiza `status` y/o `assigned_to` en un solo llamada a la API.

---

## Layout

```
[Todos] [C Carlos] [M Maria] [L Luis]   ← chips de filtro

              TODO     IN_PROGRESS   REVIEW    DONE
┌─ 👤 Maria ─────────────────────────────────────────
│  card       card                   card
├─ 👤 Carlos ────────────────────────────────────────
│             card      card
├─ 👤 Luis ──────────────────────────────────────────
│  card
├─ Sin asignar ──────────────────────────────────────
│  card
```

- "Todos" activo por defecto → todas las filas visibles
- Click en chip de usuario → solo su fila + "Sin asignar" visibles
- Header de fila: avatar/inicial + nombre + contador de issues
- Celdas vacías muestran área punteada

---

## Comportamiento drag & drop

| Origen | Destino | Resultado |
|--------|---------|-----------|
| Celda (fila A, col X) | Misma fila, columna Y | Actualiza solo `status` |
| Celda (fila A, col X) | Fila B, misma columna | Actualiza solo `assigned_to` |
| Celda (fila A, col X) | Fila B, columna Y | Actualiza `status` + `assigned_to` |
| Celda cualquiera | Fila "Sin asignar" | `assigned_to = NULL` |
| Backlog row | Celda (fila B, col Y) | `add_issue` + `assigned_to` + `status` |

---

## API

Sin cambios en backend. Endpoints existentes:

| Endpoint | Uso |
|----------|-----|
| `sprints.php?action=get&id=N` | Issues del sprint (ya incluye `assigned_to` + `assignee_name`) |
| `issues.php?action=update` | `{ id, status, assigned_to }` — una sola llamada |
| `team.php?action=members` | Lista de miembros del equipo con avatar/nombre |

---

## Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `app/assets/js/sprint.js` | Reemplazar `renderSprintKanban()` con versión swimlane; añadir chips de filtro; actualizar lógica de drop |

Solo se modifica `sprint.js`. No hay cambios en PHP, CSS ni otros archivos JS.

---

## Detalles de implementación

### Carga de datos
```js
// En loadSprintPage(): fetch en paralelo
const [sprintRes, membersRes] = await Promise.all([
    fetch(`${APP_URL}/app/api/sprints.php?action=get&id=${activeSprint.id}`),
    fetch(`${APP_URL}/app/api/team.php?action=members`)
]);
```

### Estructura swimlane
```
rows = members + [{ id: null, name: 'Sin asignar' }]
cols = ['todo', 'in_progress', 'review', 'done']

for each row:
  for each col:
    issues = sprint.issues.filter(i =>
      i.status === col &&
      (row.id === null ? i.assigned_to == null : i.assigned_to == row.id)
    )
    render cell with drop zone
```

### Drop handler por celda
```js
cell.addEventListener('drop', async e => {
    const { type, id, status, assigned_to } = JSON.parse(e.dataTransfer.getData('text/plain'));
    const updates = { id };
    if (col !== status)          updates.status      = col;
    if (rowUserId !== assigned_to) updates.assigned_to = rowUserId; // null para "Sin asignar"
    if (Object.keys(updates).length > 1) {
        await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, updates);
    }
    loadSprintPage();
});
```

### Chips de filtro
```js
let activeUserFilter = null; // null = Todos

function renderFilterChips(members) {
    // Render "Todos" + one chip per member
    // Click sets activeUserFilter and re-renders swimlanes
}
```
Cuando `activeUserFilter !== null`: solo renderizar la fila del usuario + "Sin asignar".

### dragstart payload (extendido)
```js
// Sprint card
{ type: 'sprint-issue', id, status, assigned_to }

// Backlog row (añadir assigned_to: null)
{ type: 'backlog-issue', id, assigned_to: null }
```
