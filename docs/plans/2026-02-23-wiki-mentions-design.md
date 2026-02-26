# Wiki Page Mentions — Design Doc

**Fecha:** 2026-02-23

## Resumen

Añadir sintaxis `[[` en el editor de wiki para insertar enlaces a otras páginas mediante autocomplete. Al escribir `[[` aparece un dropdown flotante con las páginas del scope actual; al seleccionar una, se inserta un `<a>` con el ID de la página. El título visible queda fijo al momento de la inserción (no se actualiza si la página se renombra), pero el link sigue funcionando por ID.

---

## Flujo de interacción

```
Usuario escribe [[ en el editor
    → dropdown flotante con todas las páginas del scope actual
    → filtrado en tiempo real conforme escribe [[Reu...
    → ↑↓ para navegar, Enter para seleccionar, Esc para cerrar
    → al seleccionar "Reunión de equipo":
       [[Reu  →  <a class="wiki-mention" href="?page=wiki&open_page=5">Reunión de equipo</a>
    → click en el link navega a esa página
```

---

## Arquitectura

**Solo se modifica:**
- `app/pages/wiki.php` — añadir `<div id="wiki-mention-dropdown">`
- `app/assets/js/wiki.js` — añadir `initMentionAutocomplete()` e `insertMentionLink(page)`

**Sin cambios en:** backend PHP, `main.css`, ni otros archivos JS.

### Dropdown HTML (en wiki.php)

```html
<div id="wiki-mention-dropdown"
     style="display:none;position:fixed;z-index:9999;background:var(--bg-card);
            border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);
            min-width:220px;max-width:320px;max-height:240px;overflow-y:auto;">
</div>
```

### Lógica JS

```js
function initMentionAutocomplete() {
    const editor   = document.getElementById('page-content');
    const dropdown = document.getElementById('wiki-mention-dropdown');

    editor.addEventListener('input', () => {
        const match = getTextBeforeCursor().match(/\[\[([^\[\]]{0,50})$/);
        if (!match) { closeMentionDropdown(); return; }
        const query   = match[1].toLowerCase();
        const results = pagesFlat.filter(p => p.title.toLowerCase().includes(query)).slice(0, 10);
        renderMentionDropdown(results, match[1]);
    });

    editor.addEventListener('keydown', e => { /* ↑↓ Enter Esc */ });
    document.addEventListener('click', e => {
        if (!dropdown.contains(e.target)) closeMentionDropdown();
    });
}

function insertMentionLink(page) {
    // Seleccionar y borrar [[texto antes del cursor, insertar <a>
    const link = `<a href="${APP_URL}?page=wiki&open_page=${page.id}" class="wiki-mention">${escapeHtml(page.title)}</a>`;
    document.execCommand('insertHTML', false, link);
    closeMentionDropdown();
}
```

### CSS de la mention (inline en wiki.php o wiki.js)

```css
.wiki-mention {
    color: #4f46e5;
    text-decoration: underline;
    cursor: pointer;
}
```

---

## Edge cases

| Caso | Comportamiento |
|------|---------------|
| `pagesFlat` vacío | No se muestra dropdown |
| Sin resultados al filtrar | Muestra "Sin páginas encontradas" |
| Página renombrada | Link funciona por ID; texto visible queda con título antiguo |
| DOMPurify | Permite `<a href>` — links persisten al cargar |
| Click en link dentro del editor | Navega a la página (comportamiento esperado) |
| Scope | Solo páginas del scope actual (`pagesFlat` ya filtrado) |

---

## API

Sin cambios de backend. Usa `pagesFlat` (ya disponible en `wiki.js`) y el endpoint existente `?page=wiki&open_page=ID`.
