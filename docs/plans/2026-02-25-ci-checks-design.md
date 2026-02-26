# CI Checks Integration — Design

## Goal

Añadir integración de CI/calidad de código a la app en dos partes:
1. **Generador de workflow** — botón que hace push de `.github/workflows/ci.yml` al repo conectado
2. **Display de estado CI** — mostrar ✅/❌/⏳ de GitHub Checks junto a cada branch en el panel de issue

## Architecture

No se añaden tablas a la DB. Todo es lectura/escritura directa contra la GitHub API usando el token ya almacenado en `github_repos.access_token`.

### Componentes

| Componente | Archivo | Cambio |
|---|---|---|
| `generate_workflow()` | `app/api/github.php` | Nueva función + acción HTTP |
| `get_check_status()` | `app/api/github.php` | Nueva función + acción HTTP |
| Botón "Generar CI" | `app/pages/project.php` | Nuevo botón en sección GitHub |
| CI status chips | `app/pages/issues.php` (JS inline) | Fetch check status y renderizar chips |

## APIs de GitHub usadas

### Crear/actualizar archivo (workflow)
```
PUT /repos/{owner}/{repo}/contents/{path}
Body: { message, content (base64), sha (si existe) }
```
- Si el archivo ya existe → update (necesita su SHA actual)
- Si no existe → create

### Leer check runs de un commit
```
GET /repos/{owner}/{repo}/commits/{ref}/check-runs
```
- `ref` puede ser el nombre de la branch (GitHub resuelve al último commit)
- Response: `{ check_runs: [{ name, status, conclusion, html_url }] }`
- `status`: "queued" | "in_progress" | "completed"
- `conclusion`: "success" | "failure" | "neutral" | "cancelled" | "skipped" | "timed_out" | null

### Lógica de conclusion → chip

| conclusion | chip |
|---|---|
| "success" | ✅ CI passed |
| "failure" / "timed_out" | ❌ CI failed |
| null / "queued" / "in_progress" | ⏳ Running |
| sin check runs | (nada) |

Si hay múltiples check runs → mostrar el peor resultado (failure > running > success).

## Workflow generado

```yaml
name: CI

on:
  push:
    branches: ['**']
  pull_request:
    branches: ['**']

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: PHP Syntax check
        run: find . -name "*.php" -not -path "*/vendor/*" | xargs php -l
      - name: Setup Node.js
        if: hashFiles('package.json') != ''
        uses: actions/setup-node@v4
        with: { node-version: 20 }
      - name: Install JS deps
        if: hashFiles('package.json') != ''
        run: npm ci
      - name: JS lint
        if: hashFiles('package.json') != '' && hashFiles('.eslintrc*') != ''
        run: npm run lint
```

- Corre en cada push y PR, en cualquier rama
- JS steps son condicionales (solo si hay `package.json`)
- Extensible por el usuario después

## UI — project.php (sección GitHub)

```
✅ Conectado: owner/repo-name        [Desconectar]

[⚡ Generar workflow CI]
```

El botón llama a `POST /app/api/github.php?action=generate_workflow` con `project_id`.
Muestra toast de éxito/error. Si el archivo ya existe, lo actualiza.

## UI — issues.php (panel issue, sección GitHub)

Junto a cada branch, después de renderizar el nombre:

```
feat/issue-123   ✅ CI passed
fix/issue-45     ❌ CI failed  [↗]
feat/issue-200   ⏳ Running
```

El chip `↗` linkea a `html_url` del check run fallido.

El fetch de check status ocurre en `loadGitHubSection()` (función JS existente), en paralelo con el fetch de branches, usando `Promise.all` o encadenado tras recibir las branches.

## Error handling

- Si el token no tiene permisos para `checks` → mostrar nada (no bloquear)
- Si el workflow ya existe → actualizarlo silenciosamente (no error)
- Si la API de GitHub está lenta → los chips de CI no bloquean la carga de branches

## Archivos a modificar (en orden)

1. `app/api/github.php` — añadir `generate_workflow()`, `get_check_status()` y sus rutas HTTP
2. `app/pages/project.php` — añadir botón "Generar CI" en sección GitHub
3. `app/pages/issues.php` — añadir fetch y render de CI chips en panel issue
