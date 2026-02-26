# Wiki Page Mentions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Añadir autocomplete `[[título]]` en el editor de wiki para insertar enlaces a otras páginas.

**Architecture:** Solo se modifican `app/pages/wiki.php` (añadir `<div>` del dropdown + estilos) y `app/assets/js/wiki.js` (funciones de autocomplete). Al escribir `[[` en el `contenteditable`, se filtra `pagesFlat` (ya disponible en el módulo) y se muestra un dropdown flotante; al seleccionar, se borra el texto `[[query` y se inserta un `<a class="wiki-mention">` con el ID y título de la página.

**Tech Stack:** Vanilla JS · DOM Selection/Range API · `document.execCommand('insertHTML')` · `pagesFlat` (estado de módulo existente en wiki.js)

---

### Task 1: Dropdown HTML + estilos en wiki.php

**Files:**
- Modify: `app/pages/wiki.php`

---

**Step 1: Añadir el `<div>` del dropdown antes del bloque `<script>` al final de wiki.php**

En `app/pages/wiki.php`, reemplaza la línea:
```php
<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
</script>
```

Con:
```php
<!-- Wiki mention dropdown -->
<div id="wiki-mention-dropdown"
     style="display:none;position:fixed;z-index:9999;
            background:var(--bg-card);border:1px solid var(--border);
            border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);
            min-width:220px;max-width:320px;max-height:240px;overflow-y:auto;">
</div>

<style>
.wiki-mention { color:#4f46e5; text-decoration:underline; cursor:pointer; }
.wiki-mention-item:hover,
.wiki-mention-item.mention-active { background:var(--hover-bg,#f3f4f6); }
</style>

<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
</script>
```

---

**Step 2: Verificar PHP syntax**

```bash
php -l /c/Users/carlo/proyects/claude-skills/app/pages/wiki.php
```
Expected: `No syntax errors detected`

---

**Step 3: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add app/pages/wiki.php
git commit -m "feat: add wiki mention dropdown container and styles"
```

---

### Task 2: Lógica de autocomplete en wiki.js

**Files:**
- Modify: `app/assets/js/wiki.js`

---

**Step 1: Añadir las funciones de mention al final de wiki.js, antes del bloque `// ── Init`**

En `app/assets/js/wiki.js`, reemplaza la línea:
```js
// ── Init ──────────────────────────────────────────────────────────────────────
```

Con:
```js
// ── Wiki page mentions ([[title]] autocomplete) ───────────────────────────────

let wikiMentionQuery = '';

/** Texto completo desde el inicio del editor hasta la posición del cursor. */
function getTextBeforeCursor() {
    const sel = window.getSelection();
    if (!sel.rangeCount) return '';
    const range = sel.getRangeAt(0).cloneRange();
    const editor = document.getElementById('page-content');
    if (!editor) return '';
    range.setStart(editor, 0);
    return range.toString();
}

function closeMentionDropdown() {
    const d = document.getElementById('wiki-mention-dropdown');
    if (d) d.style.display = 'none';
}

function renderWikiMentionDropdown(results) {
    const dropdown = document.getElementById('wiki-mention-dropdown');
    if (!dropdown) return;

    if (!results.length) {
        dropdown.innerHTML = '<div style="padding:0.5rem 0.75rem;color:var(--text-secondary);font-size:0.875rem;">Sin páginas encontradas</div>';
    } else {
        dropdown.innerHTML = results.map(p =>
            `<div class="wiki-mention-item"
                  data-id="${p.id}"
                  data-title="${escapeHtml(p.title)}"
                  style="padding:0.5rem 0.75rem;cursor:pointer;font-size:0.875rem;
                         white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                ${escapeHtml(p.title)}
            </div>`
        ).join('');
    }

    // Posicionar el dropdown debajo del cursor
    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
        const rect = sel.getRangeAt(0).getBoundingClientRect();
        dropdown.style.top  = (rect.bottom + 4) + 'px';
        dropdown.style.left = Math.min(rect.left, window.innerWidth - 330) + 'px';
    }
    dropdown.style.display = 'block';
}

function insertWikiMentionLink(page) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) { closeMentionDropdown(); return; }

    const range     = sel.getRangeAt(0).cloneRange();
    const charsBack = 2 + wikiMentionQuery.length; // "[[" + texto escrito
    const node      = range.startContainer;
    const offset    = range.startOffset;

    if (node.nodeType === Node.TEXT_NODE && offset >= charsBack) {
        range.setStart(node, offset - charsBack);
    }

    sel.removeAllRanges();
    sel.addRange(range);

    const link = `<a href="${APP_URL}?page=wiki&open_page=${page.id}" class="wiki-mention">${escapeHtml(page.title)}</a>&nbsp;`;
    document.execCommand('insertHTML', false, link);
    closeMentionDropdown();
}

function initMentionAutocomplete() {
    const editor   = document.getElementById('page-content');
    const dropdown = document.getElementById('wiki-mention-dropdown');
    if (!editor || !dropdown) return;

    editor.addEventListener('input', () => {
        const before = getTextBeforeCursor();
        const match  = before.match(/\[\[([^\[\]]{0,50})$/);
        if (!match) { closeMentionDropdown(); return; }
        wikiMentionQuery = match[1];
        const query   = match[1].toLowerCase();
        const results = pagesFlat.filter(p => p.title.toLowerCase().includes(query)).slice(0, 8);
        renderWikiMentionDropdown(results);
    });

    dropdown.addEventListener('click', e => {
        const item = e.target.closest('.wiki-mention-item');
        if (!item) return;
        insertWikiMentionLink({ id: parseInt(item.dataset.id), title: item.dataset.title });
    });

    editor.addEventListener('keydown', e => {
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
        if (!dropdown.contains(e.target) && e.target !== editor) {
            closeMentionDropdown();
        }
    });
}

// ── Init ──────────────────────────────────────────────────────────────────────
```

---

**Step 2: Llamar a `initMentionAutocomplete()` en el bloque de init al final de wiki.js**

El bloque de init actual (últimas 5 líneas del archivo) es:
```js
loadPagesList().then(() => {
    const params     = new URLSearchParams(window.location.search);
    const openPageId = parseInt(params.get('open_page') || '0');
    if (openPageId) loadPage(openPageId);
});
```

Reemplázalo con:
```js
loadPagesList().then(() => {
    const params     = new URLSearchParams(window.location.search);
    const openPageId = parseInt(params.get('open_page') || '0');
    if (openPageId) loadPage(openPageId);
});
initMentionAutocomplete();
```

---

**Step 3: Verificar sintaxis JS**

```bash
node --check /c/Users/carlo/proyects/claude-skills/app/assets/js/wiki.js
```
Expected: sin salida (éxito silencioso).

---

**Step 4: Commit**

```bash
cd /c/Users/carlo/proyects/claude-skills
git add app/assets/js/wiki.js
git commit -m "feat: wiki page mention autocomplete with [[ trigger"
```

---

## Smoke test manual en Laragon (`http://localhost/teamapp/public?page=wiki`)

1. Abrir una página existente con contenido
2. Colocar cursor en el editor y escribir `[[` → debe aparecer dropdown con todas las páginas del scope
3. Seguir escribiendo letras → dropdown filtra por título en tiempo real
4. Usar ↑↓ para navegar → elemento activo se resalta
5. Pulsar Enter (o click) → se inserta link `[Título de la página]` con estilo morado subrayado
6. Pulsar Esc con dropdown abierto → dropdown cierra sin insertar nada
7. Hacer click fuera del dropdown → cierra
8. Guardar la página (Ctrl+S o botón) y recargar → el link persiste con estilo `.wiki-mention`
9. Click en el link → navega a esa página (`?page=wiki&open_page=ID`)
10. Cambiar al tab "Proyecto", abrir una página → `[[` muestra páginas del scope proyecto, no las generales
