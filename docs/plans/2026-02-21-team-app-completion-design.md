# WINOJI — Diseño de Completación
**Fecha:** 2026-02-21
**Estado:** Aprobado

## Contexto
App de colaboración interna (Notion + Jira + GitHub). Stack: PHP 8.x vanilla, MySQL 8.x, Vanilla JS.
Para uso real, equipo de tamaño variable, sin deadline. Enfoque: Fix-first → Build.

## Enfoque General: A — Fix-first → Build
1. Arreglar todos los bugs críticos primero (base limpia)
2. Implementar features core faltantes
3. Completar integración GitHub
4. UX polish

---

## Sección 1: Bugs Críticos (Base)

### 1.1 XSS en Wiki
- Añadir DOMPurify (JS) al layout para sanitizar HTML antes de insertarlo en el DOM
- En el servidor: `strip_tags()` con whitelist (p, h1-h3, ul, ol, li, strong, em, code, pre, a) antes de guardar
- Archivo afectado: `app/assets/js/wiki.js`, `app/api/pages.php`, `app/includes/layout_top.php`

### 1.2 IDs Hardcodeados
- **project_id:** Añadir dropdown de proyectos en el sidebar. Proyecto activo en `localStorage`. JS lo lee desde ahí.
- **team_id:** Leer de `$_SESSION['user']['team_id']` (obtener en login de tabla `team_members`)
- Archivos: `app/assets/js/kanban.js`, `app/assets/js/issues.js`, `app/api/team.php`, `app/includes/auth.php`, `app/api/auth.php`

### 1.3 GitHub API Errors
- En `github_request()`: capturar HTTP status con `curl_getinfo(CURLINFO_HTTP_CODE)`
- Devolver array estandarizado: `['success' => bool, 'data' => ..., 'error' => string]`
- Frontend muestra el mensaje de error real
- Archivo: `app/api/github.php`

### 1.4 Profile Page 404
- Crear `app/pages/profile.php`
- Crear `app/api/profile.php` con endpoints: get, update (nombre, email, password, github_token, avatar)
- Archivo nuevo: `app/pages/profile.php`, `app/api/profile.php`

---

## Sección 2: Features Core Faltantes

### 2.1 Comentarios en Issues
- API: `app/api/comments.php` — endpoints: list(issue_id), create, delete
- Permisos: autor puede borrar propio; admin puede borrar cualquiera
- UI: hilo en panel de detalle de issues (avatar, nombre, fecha, texto)
- Archivos: `app/api/comments.php` (nuevo), `app/pages/issues.php`, `app/assets/js/issues.js`

### 2.2 Labels
- API: `app/api/labels.php` — CRUD de labels por proyecto, add/remove label de issue
- UI: chips de color en cards de issues y kanban, picker en detalle de issue, filtro por label
- Archivos: `app/api/labels.php` (nuevo), `app/pages/issues.php`, `app/pages/kanban.php`, `app/assets/js/issues.js`, `app/assets/js/kanban.js`

### 2.3 Issue Assignment UI
- Dropdown de miembros del equipo en panel de detalle de issue
- Avatar del asignado visible en cards del kanban
- Archivos: `app/pages/issues.php`, `app/assets/js/issues.js`, `app/assets/js/kanban.js`

### 2.4 Dashboard con Datos Reales
- API: `app/api/dashboard.php` — stats (issues abiertos/en progreso/PRs), mis issues, actividad reciente
- UI: contadores, lista de mis issues asignados, feed de actividad reciente
- Archivos: `app/api/dashboard.php` (nuevo), `app/pages/dashboard.php`, `app/assets/js/dashboard.js` (nuevo)

### 2.5 Profile Page
- Ver y editar: nombre, email, contraseña (requiere contraseña actual), GitHub Personal Access Token, avatar (URL)
- Token GitHub guardado encriptado igual que tokens de repo
- Archivos: `app/pages/profile.php` (nuevo), `app/api/profile.php` (nuevo)

### 2.6 Project Switcher + Multi-proyecto
- Dropdown en sidebar con todos los proyectos + botón crear nuevo
- Proyecto activo en `localStorage`, kanban e issues recargan al cambiar
- Archivos: `app/includes/layout_top.php`, `app/assets/js/kanban.js`, `app/assets/js/issues.js`

### 2.7 Historial de Versiones Wiki
- Botón "Historial" en editor wiki
- Panel lateral con lista de versiones (fecha, autor)
- Click en versión: ver contenido + botón "Restaurar"
- Archivos: `app/pages/wiki.php`, `app/assets/js/wiki.js`, `app/api/pages.php`

---

## Sección 3: GitHub Integración Completa

### 3.1 PR Listing por Issue
- Al abrir detalle de issue: buscar PRs cuya `head branch` coincida con branches creadas desde ese issue
- También: input para vincular PR por URL manualmente (guardar en tabla `branches` con pr_url)
- Mostrar: título, estado (open/merged/closed), autor, fecha, link a GitHub
- Archivos: `app/api/github.php`, `app/pages/issues.php`, `app/assets/js/issues.js`, `db/schema.sql` (añadir pr_url a branches)

### 3.2 Sincronización Estado PR → Issue (Polling)
- Al cargar detalle de issue: consultar estado actual de PRs en GitHub API
- Si PR mergeado → issue pasa a `done`; si PR cerrado sin merge → issue vuelve a `todo`
- Pull-based (no webhooks), sin necesidad de URL pública
- Archivos: `app/api/github.php`, `app/assets/js/issues.js`

### 3.3 Commits por Branch
- En panel de issue, cada branch es expandible
- Al expandir: carga últimos 10 commits (mensaje, autor, fecha, link)
- Archivos: `app/api/github.php`, `app/assets/js/issues.js`

### 3.4 GitHub Token por Usuario
- Token personal en profile (sección 2.5)
- En `github_request()`: si proyecto no tiene token propio, usar token del usuario actual
- Archivos: `app/api/github.php`, `app/includes/auth.php`

---

## Sección 4: UX Polish

### 4.1 Loading States
- Botones: deshabilitar + texto "Cargando..." durante petición
- Listas: skeleton loaders animados mientras cargan
- Archivos: `app/assets/css/main.css`, todos los JS

### 4.2 Confirmaciones para Borrar
- Modal de confirmación reutilizable (no `window.confirm()`)
- Para: borrar issue, página wiki, miembro, label
- Archivos: `app/assets/js/utils.js` (nuevo), `app/assets/css/main.css`

### 4.3 Empty States
- Mensaje + botón de acción cuando no hay datos
- Issues vacíos, wiki vacío, kanban vacío, sin miembros
- Archivos: todos los JS de features

### 4.4 Paginación en Issues
- 25 issues por página, botones Anterior/Siguiente, total de resultados
- API: `?action=list&page=1&per_page=25`
- Archivos: `app/api/issues.php`, `app/assets/js/issues.js`

### 4.5 Responsive / Mobile
- Media queries desde 768px
- Sidebar: colapsa en menú hamburguesa en mobile
- Kanban: scroll horizontal
- Panel de detalle issues: pantalla completa en mobile
- Archivos: `app/assets/css/main.css`, `app/includes/layout_top.php`

### 4.6 Toast Notifications
- Sistema de toasts reutilizable: success (verde), error (rojo), warning (amarillo)
- Reemplaza todos los `alert()` del código
- Auto-desaparecen a los 3 segundos
- Archivos: `app/assets/js/utils.js` (nuevo), `app/assets/css/main.css`

---

## Archivos Nuevos a Crear
- `app/api/comments.php`
- `app/api/labels.php`
- `app/api/dashboard.php`
- `app/api/profile.php`
- `app/pages/profile.php`
- `app/assets/js/dashboard.js`
- `app/assets/js/utils.js`

## Archivos Existentes a Modificar
- `app/api/pages.php` — sanitización XSS, versiones UI
- `app/api/github.php` — error handling, PR, commits, token fallback
- `app/api/issues.php` — paginación
- `app/api/team.php` — team_id dinámico
- `app/api/auth.php` — incluir team_id en sesión
- `app/assets/js/wiki.js` — DOMPurify, historial
- `app/assets/js/issues.js` — assignment, labels, comments, PRs, commits, paginación, toasts
- `app/assets/js/kanban.js` — project_id dinámico, labels, assignment
- `app/assets/css/main.css` — responsive, toasts, skeletons, empty states
- `app/includes/layout_top.php` — DOMPurify script, project switcher
- `db/schema.sql` — añadir pr_url a branches table
