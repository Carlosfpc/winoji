# Sprints — Design Doc

**Fecha:** 2026-02-23

## Resumen

Añadir sprints (iteraciones con fecha inicio/fin) al proyecto. Una issue pertenece a máximo un sprint. El sprint activo se visualiza en una página nueva con Kanban. Las issues sin sprint forman el backlog, visible en la misma página. Los sprints se gestionan desde un modal global.

---

## Base de datos

```sql
CREATE TABLE sprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('planning','active','completed') NOT NULL DEFAULT 'planning',
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

ALTER TABLE issues
    ADD COLUMN sprint_id INT DEFAULT NULL,
    ADD CONSTRAINT fk_issue_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE SET NULL;
```

- Solo un sprint puede estar `active` por proyecto a la vez.
- Al completar un sprint, las issues con `status != 'done'` tienen su `sprint_id` puesto a NULL (vuelven al backlog).

---

## API — `app/api/sprints.php`

| Action | Método | Descripción |
|--------|--------|-------------|
| `list` | GET `?project_id=N` | Todos los sprints del proyecto |
| `get` | GET `?id=N` | Sprint + issues agrupadas por status |
| `create` | POST | `{project_id, name, start_date, end_date}` → status=planning |
| `update` | POST | `{id, name, start_date, end_date}` — solo en status=planning |
| `start` | POST | `{id}` — planning→active (falla si ya hay otro activo) |
| `complete` | POST | `{id}` — active→completed, mueve issues no-done al backlog |
| `delete` | POST | `{id}` — elimina si no tiene issues, admin/manager only |
| `add_issue` | POST | `{sprint_id, issue_id}` — asigna issue al sprint |
| `remove_issue` | POST | `{issue_id}` — sprint_id=NULL (vuelve al backlog) |

---

## Páginas y UI

### `app/pages/sprint.php`

**Si no hay sprint activo:**
- Mensaje "No hay sprint activo"
- Botón "Crear sprint" que abre el modal de gestión

**Con sprint activo:**
- Header: nombre del sprint, fechas (dd/mm – dd/mm), badge de estado, botón "Gestionar sprints"
- Kanban de 4 columnas (todo / in_progress / review / done) con las issues del sprint
  - Cards iguales al Kanban existente: tipo, título, prioridad, asignado
  - Drag & drop entre columnas → actualiza `status` de la issue (mismo patrón que kanban.php)
  - Click en card → deep-link a issues page
- Sección Backlog debajo del Kanban: lista de issues sin sprint, ordenadas por prioridad
  - Cada fila tiene botón "→ Sprint" para asignar al sprint activo
  - Si no hay backlog: mensaje "Backlog vacío"

### Modal "Gestionar sprints" (`#sprint-modal`)

Accesible desde:
- Botón en la página Sprint
- Botón/icono en el sidebar junto al enlace Sprint

Contenido:
- Lista de sprints (planning / active / completed) con fechas
- Por sprint: botones contextuales según estado:
  - `planning`: Iniciar / Editar / Eliminar
  - `active`: Cerrar sprint
  - `completed`: solo nombre y fechas (solo lectura)
- Formulario crear sprint: nombre + start_date + end_date + botón Crear

### Sidebar

Añadir enlace "Sprint" entre Kanban y Roadmap. Añadir icono ⚙ de gestión junto al enlace.

---

## Archivos a modificar/crear

| Archivo | Cambio |
|---------|--------|
| `db/schema.sql` | Añadir tabla `sprints` + columna `sprint_id` en `issues` |
| `db/migrations/add_sprints.sql` | Migración para entornos existentes |
| `app/api/sprints.php` | Nuevo — todas las actions |
| `app/pages/sprint.php` | Nuevo — Kanban + backlog + modal |
| `app/assets/js/sprint.js` | Nuevo — lógica del sprint page y modal |
| `app/includes/layout_top.php` | Añadir enlace Sprint + botón gestión en sidebar |
