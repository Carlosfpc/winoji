# Sprint Swimlanes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Añadir swimlanes por usuario al kanban del sprint activo, con chips de filtro en la parte superior y drag & drop que asigna issues al usuario de la fila destino.

**Architecture:** Solo se modifica `app/assets/js/sprint.js`. Se añaden dos variables de módulo (`sprintMembers`, `activeUserFilter`), se carga el team en paralelo con el sprint activo, y se reemplaza `renderSprintKanban` con `renderSwimlanesKanban` + `renderFilterChips`. El drop handler en cada celda hace una sola llamada a `issues.php?action=update` con `{ status, assigned_to }`.

**Tech Stack:** Vanilla JS · API existente `team.php?action=members` (GET, sin parámetros — usa team_id del usuario en sesión) · `issues.php?action=update` (ya soporta `assigned_to` en la whitelist)

---

### Task 1: Swimlanes en sprint.js

**Files:**
- Modify: `app/assets/js/sprint.js`

---

**Step 1: Añadir variables de módulo y actualizar `loadSprintPage`**

En `app/assets/js/sprint.js`, reemplaza las líneas 1-4 (variables de módulo) y la función `loadSprintPage` (líneas 14-40) con:

```js
const SPRINT_COLS = ['todo', 'in_progress', 'review', 'done'];
const SPRINT_COL_LABELS = { todo: 'Pendiente', in_progress: 'En curso', review: 'Revisión', done: 'Hecho' };
let activeSprint    = null;
let sprintMembers   = [];
let activeUserFilter = null; // null = Todos

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ─── Sprint page load ─────────────────────────────────────────────────────────

async function loadSprintPage() {
    if (!PROJECT_ID) {
        document.getElementById('sprint-kanban-wrap').innerHTML =
            '<div style="padding:2rem;text-align:center;color:var(--text-secondary);">Selecciona un proyecto en el menú lateral.</div>';
        return;
    }
    try {
        const res     = await fetch(`${APP_URL}/app/api/sprints.php?action=list&project_id=${PROJECT_ID}`);
        const data    = await res.json();
        const sprints = data.data || [];
        activeSprint  = sprints.find(s => s.status === 'active') || null;

        if (!activeSprint) {
            renderNoActiveSprint();
        } else {
            const [sRes, membersRes] = await Promise.all([
                fetch(`${APP_URL}/app/api/sprints.php?action=get&id=${activeSprint.id}`),
                fetch(`${APP_URL}/app/api/team.php?action=members`)
            ]);
            const sData       = await sRes.json();
            const membersData = await membersRes.json();
            activeSprint  = sData.data;
            sprintMembers = membersData.data || [];
            renderSprintHeader(activeSprint);
            renderSwimlanesKanban(activeSprint.issues || [], sprintMembers);
        }
    } catch(e) {
        document.getElementById('sprint-kanban-wrap').innerHTML =
            '<div style="padding:2rem;text-align:center;color:var(--text-secondary);">Error al cargar el sprint.</div>';
    }
    loadBacklog();
}
```

---

**Step 2: Añadir `renderFilterChips` y `renderSwimlanesKanban`**

Reemplaza toda la sección `// ─── Sprint Kanban ───` (funciones `renderSprintKanban`, que van de la línea 60 a la 100) con:

```js
// ─── Sprint Kanban con swimlanes ──────────────────────────────────────────────

function renderFilterChips(members) {
    const wrap     = document.getElementById('sprint-kanban-wrap');
    const chipsDiv = document.createElement('div');
    chipsDiv.style.cssText = 'display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.75rem;align-items:center;';

    // Chip "Todos"
    const allChip = document.createElement('button');
    allChip.textContent = 'Todos';
    const allActive = activeUserFilter === null;
    allChip.style.cssText = `padding:0.25rem 0.75rem;border-radius:999px;font-size:0.8rem;cursor:pointer;border:1px solid var(--border);background:${allActive ? '#4f46e5' : 'var(--bg-card)'};color:${allActive ? '#fff' : 'var(--text-primary)'};`;
    allChip.addEventListener('click', () => {
        activeUserFilter = null;
        renderSwimlanesKanban(activeSprint.issues || [], sprintMembers);
    });
    chipsDiv.appendChild(allChip);

    members.forEach(m => {
        const isActive = activeUserFilter == m.id;
        const initial  = (m.name || '?').charAt(0).toUpperCase();
        const chip     = document.createElement('button');
        chip.style.cssText = `display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem 0.2rem 0.3rem;border-radius:999px;font-size:0.8rem;cursor:pointer;border:1px solid var(--border);background:${isActive ? '#4f46e5' : 'var(--bg-card)'};color:${isActive ? '#fff' : 'var(--text-primary)'};`;
        const avatarHtml = m.avatar
            ? `<img src="${escapeHtml(m.avatar)}" style="width:18px;height:18px;border-radius:50%;object-fit:cover;" alt="">`
            : `<span style="width:18px;height:18px;border-radius:50%;background:${isActive ? 'rgba(255,255,255,0.3)' : '#4f46e5'};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:700;">${escapeHtml(initial)}</span>`;
        chip.innerHTML = `${avatarHtml}${escapeHtml(m.name)}`;
        chip.addEventListener('click', () => {
            activeUserFilter = isActive ? null : m.id;
            renderSwimlanesKanban(activeSprint.issues || [], sprintMembers);
        });
        chipsDiv.appendChild(chip);
    });

    wrap.appendChild(chipsDiv);
}

function renderSwimlanesKanban(issues, members) {
    const wrap = document.getElementById('sprint-kanban-wrap');
    wrap.innerHTML = '';

    renderFilterChips(members);

    // Filas visibles según filtro activo
    const rows = activeUserFilter !== null
        ? [members.find(m => m.id == activeUserFilter), { id: null, name: 'Sin asignar' }].filter(Boolean)
        : [...members, { id: null, name: 'Sin asignar' }];

    const scrollWrap = document.createElement('div');
    scrollWrap.style.cssText = 'overflow-x:auto;';

    const grid = document.createElement('div');
    grid.style.cssText = `display:grid;grid-template-columns:140px repeat(4,1fr);min-width:660px;gap:2px;`;

    // Cabecera de columnas
    const corner = document.createElement('div');
    corner.style.cssText = 'padding:0.4rem;';
    grid.appendChild(corner);
    SPRINT_COLS.forEach(col => {
        const h = document.createElement('div');
        h.style.cssText = 'padding:0.4rem 0.5rem;font-weight:600;font-size:0.8rem;color:var(--text-secondary);text-align:center;border-bottom:2px solid var(--border);';
        h.textContent = SPRINT_COL_LABELS[col];
        grid.appendChild(h);
    });

    // Filas de swimlane
    rows.forEach(row => {
        const isUnassigned = row.id === null;
        const rowIssues    = issues.filter(i =>
            isUnassigned
                ? !i.assigned_to
                : String(i.assigned_to) === String(row.id)
        );

        // Header de fila
        const rowHeader = document.createElement('div');
        rowHeader.style.cssText = 'padding:0.4rem 0.3rem;display:flex;align-items:flex-start;gap:0.35rem;border-top:1px solid var(--border);padding-top:0.6rem;';
        const initial = isUnassigned ? '?' : (row.name || '?').charAt(0).toUpperCase();
        const avatarHtml = (!isUnassigned && row.avatar)
            ? `<img src="${escapeHtml(row.avatar)}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">`
            : `<span style="width:22px;height:22px;border-radius:50%;background:${isUnassigned ? '#9ca3af' : '#4f46e5'};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:700;flex-shrink:0;">${escapeHtml(initial)}</span>`;
        rowHeader.innerHTML = `${avatarHtml}<div style="min-width:0;"><div style="font-size:0.75rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:90px;">${escapeHtml(isUnassigned ? 'Sin asignar' : row.name)}</div><div style="font-size:0.65rem;color:var(--text-secondary);">${rowIssues.length} issues</div></div>`;
        grid.appendChild(rowHeader);

        // Celdas por columna
        SPRINT_COLS.forEach(col => {
            const cell = document.createElement('div');
            cell.style.cssText = 'min-height:80px;padding:0.3rem;border-top:1px solid var(--border);border-left:1px solid var(--border);background:var(--bg-secondary,#f9fafb);border-radius:4px;display:flex;flex-direction:column;gap:0.3rem;';
            cell.dataset.col    = col;
            cell.dataset.userId = row.id ?? '';

            const cellIssues = rowIssues.filter(i => i.status === col);
            if (!cellIssues.length) {
                const empty = document.createElement('div');
                empty.style.cssText = 'border:2px dashed var(--border);border-radius:4px;flex:1;min-height:56px;opacity:0.35;';
                cell.appendChild(empty);
            }
            cellIssues.forEach(issue => cell.appendChild(buildSprintCard(issue)));

            cell.addEventListener('dragover', e => { e.preventDefault(); cell.style.outline = '2px dashed #4f46e5'; cell.style.outlineOffset = '-2px'; });
            cell.addEventListener('dragleave', e => { if (!cell.contains(e.relatedTarget)) cell.style.outline = ''; });
            cell.addEventListener('drop', async e => {
                e.preventDefault();
                cell.style.outline = '';
                try {
                    const parsed       = JSON.parse(e.dataTransfer.getData('text/plain'));
                    const newAssigned  = row.id != null ? parseInt(row.id) : null;
                    const updates      = { id: parsed.id };

                    if (parsed.type === 'backlog-issue') {
                        // Añadir al sprint primero
                        await apiFetch(`${APP_URL}/app/api/sprints.php?action=add_issue`, { sprint_id: activeSprint.id, issue_id: parsed.id });
                        if (col !== 'todo')  updates.status      = col;
                        if (newAssigned !== null) updates.assigned_to = newAssigned;
                        if (Object.keys(updates).length > 1) {
                            await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, updates);
                        }
                    } else if (parsed.type === 'sprint-issue') {
                        const oldAssigned = parsed.assigned_to != null ? parseInt(parsed.assigned_to) : null;
                        if (col !== parsed.status)        updates.status      = col;
                        if (newAssigned !== oldAssigned)  updates.assigned_to = newAssigned;
                        if (Object.keys(updates).length > 1) {
                            await apiFetch(`${APP_URL}/app/api/issues.php?action=update`, updates);
                        }
                    }
                    loadSprintPage();
                } catch(err) {}
            });
            grid.appendChild(cell);
        });
    });

    scrollWrap.appendChild(grid);
    wrap.appendChild(scrollWrap);
}
```

---

**Step 3: Actualizar `buildSprintCard` — añadir `assigned_to` al payload de dragstart**

En `buildSprintCard` (línea ~128), reemplaza la línea:

```js
    card.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'sprint-issue', id: issue.id, status: issue.status }));
    });
```

Con:

```js
    card.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', JSON.stringify({
            type: 'sprint-issue',
            id: issue.id,
            status: issue.status,
            assigned_to: issue.assigned_to ?? null
        }));
    });
```

---

**Step 4: Actualizar dragstart del backlog — añadir `assigned_to: null`**

En `loadBacklog` (línea ~185-188), reemplaza:

```js
        backlogEl.querySelectorAll('.backlog-row').forEach(row => {
            row.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'backlog-issue', id: parseInt(row.dataset.issueId) }));
            });
        });
```

Con:

```js
        backlogEl.querySelectorAll('.backlog-row').forEach(row => {
            row.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'backlog-issue', id: parseInt(row.dataset.issueId), assigned_to: null }));
            });
        });
```

---

**Step 5: Verificar sintaxis del JS**

No hay compilación en este proyecto. Verificar visualmente que no haya llaves ni paréntesis descuadrados en sprint.js.

Como alternativa, correr:
```bash
node --check /c/Users/carlo/proyects/claude-skills/app/assets/js/sprint.js
```
Expected: sin salida (éxito silencioso).

---

**Step 6: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add app/assets/js/sprint.js
git commit -m "feat: sprint kanban swimlanes by user with filter chips and drag-to-assign"
```

---

## Smoke test manual en Laragon (`http://localhost/teamapp/public?page=sprint`)

1. Con sprint activo y issues asignadas → aparecen filas por usuario + fila "Sin asignar"
2. Click en chip de un usuario → solo su fila + "Sin asignar" visibles
3. Click en "Todos" → todas las filas visibles
4. Arrastra una card a otra columna (misma fila) → cambia el status de la issue
5. Arrastra una card a otra fila (misma columna) → cambia el asignado
6. Arrastra una card a "Sin asignar" → assigned_to queda NULL
7. Arrastra una card del backlog a la celda de un usuario → añade al sprint + asigna + cambia status
8. Arrastra una card del backlog a "Sin asignar" → añade al sprint sin asignar
9. Botón "← Backlog" sigue funcionando
10. Botón "→ Sprint" del backlog sigue funcionando (añade sin asignar, aparece en "Sin asignar")
