# Wiki Tables + PDF Export Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add full table support to the wiki editor via `quill-better-table` and one-click PDF export of any wiki page.

**Architecture:** `quill-better-table@1.2.10` registered as a Quill module; custom row×col picker popup triggers `insertTable()`; existing `sanitize_html()` extended with `<colgroup><col>`; PHP `export_print` action outputs a self-contained HTML document that auto-triggers `window.print()`.

**Tech Stack:** Quill 1.3.7 (existing CDN), quill-better-table 1.2.10 (CDN), PHP 8.3 `get_page()` + `sanitize_html()`, browser `window.print()` for PDF.

---

### Task 1 — CDN + module registration

**Files:**
- Modify: `app/pages/wiki.php` lines 3–6 (`$extra_head`)
- Modify: `app/assets/js/wiki.js` — add `Quill.register(...)` right before the `registerWikiBlots` IIFE (line ~97)
- Modify: `app/api/pages.php` line 5 — add `<colgroup><col>` to `sanitize_html` allowed tags

**Step 1: Add quill-better-table CDN to `$extra_head` in `wiki.php`**

Replace the existing `$extra_head` block (lines 3–6):

```php
$extra_head = '
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill-better-table@1.2.10/dist/quill-better-table.css">
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill-better-table@1.2.10/dist/quill-better-table.js"></script>
';
```

Order matters: Quill JS must load before quill-better-table JS.

**Step 2: Register the module in `wiki.js` — insert after line 8 (`let searchDebounceTimer`) and before the `registerWikiBlots` IIFE**

Add this block (runs immediately when the script loads, after Quill is available):

```js
// ── quill-better-table module registration ────────────────────────────────────
Quill.register({ 'modules/better-table': QuillBetterTable }, true);
```

**Step 3: Extend `sanitize_html` allowed tags in `app/api/pages.php` line 5**

Change:
```php
$allowed = '<p><h1><h2><h3><ul><ol><li><strong><em><code><pre><a><br><blockquote><u><span><div><img><table><thead><tbody><tfoot><tr><th><td>';
```
To:
```php
$allowed = '<p><h1><h2><h3><ul><ol><li><strong><em><code><pre><a><br><blockquote><u><span><div><img><table><thead><tbody><tfoot><tr><th><td><colgroup><col><caption>';
```

**Step 4: Verify with `php -l`**

```bash
php -l app/api/pages.php
php -l app/pages/wiki.php
```
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add app/pages/wiki.php app/assets/js/wiki.js app/api/pages.php
git commit -m "feat: add quill-better-table CDN + module registration + extend sanitize_html"
```

---

### Task 2 — Update `initQuill()` + `tablePickerHandler()`

**Files:**
- Modify: `app/assets/js/wiki.js` — replace `initQuill()` function (lines 135–155) and add `tablePickerHandler()` after it

**Step 1: Replace `initQuill()` in `wiki.js`**

The current `initQuill()` is at lines 135–155. Replace the entire function with:

```js
function initQuill() {
    quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
            toolbar: {
                container: [
                    [{ header: [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    ['blockquote', 'code-block'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['link', 'image', 'table'],
                    ['clean']
                ],
                handlers: {
                    image: imageUploadHandler,
                    table: tablePickerHandler
                }
            },
            history: { delay: 500, maxStack: 100 },
            'better-table': {
                operationMenu: {
                    items: {
                        unmergeCells:    { text: 'Separar celdas' },
                        insertColumnRight:{ text: 'Insertar columna →' },
                        insertColumnLeft: { text: 'Insertar columna ←' },
                        insertRowUp:      { text: 'Insertar fila ↑' },
                        insertRowDown:    { text: 'Insertar fila ↓' },
                        mergeCells:       { text: 'Fusionar celdas' },
                        deleteColumn:     { text: 'Eliminar columna' },
                        deleteRow:        { text: 'Eliminar fila' },
                        deleteTable:      { text: 'Eliminar tabla' }
                    }
                }
            },
            keyboard: {
                bindings: QuillBetterTable.keyboardBindings
            }
        }
    });
    quill.on('text-change', scheduleTypingSave);
}
```

**Step 2: Add `tablePickerHandler()` directly after `initQuill()`**

```js
function tablePickerHandler() {
    // Toggle: close if already open
    const existing = document.getElementById('wiki-table-picker');
    if (existing) { existing.remove(); return; }

    const MAX = 8;
    const picker = document.createElement('div');
    picker.id = 'wiki-table-picker';
    picker.className = 'wiki-table-picker';

    const label = document.createElement('div');
    label.className = 'wiki-table-picker-label';
    label.textContent = 'Insertar tabla';
    picker.appendChild(label);

    const grid = document.createElement('div');
    grid.className = 'wiki-table-picker-grid';

    for (let r = 1; r <= MAX; r++) {
        for (let c = 1; c <= MAX; c++) {
            const cell = document.createElement('div');
            cell.className = 'wiki-table-picker-cell';
            cell.dataset.row = r;
            cell.dataset.col = c;
            cell.addEventListener('mouseover', () => {
                label.textContent = `${r} × ${c}`;
                grid.querySelectorAll('.wiki-table-picker-cell').forEach(el => {
                    const er = parseInt(el.dataset.row), ec = parseInt(el.dataset.col);
                    el.classList.toggle('active', er <= r && ec <= c);
                });
            });
            cell.addEventListener('click', () => {
                quill.getModule('better-table').insertTable(r, c);
                picker.remove();
            });
            grid.appendChild(cell);
        }
    }
    picker.appendChild(grid);

    // Position below the toolbar table button
    const tableBtn = document.querySelector('.ql-table');
    if (tableBtn) {
        const rect = tableBtn.getBoundingClientRect();
        picker.style.top  = (rect.bottom + 4) + 'px';
        picker.style.left = rect.left + 'px';
    }
    document.body.appendChild(picker);

    // Close on outside click
    setTimeout(() => {
        document.addEventListener('click', function closePicker(e) {
            if (!picker.contains(e.target) && e.target !== tableBtn) {
                picker.remove();
                document.removeEventListener('click', closePicker);
            }
        });
    }, 0);
}
```

**Step 3: Add `exportPagePdf()` function — add it right after `tablePickerHandler()`**

```js
function exportPagePdf() {
    if (!currentPageId) return;
    window.open(`${APP_URL}/app/api/pages.php?action=export_print&id=${currentPageId}`, '_blank');
}
```

**Step 4: Verify no JS syntax errors**

Open browser console on the wiki page — no errors should appear.

**Step 5: Commit**

```bash
git add app/assets/js/wiki.js
git commit -m "feat: table picker handler + exportPagePdf() in wiki.js"
```

---

### Task 3 — CSS: table styles + picker popup

**Files:**
- Modify: `app/assets/css/main.css` — add styles after the `.wiki-content-view` block (line ~1183)

**Step 1: Add table styles and picker CSS in `main.css` after the line `[data-theme="dark"] .wiki-content-view { border-color: var(--border); }` (line 1183)**

```css
/* ── Wiki tables ────────────────────────────────────────────────────────────── */
.wiki-content-view table,
.ql-editor table {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75rem 0;
    table-layout: fixed;
}
.wiki-content-view td, .wiki-content-view th,
.ql-editor td, .ql-editor th {
    border: 1px solid var(--border-medium);
    padding: 0.4rem 0.65rem;
    min-width: 2rem;
    word-wrap: break-word;
    vertical-align: top;
}
.wiki-content-view th, .ql-editor th {
    background: var(--bg-secondary);
    font-weight: 600;
    text-align: left;
}
[data-theme="dark"] .wiki-content-view td, [data-theme="dark"] .wiki-content-view th,
[data-theme="dark"] .ql-editor td, [data-theme="dark"] .ql-editor th {
    border-color: var(--border-medium);
}
[data-theme="dark"] .wiki-content-view th, [data-theme="dark"] .ql-editor th {
    background: var(--bg-secondary);
}

/* quill-better-table operation menu dark mode */
[data-theme="dark"] .quill-better-table-operation-menu {
    background: var(--bg-card);
    border-color: var(--border);
    color: var(--text-primary);
}
[data-theme="dark"] .quill-better-table-operation-menu .operation-item:hover {
    background: var(--hover-bg);
}

/* ── Table picker popup ─────────────────────────────────────────────────────── */
.wiki-table-picker {
    position: fixed;
    z-index: 1100;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 0.5rem;
    box-shadow: var(--shadow-md);
}
.wiki-table-picker-label {
    text-align: center;
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-bottom: 0.4rem;
    font-weight: 600;
}
.wiki-table-picker-grid {
    display: grid;
    grid-template-columns: repeat(8, 18px);
    gap: 2px;
}
.wiki-table-picker-cell {
    width: 18px;
    height: 18px;
    border: 1px solid var(--border);
    border-radius: 2px;
    cursor: pointer;
    background: var(--bg-secondary);
    transition: background var(--transition), border-color var(--transition);
}
.wiki-table-picker-cell.active {
    background: var(--color-primary-light);
    border-color: var(--color-primary);
}

/* ql-table toolbar button label */
.ql-table::before { content: '⊞'; font-size: 1rem; }
```

**Step 2: Verify CSS is valid — open wiki, check no layout breaks**

**Step 3: Commit**

```bash
git add app/assets/css/main.css
git commit -m "feat: table styles + table picker popup CSS"
```

---

### Task 4 — PDF export: `export_print` PHP action + toolbar button

**Files:**
- Modify: `app/api/pages.php` — add new `elseif` branch before line 243's closing `}`
- Modify: `app/pages/wiki.php` — add PDF button in view mode toolbar

**Step 1: Add `export_print` action in `app/api/pages.php`**

Insert a new `elseif` block right after the closing `}` of the `search` action (after line 242, before line 243):

Current line 242–244:
```php
        echo json_encode(['success' => true, 'data' => $results]); exit;
    }
    exit;
```

Change to:
```php
        echo json_encode(['success' => true, 'data' => $results]); exit;
    } elseif ($method === 'GET' && $action === 'export_print') {
        $id   = (int)($_GET['id'] ?? 0);
        $page = get_page($id);
        if (!$page) {
            header('Content-Type: text/html; charset=utf-8');
            http_response_code(404);
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;"><h2>Página no encontrada</h2></body></html>';
            exit;
        }
        $title   = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
        $content = sanitize_html($page['content'] ?? '');
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill-better-table@1.2.10/dist/quill-better-table.css">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      margin: 0; padding: 2rem 2.5rem; color: #1d1d1f; line-height: 1.7;
      font-size: 0.9375rem;
    }
    h1.page-title {
      font-size: 1.6rem; font-weight: 700;
      margin: 0 0 1.5rem; padding-bottom: 0.75rem;
      border-bottom: 2px solid #e5e7eb;
    }
    .ql-editor { padding: 0; min-height: unset; border: none; font-size: inherit; line-height: inherit; }
    .ql-editor h1 { font-size: 1.4rem; } .ql-editor h2 { font-size: 1.2rem; } .ql-editor h3 { font-size: 1.05rem; }
    table { border-collapse: collapse; width: 100%; margin: 0.75rem 0; table-layout: fixed; }
    td, th { border: 1px solid #d1d5db; padding: 0.4rem 0.65rem; word-wrap: break-word; vertical-align: top; }
    th { background: #f3f4f6; font-weight: 600; text-align: left; }
    pre { background: #f3f4f6; padding: 0.75rem 1rem; border-radius: 6px; overflow-x: auto; }
    code { background: #f3f4f6; padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.875em; }
    pre code { background: none; padding: 0; }
    blockquote { border-left: 4px solid #e5e7eb; margin: 0; padding-left: 1rem; color: #6b7280; }
    img { max-width: 100%; height: auto; }
    .no-print { display: none; }
    @media print {
      body { padding: 0; }
      @page { margin: 1.5cm 2cm; }
    }
  </style>
</head>
<body>
  <h1 class="page-title">{$title}</h1>
  <div class="ql-editor">{$content}</div>
  <script>
    window.addEventListener('load', function() {
      window.print();
      window.addEventListener('afterprint', function() { window.close(); });
    });
  </script>
</body>
</html>
HTML;
        exit;
    }
    exit;
```

**Step 2: Add PDF button to the view mode toolbar in `wiki.php`**

In `wiki.php` lines 41–45, the view mode actions currently are:
```html
<div class="editor-actions flex items-center gap-2 mt-2">
    <button onclick="toggleHistory()" class="btn btn-secondary btn-sm">📋 Historial</button>
    <button onclick="showMoveModal()" class="btn btn-secondary btn-sm">📂 Mover</button>
    <button onclick="enterEditMode()" class="btn btn-primary btn-sm ml-auto">✏️ Editar</button>
</div>
```

Change to (add PDF button before Editar):
```html
<div class="editor-actions flex items-center gap-2 mt-2">
    <button onclick="toggleHistory()" class="btn btn-secondary btn-sm">📋 Historial</button>
    <button onclick="showMoveModal()" class="btn btn-secondary btn-sm">📂 Mover</button>
    <button onclick="exportPagePdf()" class="btn btn-secondary btn-sm">📄 PDF</button>
    <button onclick="enterEditMode()" class="btn btn-primary btn-sm ml-auto">✏️ Editar</button>
</div>
```

**Step 3: Verify with `php -l`**

```bash
php -l app/api/pages.php
php -l app/pages/wiki.php
```
Expected: `No syntax errors detected`

**Step 4: Manual test**
1. Open `http://localhost/teamapp/public?page=wiki`
2. Open a page → click "📄 PDF" → new tab opens → print dialog appears → save as PDF
3. Verify the page content, tables and images render correctly

**Step 5: Commit**

```bash
git add app/api/pages.php app/pages/wiki.php
git commit -m "feat: PDF export via export_print action + PDF button in wiki view toolbar"
```

---

## Verification checklist

- [ ] Tables can be inserted with the ⊞ toolbar button
- [ ] Row/col picker shows 8×8 grid, label updates on hover, click inserts table
- [ ] Right-click inside table cell shows operation menu in Spanish
- [ ] Add row / delete row / add column / delete column work
- [ ] Merge cells / unmerge cells work
- [ ] Table content saves and reloads correctly (sanitize_html preserves colgroup/col/colspan/rowspan)
- [ ] Tables display correctly in view mode (read-only div)
- [ ] Dark mode: table borders and header cells look correct
- [ ] PDF button opens new tab, print dialog fires automatically
- [ ] PDF output includes correct title, all content, tables rendered
- [ ] Tab key navigates between cells (QuillBetterTable.keyboardBindings)
