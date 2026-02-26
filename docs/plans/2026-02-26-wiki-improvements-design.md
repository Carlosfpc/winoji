# Wiki Improvements Design

**Date:** 2026-02-26

## Goal

Replace the deprecated `contenteditable`/`execCommand` editor with Quill.js, add image upload to disk, and add full-text search within wiki pages.

## Architecture

Three independent improvements layered on the existing wiki:

1. **Editor** — swap `contenteditable` div for a Quill instance; storage format stays HTML, so all existing pages and version history remain compatible.
2. **Image upload** — new API endpoint saves files to `public/uploads/wiki/`; Quill's image button calls this endpoint and inserts `<img src="...">` at cursor.
3. **Search** — new API action does `LIKE` on both `title` and `content`; results shown inline in sidebar with text excerpt.

## Tech Stack

- **Quill.js 2.x** via CDN (`https://cdn.jsdelivr.net/npm/quill@2/dist/`)
- **PHP file upload** — native `$_FILES`, `move_uploaded_file()`
- **MySQL LIKE** search on `pages.title` and `pages.content`

---

## Section 1 — Quill Editor

### What changes

Replace in `app/pages/wiki.php`:
- Remove `<div id="page-editor" contenteditable>` and the manual toolbar HTML (bold/italic/underline/list/code/history/move/save buttons above it)
- Add Quill CDN links (CSS + JS) in `<head>`
- Add two containers:
  ```html
  <div id="quill-toolbar-container"></div>
  <div id="quill-editor-container"></div>
  ```

### Toolbar modules

| Group | Buttons |
|-------|---------|
| Headers | H1, H2, H3 |
| Inline | Bold, Italic, Underline, Strike |
| Block | Blockquote, Code-block |
| Lists | Ordered, Bullet |
| Insert | Link, Image, Table (via quill-better-table plugin) |
| History | Undo, Redo |

### Initialization (`wiki.js`)

```js
const quill = new Quill('#quill-editor-container', {
  theme: 'snow',
  modules: {
    toolbar: { container: '#quill-toolbar-container', handlers: { image: imageUploadHandler } },
    history: { delay: 500, maxStack: 100 }
  }
});
```

### Load / Save

- **Load**: `quill.root.innerHTML = page.content ?? ''`
- **Save**: body sent to API: `{ ..., content: quill.root.innerHTML }`
- **Auto-save**: debounced `on('text-change')` event (replaces `input` listener)
- Existing `savePage()` function updated to read from quill instance

### History / version restore

- Version panel and restore flow stay the same — version content is HTML and loads into `quill.root.innerHTML`

---

## Section 2 — Image Upload to Disk

### Upload endpoint

`app/api/pages.php?action=upload_image` — POST multipart/form-data, CSRF required

**Validation:**
- `$_FILES['image']` must be present, no upload errors
- MIME check: `image/jpeg`, `image/png`, `image/gif`, `image/webp` only
- Max size: 5 MB (5 * 1024 * 1024 bytes)

**Storage path:**
```
public/uploads/wiki/{YYYY}/{MM}/{uniqid()}.{ext}
```
Directories created with `mkdir(..., 0755, true)` if missing.

**Response:**
```json
{ "success": true, "url": "/teamapp/public/uploads/wiki/2026/02/abc123.jpg" }
```

### Quill image handler (`wiki.js`)

```js
function imageUploadHandler() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.onchange = async () => {
    const file = input.files[0];
    const fd = new FormData();
    fd.append('image', file);
    const res = await fetchWithCsrf('/teamapp/public/api/pages.php?action=upload_image', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) {
      const range = quill.getSelection(true);
      quill.insertEmbed(range.index, 'image', json.url, Quill.sources.USER);
    }
  };
  input.click();
}
```

### .gitignore

Add `public/uploads/` to `.gitignore`.

---

## Section 3 — Full-Text Search

### UI (wiki sidebar)

Above the page tree, add:
```html
<div class="wiki-search-wrap">
  <input type="text" id="wiki-search-input" placeholder="Buscar en wiki..." class="form-input" />
  <div id="wiki-search-results"></div>
</div>
```

- Debounce: 300ms
- Min query length: 2 characters
- On empty → hide results, show normal tree
- Results replace the tree while search is active; clicking outside or clearing the field restores the tree

### Search endpoint

`app/api/pages.php?action=search&q=TEXTO&project_id=N` — GET, auth required

```sql
SELECT id, title,
  SUBSTRING(content, GREATEST(1, LOCATE(?, content) - 60), 200) AS excerpt
FROM pages
WHERE project_id = ?
  AND (title LIKE ? OR content LIKE ?)
ORDER BY (title LIKE ?) DESC, created_at DESC
LIMIT 15
```

- Strips HTML from excerpt client-side before display (or via `strip_tags` in PHP)
- Highlights the query term in both title and excerpt using `<mark>` tags (done in JS with `escapeHtml` + replace)

### Result item HTML (rendered in JS)

```html
<div class="wiki-search-result" data-id="{id}">
  <div class="wiki-search-title">{highlighted title}</div>
  <div class="wiki-search-excerpt">{highlighted excerpt stripped of HTML}</div>
</div>
```

Clicking a result calls existing `loadPage(id)` and clears the search.

---

## Files to Modify / Create

| File | Change |
|------|--------|
| `app/pages/wiki.php` | Add Quill CDN, replace editor div + toolbar, add search input |
| `app/assets/js/wiki.js` | Replace editor init/save/load with Quill API; add imageUploadHandler; add search debounce + render |
| `app/api/pages.php` | Add `upload_image` action; add `search` action |
| `.gitignore` | Add `public/uploads/` |
| `public/uploads/wiki/` | Created at runtime by PHP |

## Out of Scope

- Table support (complex Quill plugin; deferred to future iteration)
- PDF export
- Page templates
- Drag-and-drop page reorder in tree (separate feature)
