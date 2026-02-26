# Xray Test Management — Diseño

## Objetivo
Permitir que cada issue tenga test cases manuales (estilo Jira/Xray): múltiples tests con pasos, asignables a cualquier miembro, ejecutables múltiples veces con historial completo.

## UI

### Tabs en Full Issue View
El área izquierda del full issue view (actualmente Descripción + Checklist + Comentarios apilados) se convierte en tabs:

```
[Descripción]  [Tests]  [Comentarios]
```

El checklist permanece dentro de Descripción. Los tabs reemplazan el scroll vertical con navegación horizontal.

### Tab "Tests"
- Lista de test cases del issue
- Botón "＋ Nuevo Test" (admin/manager/cualquier miembro)
- Cada test case muestra:
  - Título
  - Asignado a (avatar + nombre)
  - Nº de pasos
  - Último resultado: `✅ PASS` / `❌ FAIL` / `⬜ Sin ejecutar`
  - Botones: `▶ Ejecutar` | `✏ Editar` | `🗑 Eliminar`
  - Expandible: "▼ Historial (N ejecuciones)"

### Historial expandido
```
🗓 Hoy 14:30   | Carlos  | ❌ FAIL  [👁 Ver]
🗓 Ayer 10:15  | Maria   | ✅ PASS  [👁 Ver]
🗓 25 Feb      | Carlos  | ✅ PASS  [👁 Ver]
```

## Modales

### Modal: Crear/Editar Test Case
- Campo: Título del test
- Select: Asignado a (miembros del equipo)
- Sección de pasos (drag-reorder opcional):
  - Por cada paso: Acción + Resultado esperado
  - Botón "＋ Añadir paso"
  - Botón eliminar paso (✕)
- Botones: Cancelar | Guardar

### Modal: Ejecutar Test (paso a paso)
Muestra los pasos en orden. Por cada paso:
```
Paso 1 de 3: Ir a /login
Resultado esperado: Ver formulario de login

[ ✓ Pass ]  [ ✗ Fail ]  [ — Skip ]

Comentario (opcional): ___________
```
Navegación: Anterior / Siguiente. Al completar todos: "Finalizar ejecución".
- Resultado global: **PASS** (si todos son pass o skip) o **FAIL** (si alguno es fail)

### Modal: Ver Ejecución (solo lectura)
Muestra los pasos con los resultados históricos: acción, esperado, resultado marcado, comentario dejado en esa ejecución.

## Base de datos

```sql
CREATE TABLE test_cases (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    issue_id   INT NOT NULL,
    title      VARCHAR(255) NOT NULL,
    assignee_id INT DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issue_id)    REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (assignee_id) REFERENCES users(id)  ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id)  ON DELETE RESTRICT
);

CREATE TABLE test_steps (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    test_case_id   INT NOT NULL,
    sort_order     INT NOT NULL DEFAULT 0,
    action         TEXT NOT NULL,
    expected_result TEXT DEFAULT NULL,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE
);

CREATE TABLE test_executions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    test_case_id INT NOT NULL,
    executed_by  INT NOT NULL,
    result       ENUM('pass','fail') NOT NULL,
    executed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (executed_by)  REFERENCES users(id)      ON DELETE RESTRICT
);

CREATE TABLE test_execution_steps (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    step_id      INT NOT NULL,
    result       ENUM('pass','fail','skip') NOT NULL,
    comment      TEXT DEFAULT NULL,
    FOREIGN KEY (execution_id) REFERENCES test_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id)      REFERENCES test_steps(id)      ON DELETE CASCADE
);
```

## API

**Archivo:** `app/api/tests.php`

| Action | Método | Auth | Descripción |
|--------|--------|------|-------------|
| `list` | GET | auth | Lista test cases de un issue (`?issue_id=N`) con steps + último resultado |
| `create` | POST | auth | Crea test case con pasos |
| `update` | POST | auth | Actualiza título, asignado y pasos |
| `delete` | POST | auth+csrf | Elimina test case |
| `execute` | POST | auth+csrf | Crea ejecución con resultados por paso |
| `executions` | GET | auth | Historial de ejecuciones de un test case (`?test_case_id=N`) |
| `execution_detail` | GET | auth | Detalle de una ejecución (`?execution_id=N`) |

## Archivos a tocar

| Archivo | Cambio |
|---------|--------|
| `db/schema.sql` | Añadir 4 tablas |
| `db/migrations/add_test_management.sql` | Migration SQL |
| `app/api/tests.php` | Nuevo: todas las acciones API |
| `app/pages/issues.php` | Tabs en full issue view + modales de test |
| `app/assets/js/issues.js` | Lógica de tabs + test cases JS |
