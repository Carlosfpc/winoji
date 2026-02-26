# Wiki Tables + PDF Export — Design

**Date:** 2026-02-26

## Goal

Add full table support (insert, edit, merge/unmerge cells, add/remove rows and columns) to the wiki Quill editor via `quill-better-table`, and add one-click PDF export of any wiki page via a server-rendered print page.

## Architecture

Two independent features layered on the existing wiki:

1. **Tables** — register `quill-better-table` module in Quill; add toolbar button with row×col picker; context menu provided by the plugin for structural edits; `sanitize_html()` extended to allow `colspan`/`rowspan` attributes; view mode inherits Quill's table CSS.

2. **PDF export** — new PHP action `export_print` in `pages.php` returns a self-contained HTML document; client opens it in a new tab; `window.print()` fires automatically; after printing the tab closes. Zero extra dependencies.

---

## Section 1 — Tables (quill-better-table)

### CDN additions (`wiki.php` `$extra_head`)

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill-better-table@1.2.10/dist/quill-better-table.css">
<script src="https://cdn.jsdelivr.net/npm/quill-better-table@1.2.10/dist/quill-better-table.js"></script>
```

Added **before** the existing Quill CDN links so Quill is available when the plugin loads.
Actually: plugin must load **after** Quill. Order: Quill CSS → Quill JS → quill-better-table CSS → quill-better-table JS.

### Quill registration (`wiki.js`, before `initQuill`)

```js
Quill.register({ 'modules/better-table': QuillBetterTable }, true);
```

### `initQuill()` changes

Add `better-table` to modules and add `table` handler in toolbar:

```js
modules: {
    toolbar: {
        container: [
            [{ header: [1, 2, 3, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            ['blockquote', 'code-block'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link', 'image', 'table'],   // ← table button added
            ['clean']
        ],
        handlers: {
            image: imageUploadHandler,
            table: tablePickerHandler    // ← custom handler
        }
    },
    history: { delay: 500, maxStack: 100 },
    'better-table': {
        operationMenu: {
            items: {
                unmergeCells: { text: 'Separar celdas' },
                insertColumnRight: { text: 'Insertar columna →' },
                insertColumnLeft:  { text: 'Insertar columna ←' },
                insertRowUp:       { text: 'Insertar fila ↑' },
                insertRowDown:     { text: 'Insertar fila ↓' },
                mergeCells:        { text: 'Fusionar celdas' },
                deleteColumn:      { text: 'Eliminar columna' },
                deleteRow:         { text: 'Eliminar fila' },
                deleteTable:       { text: 'Eliminar tabla' },
            }
        }
    }
}
```

### Table picker handler

Small inline row×col grid (max 8×8) rendered as a floating div; on hover highlights cells; on click calls `quill.getModule('better-table').insertTable(rows, cols)`.

```js
function tablePickerHandler() {
    // Build 8×8 grid popup, position below toolbar button
    // On cell click: quill.getModule('better-table').insertTable(row, col)
    // On outside click: close popup
}
```

### `sanitize_html()` in `pages.php`

Extend allowed tags list to include all table-related tags (most already added):
```
<table><thead><tbody><tfoot><tr><th><td><colgroup><col><caption>
```

Allow `colspan` and `rowspan` attributes (currently the regex only strips `on*` events and `javascript:` in href/src). Add explicit allowlist for these attrs using `DOMDocument` or extend the regex to also preserve them.

Actually: PHP `strip_tags()` preserves ALL attributes on allowed tags. The regex cleanup only strips `on*` event handlers and `javascript:` URIs. `colspan` and `rowspan` are safe and will be preserved automatically — no change needed.

### CSS for tables in view mode (`main.css`)

```css
/* Tables in wiki content view */
.wiki-content-view table,
.ql-editor table {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75rem 0;
}
.wiki-content-view td,
.wiki-content-view th,
.ql-editor td,
.ql-editor th {
    border: 1px solid var(--border-medium);
    padding: 0.4rem 0.65rem;
    min-width: 2rem;
}
.wiki-content-view th,
.ql-editor th {
    background: var(--bg-secondary);
    font-weight: 600;
}
```

Dark mode: border/background automatically use CSS variables.

---

## Section 2 — PDF Export

### UI

In view mode toolbar (`wiki.php`), add button before "✏️ Editar":

```html
<button onclick="exportPagePdf()" class="btn btn-secondary btn-sm">📄 PDF</button>
```

### JS function (`wiki.js`)

```js
function exportPagePdf() {
    if (!currentPageId) return;
    window.open(`${APP_URL}/app/api/pages.php?action=export_print&id=${currentPageId}`, '_blank');
}
```

### PHP action `export_print` (`pages.php`)

GET, auth required (existing `require_auth()` at top of file), no CSRF needed (read-only).

```php
} elseif ($method === 'GET' && $action === 'export_print') {
    $id   = (int)($_GET['id'] ?? 0);
    $page = get_page($id);
    if (!$page) { http_response_code(404); echo 'Página no encontrada'; exit; }

    $title   = htmlspecialchars($page['data']['title'], ENT_QUOTES, 'UTF-8');
    $content = $page['data']['content'] ?? '';
    // content already sanitized on save; re-sanitize for output safety:
    $content = sanitize_html($content);

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>{$title}</title>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill-better-table@1.2.10/dist/quill-better-table.css">
      <style>
        /* print styles */
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 2rem; color: #1d1d1f; }
        h1.page-title { font-size: 1.6rem; font-weight: 700; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e5e7eb; }
        .ql-editor { padding: 0; min-height: unset; }
        table { border-collapse: collapse; width: 100%; margin: 0.75rem 0; }
        td, th { border: 1px solid #d1d5db; padding: 0.4rem 0.65rem; }
        th { background: #f3f4f6; font-weight: 600; }
        @media print {
          body { padding: 0; }
          @page { margin: 1.5cm; }
        }
      </style>
    </head>
    <body>
      <h1 class="page-title">{$title}</h1>
      <div class="ql-editor">{$content}</div>
      <script>window.print(); window.onafterprint = function(){ window.close(); };</script>
    </body>
    </html>
    HTML;
    exit;
}
```

---

## Files to Modify / Create

| File | Change |
|------|--------|
| `app/pages/wiki.php` | Add quill-better-table CDN (CSS + JS after Quill), add 📄 PDF button |
| `app/assets/js/wiki.js` | Register QuillBetterTable module, update initQuill config, add tablePickerHandler, add exportPagePdf() |
| `app/assets/css/main.css` | Table styles for .wiki-content-view and .ql-editor, table picker popup styles |
| `app/api/pages.php` | Add export_print GET action |

## Out of Scope

- Import tables from Word/Excel
- Table sorting/filtering
- PDF with images (browser print handles this natively)
- Custom paper size selection
