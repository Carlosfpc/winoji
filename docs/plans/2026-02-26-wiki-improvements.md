# Wiki Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the deprecated contenteditable editor with Quill.js, add image upload to disk, and add full-text search within wiki pages.

**Architecture:** Quill.js v1.3.7 via CDN replaces the manual execCommand toolbar; content storage stays HTML (no migration needed). Images upload via POST multipart to a new `upload_image` endpoint that saves files to `public/uploads/wiki/YYYY/MM/`. Full-text search runs LIKE queries on `pages.title` and `pages.content` from a new `search` endpoint.

**Tech Stack:** Quill.js 1.3.7 (CDN), PHP `$_FILES` upload, MySQL LIKE search

**Context — Key files:**
- `app/pages/wiki.php` — HTML shell: sidebar (tabs + page tree) + editor area + modals
- `app/assets/js/wiki.js` — all wiki JS (~567 lines): tree, CRUD, save, history, mentions
- `app/api/pages.php` — REST API: list/get/create/update/delete/move/versions
- `app/includes/auth.php` — `verify_csrf()` reads `$_SERVER['HTTP_X_CSRF_TOKEN']` header
- `app/includes/layout_top.php` — shared HTML head; DOMPurify loaded globally
- `app/assets/css/main.css` — all styles; wiki styles at lines ~732-810

**No test suite** — skip test steps. Commit to master after each task.

---

### Task 1: layout_top.php — `$extra_head` support

**Files:**
- Modify: `app/includes/layout_top.php` (line 20, after the DOMPurify script tag)

**Step 1: Add `$extra_head` output to layout_top**

In `layout_top.php`, find line 20:
```html
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
```

Add immediately after it (line 21):
```php
    <?= $extra_head ?? '' ?>
```

**Step 2: Verify syntax**

```bash
php -l app/includes/layout_top.php
```
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add app/includes/layout_top.php
git commit -m "feat: add \$extra_head injection point to layout_top"
```

---

### Task 2: pages.php — sanitize_html + upload_image + search

**Files:**
- Modify: `app/api/pages.php`

**Step 1: Update `sanitize_html()` to allow `<img>` and table tags**

Current (line 5):
```php
    $allowed = '<p><h1><h2><h3><ul><ol><li><strong><em><code><pre><a><br><blockquote><u><span><div>';
```

Replace with:
```php
    $allowed = '<p><h1><h2><h3><ul><ol><li><strong><em><code><pre><a><br><blockquote><u><span><div><img><table><thead><tbody><tfoot><tr><th><td>';
```

Also add after the existing `href` sanitization (line 9):
```php
    // Remove javascript: in src attributes (images)
    $clean = preg_replace('/\s+src\s*=\s*(?:"javascript:[^"]*"|\'javascript:[^\']*\')/i', '', $clean);
```

**Step 2: Add `upload_image` and `search` actions to the HTTP routing block**

In the HTTP routing block (after line 179, before `exit;`), add these two new `elseif` branches:

```php
    } elseif ($method === 'POST' && $action === 'upload_image') {
        verify_csrf();
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'No se recibió la imagen']); exit;
        }
        $file = $_FILES['image'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            echo json_encode(['success' => false, 'error' => 'Tipo de archivo no permitido']); exit;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'La imagen supera el límite de 5 MB']); exit;
        }
        $extMap  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext     = $extMap[$mime];
        $year    = date('Y'); $month = date('m');
        $dir     = __DIR__ . '/../../public/uploads/wiki/' . $year . '/' . $month;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = uniqid('wiki_', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            echo json_encode(['success' => false, 'error' => 'No se pudo guardar la imagen']); exit;
        }
        $url = APP_URL . '/public/uploads/wiki/' . $year . '/' . $month . '/' . $filename;
        echo json_encode(['success' => true, 'url' => $url]);
    } elseif ($method === 'GET' && $action === 'search') {
        $q   = trim($_GET['q'] ?? '');
        $pid = isset($_GET['project_id']) && (int)$_GET['project_id'] > 0 ? (int)$_GET['project_id'] : null;
        if (strlen($q) < 2) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $pdo  = get_db();
        $like = '%' . $q . '%';
        if ($pid) {
            $stmt = $pdo->prepare(
                'SELECT id, title,
                        SUBSTRING(content, GREATEST(1, LOCATE(?, content) - 80), 200) AS excerpt
                 FROM pages
                 WHERE project_id = ? AND scope = ?
                   AND (title LIKE ? OR content LIKE ?)
                 ORDER BY (title LIKE ?) DESC, updated_at DESC
                 LIMIT 15'
            );
            $stmt->execute([$q, $pid, 'project', $like, $like, $like]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, title,
                        SUBSTRING(content, GREATEST(1, LOCATE(?, content) - 80), 200) AS excerpt
                 FROM pages
                 WHERE scope = ?
                   AND (title LIKE ? OR content LIKE ?)
                 ORDER BY (title LIKE ?) DESC, updated_at DESC
                 LIMIT 15'
            );
            $stmt->execute([$q, 'general', $like, $like, $like]);
        }
        $results = $stmt->fetchAll();
        foreach ($results as &$r) {
            $r['excerpt'] = strip_tags($r['excerpt'] ?? '');
        }
        echo json_encode(['success' => true, 'data' => $results]);
```

**Step 3: Add `public/uploads/` to `.gitignore`**

Check if `.gitignore` exists at project root:
```bash
cat .gitignore 2>/dev/null || echo "(no .gitignore)"
```

Add the line (edit manually or append):
```
public/uploads/
```

**Step 4: Verify PHP syntax**

```bash
php -l app/api/pages.php
```
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add app/api/pages.php .gitignore
git commit -m "feat: pages API — allow img/table in sanitize_html, add upload_image and search endpoints"
```

---

### Task 3: wiki.php — Quill CDN + swap editor HTML + search UI

**Files:**
- Modify: `app/pages/wiki.php`

**Step 1: Set `$extra_head` before including layout_top**

Replace the first two lines:
```php
<?php
$page_title = 'Wiki';
require __DIR__ . '/../includes/layout_top.php';
```

With:
```php
<?php
$page_title = 'Wiki';
$extra_head = '
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.js"></script>
';
require __DIR__ . '/../includes/layout_top.php';
```

**Step 2: Add search input above `<div id="pages-list">`**

Replace:
```html
        <div id="pages-list"></div>
```

With:
```html
        <div class="wiki-search-wrap">
            <input type="text" id="wiki-search-input" class="form-input" placeholder="Buscar en wiki..." autocomplete="off">
        </div>
        <div id="wiki-search-results" class="hidden"></div>
        <div id="pages-list"></div>
```

**Step 3: Replace the editor toolbar + contenteditable with Quill containers**

Replace this entire block:
```html
            <div class="editor-toolbar">
                <button onclick="execCmd('bold')"><b>N</b></button>
                <button onclick="execCmd('italic')"><i>C</i></button>
                <button onclick="execCmd('underline')"><u>S</u></button>
                <button onclick="execCmd('insertUnorderedList')">• Lista</button>
                <button onclick="insertCode()">{ } Código</button>
                <button onclick="toggleHistory()">📋 Historial</button>
                <button id="move-page-btn" onclick="showMoveModal()">📂 Mover</button>
                <button onclick="savePage()" id="save-btn" class="btn btn-primary btn-xs ml-auto">
                    💾 Guardar
                </button>
            </div>
            <div id="page-content" contenteditable="true" class="editor-content"></div>
            <div id="save-status" class="text-xs text-muted mt-2"></div>
```

With:
```html
            <div id="quill-wrap">
                <div id="quill-editor"></div>
            </div>
            <div class="editor-actions flex items-center gap-2 mt-2">
                <button onclick="toggleHistory()" class="btn btn-secondary btn-sm">📋 Historial</button>
                <button id="move-page-btn" onclick="showMoveModal()" class="btn btn-secondary btn-sm">📂 Mover</button>
                <button onclick="savePage()" id="save-btn" class="btn btn-primary btn-sm ml-auto">💾 Guardar</button>
            </div>
            <div id="save-status" class="text-xs text-muted mt-1"></div>
```

**Step 4: Verify PHP syntax**

```bash
php -l app/pages/wiki.php
```
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add app/pages/wiki.php
git commit -m "feat: wiki.php — Quill CDN, swap editor containers, add search UI"
```

---

### Task 4: wiki.js — Quill core: init, load, save, autosave, history restore, image upload

**Files:**
- Modify: `app/assets/js/wiki.js`

**Step 1: Add `quill` state variable**

At the top of wiki.js, after line 6 (`const expandedPages = new Set();`), add:
```js
let quill = null;       // Quill editor instance
```

**Step 2: Add `initQuill()` function**

Add this function after the `escapeHtml` helper (after line 14):
```js
// ── Quill init ────────────────────────────────────────────────────────────────
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
                    ['link', 'image'],
                    ['clean']
                ],
                handlers: { image: imageUploadHandler }
            },
            history: { delay: 500, maxStack: 100 }
        }
    });
    quill.on('text-change', scheduleTypingSave);
}
```

**Step 3: Add `imageUploadHandler()` function**

Add immediately after `initQuill()`:
```js
function imageUploadHandler() {
    const input = document.createElement('input');
    input.type  = 'file';
    input.accept = 'image/jpeg,image/png,image/gif,image/webp';
    input.onchange = async () => {
        const file = input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('image', file);
        try {
            const res  = await fetch(`${APP_URL}/app/api/pages.php?action=upload_image`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: fd
            });
            const json = await res.json();
            if (json.success) {
                const range = quill.getSelection(true);
                quill.insertEmbed(range.index, 'image', json.url, Quill.sources.USER);
                quill.setSelection(range.index + 1, Quill.sources.SILENT);
            } else {
                showToast(json.error || 'Error al subir imagen', 'error');
            }
        } catch(e) {
            showToast('Error al subir imagen', 'error');
        }
    };
    input.click();
}
```

**Step 4: Update `loadPage()` to use Quill**

Find `loadPage()` (lines 256-271). Replace:
```js
    document.getElementById('page-content').innerHTML = DOMPurify.sanitize(data.data.content || '');
    document.getElementById('editor-placeholder').classList.add('hidden');
    document.getElementById('editor-area').classList.remove('hidden');
    document.getElementById('save-status').textContent = '';
    scheduleSave();
    document.getElementById('page-content').oninput = scheduleTypingSave;
    document.getElementById('page-title').oninput  = scheduleTypingSave;
    updateBreadcrumb(id);
    rerenderTree(); // refresh active state
```

With:
```js
    quill.root.innerHTML = DOMPurify.sanitize(data.data.content || '');
    document.getElementById('editor-placeholder').classList.add('hidden');
    document.getElementById('editor-area').classList.remove('hidden');
    document.getElementById('save-status').textContent = '';
    scheduleSave();
    document.getElementById('page-title').oninput = scheduleTypingSave;
    updateBreadcrumb(id);
    rerenderTree(); // refresh active state
```

**Step 5: Update `savePage()` to read from Quill**

Find `savePage()` (lines 284-296). Replace:
```js
    const content = document.getElementById('page-content').innerHTML;
```

With:
```js
    const content = quill ? quill.root.innerHTML : '';
```

**Step 6: Update `restoreVersion()` to use Quill**

Find `restoreVersion()` (lines 335-345). Replace:
```js
        document.getElementById('page-content').innerHTML = DOMPurify.sanitize(data.data.content || '');
```

With:
```js
        quill.root.innerHTML = DOMPurify.sanitize(data.data.content || '');
```

**Step 7: Remove `execCmd()` and `insertCode()`**

Delete these two lines (around line 298-299):
```js
function execCmd(cmd) { document.execCommand(cmd, false, null); }
function insertCode() { document.execCommand('insertHTML', false, '<code>código aquí</code>'); }
```

**Step 8: Call `initQuill()` at bottom of file**

The current bottom of wiki.js (lines 561-567):
```js
Promise.all([loadPagesList(), loadWikiTeamMembers()]).then(() => {
    const params     = new URLSearchParams(window.location.search);
    const openPageId = parseInt(params.get('open_page') || '0');
    if (openPageId) loadPage(openPageId);
    initMentionAutocomplete();
});
```

Replace with:
```js
initQuill();
Promise.all([loadPagesList(), loadWikiTeamMembers()]).then(() => {
    const params     = new URLSearchParams(window.location.search);
    const openPageId = parseInt(params.get('open_page') || '0');
    if (openPageId) loadPage(openPageId);
    initMentionAutocomplete();
    initWikiSearch();
});
```

(`initWikiSearch` is added in Task 6.)

**Step 9: Verify**

```bash
php -l app/assets/js/wiki.js 2>&1 || echo "JS file — no PHP lint needed"
```

Visit `http://localhost/teamapp/public/?page=wiki` and confirm:
- Quill editor appears with snow toolbar
- Can type in the editor
- Can load a page
- Save button works

**Step 10: Commit**

```bash
git add app/assets/js/wiki.js
git commit -m "feat: wiki.js — replace execCommand editor with Quill, add imageUploadHandler"
```

---

### Task 5: wiki.js — update mentions for Quill API

**Files:**
- Modify: `app/assets/js/wiki.js`

The mention system uses `document.getElementById('page-content')` for events and `document.execCommand('insertHTML')` for insertion. Both need to work with Quill instead.

**Step 1: Update `getTextBeforeCursor()` to use Quill**

Find (lines 394-403):
```js
function getTextBeforeCursor() {
    const sel = window.getSelection();
    if (!sel.rangeCount) return '';
    const range  = sel.getRangeAt(0).cloneRange();
    const editor = document.getElementById('page-content');
    if (!editor) return '';
    range.setStart(editor, 0);
    return range.toString();
}
```

Replace with:
```js
function getTextBeforeCursor() {
    if (!quill) return '';
    const sel = quill.getSelection();
    if (!sel) return '';
    return quill.getText(0, sel.index);
}
```

**Step 2: Update `insertWikiMention()` to use Quill API**

Find (lines 447-471):
```js
function insertWikiMention(item) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) { closeMentionDropdown(); return; }

    const range      = sel.getRangeAt(0).cloneRange();
    const triggerLen = wikiMentionTrigger === 'person' ? 1 : 2; // '@' or '[['
    const charsBack  = triggerLen + wikiMentionQuery.length;
    const node       = range.startContainer;
    const offset     = range.startOffset;

    // If trigger+query spans a node boundary (rare), range is not adjusted.
    if (node.nodeType === Node.TEXT_NODE && offset >= charsBack) {
        range.setStart(node, offset - charsBack);
    }

    sel.removeAllRanges();
    sel.addRange(range);

    const html = wikiMentionTrigger === 'person'
        ? `<span class="wiki-mention-person" data-user-id="${item.id}">@${escapeHtml(item.label)}</span>&nbsp;`
        : `<a href="${APP_URL}?page=wiki&open_page=${item.id}" class="wiki-mention" data-page-id="${item.id}">${escapeHtml(item.label)}</a>&nbsp;`;

    document.execCommand('insertHTML', false, html);
    closeMentionDropdown();
}
```

Replace with:
```js
function insertWikiMention(item) {
    if (!quill) { closeMentionDropdown(); return; }
    const sel = quill.getSelection();
    if (!sel) { closeMentionDropdown(); return; }

    const triggerLen  = wikiMentionTrigger === 'person' ? 1 : 2;
    const charsBack   = triggerLen + wikiMentionQuery.length;
    const insertIndex = Math.max(0, sel.index - charsBack);

    const html = wikiMentionTrigger === 'person'
        ? `<span class="wiki-mention-person" data-user-id="${item.id}">@${escapeHtml(item.label)}</span>&nbsp;`
        : `<a href="${APP_URL}?page=wiki&open_page=${item.id}" class="wiki-mention" data-page-id="${item.id}">${escapeHtml(item.label)}</a>&nbsp;`;

    quill.deleteText(insertIndex, charsBack, Quill.sources.USER);
    quill.clipboard.dangerouslyPasteHTML(insertIndex, html, Quill.sources.USER);
    closeMentionDropdown();
}
```

**Step 3: Update `initMentionAutocomplete()` to use Quill**

Find (lines 473-558). Replace the entire function:
```js
function initMentionAutocomplete() {
    const dropdown = document.getElementById('wiki-mention-dropdown');
    if (!dropdown || !quill) return;

    // Prevent editor from losing focus when clicking dropdown
    dropdown.addEventListener('mousedown', e => e.preventDefault());

    // Navigate wiki links and @mentions inside the editor
    quill.root.addEventListener('click', e => {
        const pageLink = e.target.closest('a.wiki-mention');
        if (pageLink) {
            e.preventDefault();
            const pid = parseInt(pageLink.dataset.pageId);
            if (pid) loadPage(pid);
            return;
        }
        const personMention = e.target.closest('.wiki-mention-person');
        if (personMention) {
            const uid = parseInt(personMention.dataset.userId || '0');
            window.location.href = uid
                ? `${APP_URL}?page=user_profile&id=${uid}`
                : `${APP_URL}?page=team`;
        }
    });

    // Trigger detection on text-change
    quill.on('text-change', () => {
        const before = getTextBeforeCursor();

        // [[ → páginas
        const pageMatch = before.match(/\[\[([^\[\]]{0,50})$/);
        if (pageMatch) {
            wikiMentionTrigger = 'page';
            wikiMentionQuery   = pageMatch[1];
            const q       = pageMatch[1].toLowerCase();
            const results = pagesFlat.filter(p => p.title.toLowerCase().includes(q)).slice(0, 8);
            renderWikiMentionDropdown(results);
            return;
        }

        // @ → personas
        const personMatch = before.match(/@(\w{0,30})$/);
        if (personMatch) {
            wikiMentionTrigger = 'person';
            wikiMentionQuery   = personMatch[1];
            const q       = personMatch[1].toLowerCase();
            const results = wikiTeamCache.filter(m => m.name.toLowerCase().includes(q)).slice(0, 8);
            renderWikiMentionDropdown(results);
            return;
        }

        closeMentionDropdown();
    });

    dropdown.addEventListener('click', e => {
        const item = e.target.closest('.wiki-mention-item');
        if (!item) return;
        insertWikiMention({ id: parseInt(item.dataset.id), label: item.dataset.label });
    });

    quill.root.addEventListener('keydown', e => {
        if (!dropdown || dropdown.style.display === 'none') return;
        const items  = dropdown.querySelectorAll('.wiki-mention-item');
        const active = dropdown.querySelector('.wiki-mention-item.mention-active');
        const idx    = Array.from(items).indexOf(active);
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (active) active.classList.remove('mention-active');
            items[Math.min(idx + 1, items.length - 1)]?.classList.add('mention-active');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (active) active.classList.remove('mention-active');
            items[Math.max(idx - 1, 0)]?.classList.add('mention-active');
        } else if (e.key === 'Enter' && active) {
            e.preventDefault();
            active.click();
        } else if (e.key === 'Escape') {
            closeMentionDropdown();
        }
    });

    document.addEventListener('click', e => {
        if (!dropdown.contains(e.target) && !quill.root.contains(e.target)) {
            closeMentionDropdown();
        }
    });
}
```

**Step 4: Update `renderWikiMentionDropdown()` positioning**

The function uses `window.getSelection()` to position the dropdown. With Quill this still works since the editor is still contenteditable inside `.ql-editor`. No change needed here.

**Step 5: Verify**

Visit `http://localhost/teamapp/public/?page=wiki`, load a page, type `[[` and verify the page mention dropdown appears. Type `@` and verify person dropdown appears.

**Step 6: Commit**

```bash
git add app/assets/js/wiki.js
git commit -m "feat: wiki.js — update mention autocomplete to use Quill API"
```

---

### Task 6: wiki.js — full-text search

**Files:**
- Modify: `app/assets/js/wiki.js`

**Step 1: Add search state variable**

At the top, after `let quill = null;`, add:
```js
let searchDebounceTimer = null;
```

**Step 2: Add search functions**

Add these functions at the end of wiki.js, before the `initQuill()` call:

```js
// ── Wiki search ───────────────────────────────────────────────────────────────
function initWikiSearch() {
    const input      = document.getElementById('wiki-search-input');
    const resultsEl  = document.getElementById('wiki-search-results');
    if (!input || !resultsEl) return;

    // Delegated click handler — attach once
    resultsEl.addEventListener('click', e => {
        const row = e.target.closest('.wiki-search-result');
        if (!row) return;
        loadPage(parseInt(row.dataset.id));
        input.value = '';
        clearSearch();
    });

    input.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        const q = input.value.trim();
        if (q.length < 2) { clearSearch(); return; }
        searchDebounceTimer = setTimeout(() => searchWikiPages(q), 300);
    });

    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { input.value = ''; clearSearch(); }
    });
}

async function searchWikiPages(q) {
    let url = `${APP_URL}/app/api/pages.php?action=search&q=${encodeURIComponent(q)}`;
    if (currentScope === 'project' && PROJECT_ID) url += `&project_id=${PROJECT_ID}`;
    try {
        const res  = await fetch(url);
        const data = await res.json();
        renderSearchResults(data.data || [], q);
    } catch(e) {
        renderSearchResults([], q);
    }
}

function highlightQuery(text, q) {
    if (!text || !q) return escapeHtml(text || '');
    const safe    = escapeHtml(text);
    const safeQ   = escapeHtml(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return safe.replace(new RegExp('(' + safeQ + ')', 'gi'), '<mark>$1</mark>');
}

function renderSearchResults(results, q) {
    const pagesListEl = document.getElementById('pages-list');
    const resultsEl   = document.getElementById('wiki-search-results');

    pagesListEl.classList.add('hidden');
    resultsEl.classList.remove('hidden');

    if (!results.length) {
        resultsEl.innerHTML = `<div class="empty-state">Sin resultados para "<em>${escapeHtml(q)}</em>"</div>`;
        return;
    }
    resultsEl.innerHTML = results.map(r => `
        <div class="wiki-search-result" data-id="${r.id}">
            <div class="wiki-search-result-title">${highlightQuery(r.title, q)}</div>
            ${r.excerpt ? `<div class="wiki-search-result-excerpt">${highlightQuery(r.excerpt, q)}</div>` : ''}
        </div>
    `).join('');
}

function clearSearch() {
    const pagesListEl = document.getElementById('pages-list');
    const resultsEl   = document.getElementById('wiki-search-results');
    pagesListEl.classList.remove('hidden');
    resultsEl.classList.add('hidden');
    resultsEl.innerHTML = '';
}
```

**Step 3: Also call clearSearch when switching tabs**

In `switchTab()` (around line 348), add `clearSearch();` after `expandedPages.clear();`:
```js
function switchTab(scope) {
    currentScope  = scope;
    currentPageId = null;
    expandedPages.clear();
    clearSearch();
    document.getElementById('wiki-search-input').value = '';
    ...
```

**Step 4: Verify**

Visit `http://localhost/teamapp/public/?page=wiki`, type at least 2 characters in the search box, and confirm search results appear with highlighted matches.

**Step 5: Commit**

```bash
git add app/assets/js/wiki.js
git commit -m "feat: wiki.js — full-text wiki search with debounce and highlight"
```

---

### Task 7: main.css — Quill theme overrides + search styles

**Files:**
- Modify: `app/assets/css/main.css`

Add the following CSS block at the end of main.css (before the `@media` queries section, or right before end of file):

**Step 1: Add Quill theme overrides + search styles**

```css
/* ── Quill editor integration ──────────────────────────────────────────────── */
#quill-wrap {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.ql-toolbar.ql-snow {
    border: none;
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
    padding: 0.4rem 0.6rem;
    flex-wrap: wrap;
}
.ql-container.ql-snow {
    border: none;
    font-family: inherit;
    font-size: 0.9375rem;
}
.ql-editor {
    min-height: 400px;
    line-height: 1.7;
    color: var(--text-primary);
    padding: 1rem 1.25rem;
}
.ql-editor.ql-blank::before {
    color: var(--text-tertiary);
    font-style: normal;
}
.ql-snow .ql-stroke { stroke: var(--text-secondary); }
.ql-snow .ql-fill  { fill:   var(--text-secondary); }
.ql-snow .ql-picker-label { color: var(--text-secondary); }
.ql-snow .ql-picker-options {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
}
.ql-snow .ql-picker-item:hover,
.ql-snow .ql-picker-item.ql-selected { color: var(--color-primary); }
.ql-toolbar.ql-snow .ql-formats button:hover .ql-stroke,
.ql-toolbar.ql-snow .ql-formats button.ql-active .ql-stroke { stroke: var(--color-primary); }
.ql-toolbar.ql-snow .ql-formats button:hover .ql-fill,
.ql-toolbar.ql-snow .ql-formats button.ql-active .ql-fill  { fill:   var(--color-primary); }

/* Dark mode overrides for Quill */
[data-theme="dark"] .ql-toolbar.ql-snow { background: var(--bg-secondary); border-bottom-color: var(--border); }
[data-theme="dark"] .ql-snow .ql-stroke  { stroke: var(--text-secondary); }
[data-theme="dark"] .ql-snow .ql-fill    { fill:   var(--text-secondary); }
[data-theme="dark"] .ql-snow .ql-picker-label { color: var(--text-secondary); }
[data-theme="dark"] .ql-snow .ql-picker-options { background: var(--bg-card); border-color: var(--border); }
[data-theme="dark"] .ql-editor { color: var(--text-primary); }
[data-theme="dark"] .ql-editor blockquote { border-left-color: var(--border-medium); color: var(--text-secondary); }
[data-theme="dark"] .ql-editor code, [data-theme="dark"] .ql-editor pre { background: var(--bg-secondary); color: var(--text-primary); }
[data-theme="dark"] #quill-wrap { border-color: var(--border); }

.editor-actions { display: flex; align-items: center; gap: 0.5rem; }

/* ── Wiki search ────────────────────────────────────────────────────────────── */
.wiki-search-wrap { padding: 0.5rem 0.75rem 0.25rem; }
.wiki-search-wrap .form-input { font-size: 0.8rem; padding: 0.35rem 0.6rem; }
.wiki-search-result {
    padding: 0.55rem 0.75rem;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: background var(--transition);
}
.wiki-search-result:last-child { border-bottom: none; }
.wiki-search-result:hover { background: var(--hover-bg); }
.wiki-search-result-title { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
.wiki-search-result-excerpt {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.1rem;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.wiki-search-result mark {
    background: rgba(99,102,241,0.15);
    color: var(--color-primary);
    border-radius: 2px;
    padding: 0 1px;
    font-style: normal;
}
#wiki-search-results { overflow-y: auto; max-height: calc(100vh - 180px); }
```

**Step 2: Verify**

Visit `http://localhost/teamapp/public/?page=wiki` — the Quill editor should look styled (matches app theme, no jarring white background in dark mode).

**Step 3: Commit**

```bash
git add app/assets/css/main.css
git commit -m "feat: main.css — Quill snow theme overrides + wiki search result styles"
```

---

## Verification Checklist

After all tasks are committed:

1. `http://localhost/teamapp/public/?page=wiki`
2. Load a page — Quill shows content correctly
3. Edit text with bold/italic/headers — toolbar works
4. Upload an image via toolbar image button — image appears inline
5. Toggle dark mode — Quill toolbar and editor adapt
6. Type `[[` — page mention dropdown appears
7. Type `@name` — person mention dropdown appears
8. Type in search box — results appear with highlighted text
9. Click a search result — page loads and search clears
10. Switch tabs — search clears and tree reloads
11. Save button / auto-save after 2s of no typing — "Guardado ✓" appears
12. Open history panel — versions listed; restore a version — content loads in Quill
