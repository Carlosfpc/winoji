# Quick Wins — Design Doc

**Fecha:** 2026-02-23

## Features

### 1. Paginación en notificaciones

**Problema:** `GET notifications.php?action=list` devuelve todas las notificaciones sin límite. La página `/notifications` carga todo de golpe.

**Solución:**
- API acepta `?page=N&per_page=20` (default: page=1, per_page=20)
- Respuesta incluye `{ data, total, page, per_page }`
- Página `/notifications` añade botones Anterior / Siguiente (mismo patrón que issues list)

**Archivos:** `app/api/notifications.php`, `app/pages/notifications.php`

---

### 2. Burndown chart en Dashboard

**Datos:** Tabla `issue_status_log` — cuando `new_status='done'`, sumar `story_points` de esa issue. Agrupa por `DATE(changed_at)` últimos 30 días. Días sin actividad = 0 puntos.

**API:** Nuevo action `GET dashboard.php?action=burndown&project_id=N`
Respuesta: `{ success, data: [{ day: "2026-02-01", points: 5 }, ...] }` — siempre 30 entradas (rellenando con 0 los días vacíos).

**Gráfico:** SVG inline puro (sin librerías), generado en JS:
- Card full-width, altura 180px
- Barras verticales, eje X con dd/mm cada 5 días, eje Y escala automática
- Tooltip al hover con valor exacto
- Colores del sistema: `#4f46e5` (barras) sobre `var(--bg-card)`

**Ubicación:** Dashboard — nueva card debajo de las 8 stat cards, encima del priority bar.

**Archivos:** `app/api/dashboard.php`, `app/assets/js/dashboard.js`, `app/pages/dashboard.php`
