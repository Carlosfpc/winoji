// ── State ─────────────────────────────────────────────────────────────────────
let currentPageId = null;
let saveTimer     = null;
let typingTimer   = null;
let currentScope  = 'general';
let pagesFlat     = [];              // flat list updated on every loadPagesList()
const expandedPages = new Set();    // IDs of nodes that are expanded
let quill = null;       // Quill editor instance
let searchDebounceTimer = null;
let draggingPageId = null;
let currentContent = '';    // source-of-truth for current page content
let isEditMode     = false; // true while Quill editor is visible

// ── Helpers ───────────────────────────────────────────────────────────────────
function escapeHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

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
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = null;
    const pagesListEl = document.getElementById('pages-list');
    const resultsEl   = document.getElementById('wiki-search-results');
    pagesListEl.classList.remove('hidden');
    resultsEl.classList.add('hidden');
    resultsEl.innerHTML = '';
}

// ── Quill custom blots (preserve wiki mentions through Quill normalization) ────
(function registerWikiBlots() {
    const Inline = Quill.import('blots/inline');

    class WikiPageMentionBlot extends Inline {
        static create(value) {
            const node = super.create();
            if (value) {
                if (value.pageId) node.dataset.pageId = value.pageId;
                if (value.href)   node.setAttribute('href', value.href);
            }
            return node;
        }
        static formats(node) {
            return { pageId: node.dataset.pageId || '', href: node.getAttribute('href') || '#' };
        }
    }
    WikiPageMentionBlot.blotName  = 'wiki-mention';
    WikiPageMentionBlot.tagName   = 'a';
    WikiPageMentionBlot.className = 'wiki-mention';
    Quill.register(WikiPageMentionBlot);

    class WikiPersonMentionBlot extends Inline {
        static create(value) {
            const node = super.create();
            if (value && value.userId) node.dataset.userId = value.userId;
            return node;
        }
        static formats(node) {
            return { userId: node.dataset.userId || '' };
        }
    }
    WikiPersonMentionBlot.blotName  = 'wiki-mention-person';
    WikiPersonMentionBlot.tagName   = 'span';
    WikiPersonMentionBlot.className = 'wiki-mention-person';
    Quill.register(WikiPersonMentionBlot);
})();

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
                const index = range ? range.index : quill.getLength();
                quill.insertEmbed(index, 'image', json.url, Quill.sources.USER);
                quill.setSelection(index + 1, Quill.sources.SILENT);
            } else {
                showToast(json.error || 'Error al subir imagen', 'error');
            }
        } catch(e) {
            showToast('Error al subir imagen', 'error');
        }
    };
    input.click();
}

// ── Tree logic ────────────────────────────────────────────────────────────────
function buildTree(pages) {
    const map = {};
    pages.forEach(p => map[p.id] = { ...p, children: [] });
    const roots = [];
    pages.forEach(p => {
        if (p.parent_id && map[p.parent_id]) {
            map[p.parent_id].children.push(map[p.id]);
        } else {
            roots.push(map[p.id]);
        }
    });
    return roots;
}

/** Depth of a page in the tree (0 = root, 1 = child of root, …, 3 = max allowed) */
function getPageDepth(pageId) {
    const map = {};
    pagesFlat.forEach(p => map[p.id] = p);
    let depth = 0, cur = map[pageId];
    while (cur && cur.parent_id && depth < 6) {
        depth++;
        cur = map[cur.parent_id];
    }
    return depth;
}

/** All IDs that are descendants of pageId (including itself) */
function getDescendantIds(pageId) {
    const result = new Set();
    const queue  = [pageId];
    while (queue.length) {
        const id = queue.shift();
        result.add(id);
        pagesFlat.filter(p => p.parent_id === id).forEach(p => queue.push(p.id));
    }
    return result;
}

/** Ordered path from root to pageId */
function getBreadcrumb(pageId) {
    const map = {};
    pagesFlat.forEach(p => map[p.id] = p);
    const crumbs = [];
    let cur = map[pageId];
    while (cur) {
        crumbs.unshift(cur);
        cur = cur.parent_id ? map[cur.parent_id] : null;
    }
    return crumbs;
}

// ── Tree rendering ────────────────────────────────────────────────────────────
/**
 * depth = visual depth (0 = root). Max = 3 (4 levels total).
 * canAddChild when depth < 3.
 */
function renderPageTree(nodes, depth = 0) {
    if (!nodes.length) return '';
    const pad      = depth * 16 + 4;
    const canAdd   = depth < 3;
    return nodes.map(node => {
        const hasKids  = node.children.length > 0;
        const isExp    = expandedPages.has(node.id);
        const isActive = node.id === currentPageId;
        const toggle   = hasKids
            ? `<button class="wiki-toggle" data-id="${node.id}" title="${isExp ? 'Colapsar' : 'Expandir'}">${isExp ? '▾' : '▸'}</button>`
            : `<span class="wiki-toggle-spacer"></span>`;
        const children = (hasKids && isExp)
            ? `<div class="wiki-children">${renderPageTree(node.children, depth + 1)}</div>`
            : '';
        return `
            <div class="wiki-row${isActive ? ' wiki-row-active' : ''}" style="padding-left:${pad}px;" data-page-id="${node.id}" draggable="true">
                ${toggle}
                <span class="wiki-page-name" data-id="${node.id}" title="${escapeHtml(node.title)}">${escapeHtml(node.title)}</span>
                <div class="wiki-row-actions">
                    ${canAdd ? `<button class="wiki-btn-icon wiki-add-child" data-parent="${node.id}" title="Nueva subpágina">+</button>` : ''}
                    <button class="wiki-btn-icon wiki-delete-page delete" data-id="${node.id}" data-title="${escapeHtml(node.title)}" title="Eliminar">×</button>
                </div>
            </div>
            ${children}`;
    }).join('');
}

function rerenderTree() {
    const container = document.getElementById('pages-list');
    const sidebar   = document.querySelector('.wiki-sidebar');
    const scroll    = sidebar ? sidebar.scrollTop : 0;
    container.innerHTML = renderPageTree(buildTree(pagesFlat), 0);
    if (sidebar) sidebar.scrollTop = scroll;
}

// ── Event delegation on pages list ───────────────────────────────────────────
document.getElementById('pages-list').addEventListener('click', e => {
    const toggle   = e.target.closest('.wiki-toggle');
    const name     = e.target.closest('.wiki-page-name');
    const addChild = e.target.closest('.wiki-add-child');
    const del      = e.target.closest('.wiki-delete-page');

    if (toggle) {
        const id = parseInt(toggle.dataset.id);
        if (expandedPages.has(id)) expandedPages.delete(id);
        else                       expandedPages.add(id);
        rerenderTree();
    } else if (name) {
        loadPage(parseInt(name.dataset.id));
    } else if (addChild) {
        createSubpage(parseInt(addChild.dataset.parent));
    } else if (del) {
        deletePageWithConfirm(parseInt(del.dataset.id), del.dataset.title);
    }
});

// ── Drag-and-drop page nesting ────────────────────────────────────────────────
(function initDragAndDrop() {
    const list = document.getElementById('pages-list');

    list.addEventListener('dragstart', e => {
        const row = e.target.closest('.wiki-row');
        if (!row) return;
        draggingPageId = parseInt(row.dataset.pageId);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(draggingPageId));
        row.classList.add('wiki-row-dragging');
    });

    list.addEventListener('dragend', () => {
        document.querySelectorAll('.wiki-row-dragging, .wiki-row-drag-over')
            .forEach(el => el.classList.remove('wiki-row-dragging', 'wiki-row-drag-over'));
        draggingPageId = null;
    });

    list.addEventListener('dragover', e => {
        if (!draggingPageId) return;
        const row = e.target.closest('.wiki-row');
        if (!row) return;
        const targetId = parseInt(row.dataset.pageId);
        if (targetId === draggingPageId) return;
        if (getDescendantIds(draggingPageId).has(targetId)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.wiki-row-drag-over')
            .forEach(el => el.classList.remove('wiki-row-drag-over'));
        row.classList.add('wiki-row-drag-over');
    });

    list.addEventListener('dragleave', e => {
        const row = e.target.closest('.wiki-row');
        if (row && !row.contains(e.relatedTarget)) {
            row.classList.remove('wiki-row-drag-over');
        }
    });

    list.addEventListener('drop', e => {
        e.preventDefault();
        const row = e.target.closest('.wiki-row');
        document.querySelectorAll('.wiki-row-drag-over')
            .forEach(el => el.classList.remove('wiki-row-drag-over'));
        if (!row || !draggingPageId) return;
        const targetId = parseInt(row.dataset.pageId);
        if (targetId === draggingPageId) return;
        if (getDescendantIds(draggingPageId).has(targetId)) return;
        if (getPageDepth(targetId) >= 3) {
            showToast('Máximo 4 niveles de anidación permitidos', 'error');
            return;
        }
        const pid = draggingPageId;
        draggingPageId = null;
        movePageTo(pid, targetId);
    });
})();

async function movePageTo(pageId, newParentId) {
    const res  = await fetch(`${APP_URL}/app/api/pages.php?action=move`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: pageId, parent_id: newParentId })
    });
    const data = await res.json();
    if (data.success) {
        expandedPages.add(newParentId);
        await loadPagesList();
        if (currentPageId === pageId) updateBreadcrumb(pageId);
        showToast('Página anidada');
    } else {
        showToast(data.error || 'Error al mover página', 'error');
    }
}

// ── Page list operations ──────────────────────────────────────────────────────
async function loadPagesList() {
    let url = `${APP_URL}/app/api/pages.php?action=list&scope=${currentScope}`;
    if (currentScope === 'project' && PROJECT_ID) url += `&project_id=${PROJECT_ID}`;
    const res  = await fetch(url);
    const data = await res.json();
    pagesFlat  = data.data || [];
    if (!pagesFlat.length) {
        document.getElementById('pages-list').innerHTML =
            '<div style="padding:0.75rem;color:var(--text-tertiary);font-size:0.875rem;">Sin páginas. ¡Crea una!</div>';
        return;
    }
    rerenderTree();
}

async function createSubpage(parentId) {
    if (getPageDepth(parentId) >= 3) {
        showToast('Máximo 4 niveles de anidación permitidos', 'error');
        return;
    }
    const title = prompt('Título de la nueva subpágina:');
    if (!title || !title.trim()) return;
    const body = { title: title.trim(), content: '', scope: currentScope, parent_id: parentId };
    if (currentScope === 'project' && PROJECT_ID) body.project_id = PROJECT_ID;
    const res  = await fetch(`${APP_URL}/app/api/pages.php?action=create`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    const data = await res.json();
    if (data.success) {
        expandedPages.add(parentId);
        await loadPagesList();
        loadPage(data.id);
    } else {
        showToast(data.error || 'Error al crear subpágina', 'error');
    }
}

function deletePageWithConfirm(id, title) {
    showConfirm(
        `¿Eliminar la página "${title}"? Se eliminarán también todas sus subpáginas. Esta acción no se puede deshacer.`,
        async () => {
            const r = await fetch(`${APP_URL}/app/api/pages.php?action=delete`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) {
                if (currentPageId === id) {
                    currentPageId = null;
                    document.getElementById('editor-area').classList.add('hidden');
                    document.getElementById('editor-placeholder').classList.remove('hidden');
                    document.getElementById('page-breadcrumb').innerHTML = '';
                }
                showToast('Página eliminada');
                loadPagesList();
            } else {
                showToast(d.error || 'Error al eliminar', 'error');
            }
        },
        { confirmLabel: 'Eliminar', confirmClass: 'btn-danger', requireWord: 'ELIMINAR' }
    );
}

// ── Move page ─────────────────────────────────────────────────────────────────
function showMoveModal() {
    if (!currentPageId) return;
    const pageId       = currentPageId;
    const descendants  = getDescendantIds(pageId);
    const currentPage  = pagesFlat.find(p => p.id === pageId);
    const currentParent = currentPage?.parent_id ?? null;

    // Valid parents: not a descendant (including self), and not too deep
    const validParents = pagesFlat.filter(p => !descendants.has(p.id) && getPageDepth(p.id) < 3);

    const select = document.getElementById('move-parent-select');
    select.innerHTML = '<option value="">— Sin padre (página raíz) —</option>' +
        validParents.map(p => {
            const depth  = getPageDepth(p.id);
            const prefix = '\u00a0\u00a0\u00a0\u00a0'.repeat(depth);
            return `<option value="${p.id}"${p.id === currentParent ? ' selected' : ''}>${prefix}${escapeHtml(p.title)}</option>`;
        }).join('');

    document.getElementById('move-page-modal-title').textContent = `Mover: "${currentPage?.title || ''}"`;
    document.getElementById('move-page-modal').classList.remove('hidden');

    document.getElementById('move-confirm-btn').onclick = async () => {
        const newParentId = select.value ? parseInt(select.value) : null;
        const res = await fetch(`${APP_URL}/app/api/pages.php?action=move`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: pageId, parent_id: newParentId })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('move-page-modal').classList.add('hidden');
            if (newParentId) expandedPages.add(newParentId);
            await loadPagesList();
            updateBreadcrumb(pageId);
            showToast('Página movida');
        } else {
            showToast(data.error || 'Error al mover', 'error');
        }
    };
    document.getElementById('move-cancel-btn').onclick = () => {
        document.getElementById('move-page-modal').classList.add('hidden');
    };
}

// ── Breadcrumb ────────────────────────────────────────────────────────────────
function updateBreadcrumb(pageId) {
    const el     = document.getElementById('page-breadcrumb');
    if (!el) return;
    const crumbs = getBreadcrumb(pageId);
    if (crumbs.length <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = crumbs.slice(0, -1).map(p =>
        `<span class="wiki-bc-link" data-id="${p.id}">${escapeHtml(p.title)}</span>`
    ).join('<span style="margin:0 0.25rem;color:var(--text-tertiary);">›</span>') +
    '<span style="margin:0 0.25rem;color:var(--text-tertiary);">›</span>';
}

document.getElementById('page-breadcrumb').addEventListener('click', e => {
    const link = e.target.closest('.wiki-bc-link');
    if (link) loadPage(parseInt(link.dataset.id));
});

// ── View / edit mode helpers ───────────────────────────────────────────────────
function showViewMode() {
    isEditMode = false;
    document.getElementById('wiki-view-area').classList.remove('hidden');
    document.getElementById('wiki-edit-area').classList.add('hidden');
}

function showEditMode() {
    isEditMode = true;
    document.getElementById('wiki-view-area').classList.add('hidden');
    document.getElementById('wiki-edit-area').classList.remove('hidden');
}

function enterEditMode() {
    if (!currentPageId) return;
    quill.root.innerHTML = DOMPurify.sanitize(currentContent);
    quill.history.clear();
    showEditMode();
    quill.focus();
}

// Click handler for wiki mentions in view mode
document.getElementById('wiki-content-view').addEventListener('click', e => {
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

// ── Editor ────────────────────────────────────────────────────────────────────
async function loadPage(id) {
    const res  = await fetch(`${APP_URL}/app/api/pages.php?action=get&id=${id}`);
    const data = await res.json();
    if (!data.success) return;
    currentPageId  = id;
    currentContent = data.data.content || '';
    document.getElementById('page-title').value = data.data.title;
    document.getElementById('editor-placeholder').classList.add('hidden');
    document.getElementById('editor-area').classList.remove('hidden');
    document.getElementById('wiki-content-view').innerHTML = DOMPurify.sanitize(currentContent);
    showViewMode();
    document.getElementById('save-status').textContent = '';
    scheduleSave();
    document.getElementById('page-title').oninput = scheduleTypingSave;
    updateBreadcrumb(id);
    rerenderTree(); // refresh active state
}

function scheduleSave() {
    if (saveTimer) clearInterval(saveTimer);
    saveTimer = setInterval(savePage, 30000);
}

function scheduleTypingSave() {
    if (typingTimer) clearTimeout(typingTimer);
    document.getElementById('save-status').textContent = 'Sin guardar...';
    typingTimer = setTimeout(savePage, 2000);
}

async function savePage(exitAfter = false) {
    if (!currentPageId) return;
    const title   = document.getElementById('page-title').value;
    const content = isEditMode && quill ? quill.root.innerHTML : currentContent;
    const res  = await fetch(`${APP_URL}/app/api/pages.php?action=update`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: currentPageId, title, content })
    });
    const data = await res.json();
    document.getElementById('save-status').textContent = data.success ? 'Guardado ✓' : 'Error al guardar';
    if (data.success) {
        currentContent = content;
        if (exitAfter) {
            document.getElementById('wiki-content-view').innerHTML = DOMPurify.sanitize(currentContent);
            showViewMode();
        }
    }
    await loadPagesList(); // refresh sidebar (title may have changed)
}

// ── History ───────────────────────────────────────────────────────────────────
async function toggleHistory() {
    const panel = document.getElementById('history-panel');
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden') && currentPageId) {
        await loadHistory(currentPageId);
    }
}

async function loadHistory(pageId) {
    const res      = await fetch(`${APP_URL}/app/api/pages.php?action=versions&page_id=${pageId}`);
    const data     = await res.json();
    const list     = document.getElementById('history-list');
    const versions = data.data || [];
    if (!versions.length) {
        list.innerHTML = '<em style="color:var(--text-tertiary);font-size:0.875rem;">Sin versiones guardadas aún.</em>';
        return;
    }
    list.innerHTML = versions.map((v, i) => `
        <div style="padding:0.65rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:0.5rem;font-size:0.85rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.3rem;">
                <div>
                    <div style="font-weight:600;color:#374151;">${i === 0 ? '<span style="color:#16a34a;">● Actual</span> &nbsp;' : ''}${new Date(v.saved_at).toLocaleString('es-ES')}</div>
                    <div style="color:var(--text-tertiary);margin-top:0.1rem;">por ${escapeHtml(v.saved_by_name)}</div>
                </div>
                <button onclick="restoreVersion(${v.id})" class="btn btn-secondary"
                    style="font-size:0.75rem;padding:0.2rem 0.6rem;">
                    ${i === 0 ? 'Es la actual' : '⎌ Restaurar'}
                </button>
            </div>
        </div>
    `).join('');
}

async function restoreVersion(versionId) {
    const res  = await fetch(`${APP_URL}/app/api/pages.php?action=get_version&id=${versionId}`);
    const data = await res.json();
    if (!data.success) return showToast('No se pudo cargar la versión', 'error');
    showConfirm('¿Restaurar esta versión? El contenido actual se sobreescribirá y guardará.', async () => {
        currentContent = data.data.content || '';
        document.getElementById('wiki-content-view').innerHTML = DOMPurify.sanitize(currentContent);
        if (isEditMode && quill) quill.root.innerHTML = DOMPurify.sanitize(currentContent);
        await savePage();
        showViewMode();
        showToast('Versión restaurada y guardada');
        await loadHistory(currentPageId);
    }, { confirmLabel: 'Restaurar', confirmClass: 'btn-primary' });
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(scope) {
    currentScope  = scope;
    currentPageId = null;
    expandedPages.clear();
    clearSearch();
    document.getElementById('wiki-search-input').value = '';
    document.getElementById('editor-area').classList.add('hidden');
    document.getElementById('editor-placeholder').classList.remove('hidden');
    document.getElementById('page-breadcrumb').innerHTML = '';
    const isGeneral = scope === 'general';
    document.getElementById('tab-general').classList.toggle('active', isGeneral);
    document.getElementById('tab-project').classList.toggle('active', !isGeneral);
    document.getElementById('tab-label').textContent = isGeneral ? 'Páginas generales' : 'Páginas del proyecto';
    loadPagesList();
}

// ── New root page ─────────────────────────────────────────────────────────────
document.getElementById('new-page-btn').addEventListener('click', async () => {
    const title = prompt('Título de la nueva página:');
    if (!title || !title.trim()) return;
    const body = { title: title.trim(), content: '', scope: currentScope };
    if (currentScope === 'project' && PROJECT_ID) body.project_id = PROJECT_ID;
    const res  = await fetch(`${APP_URL}/app/api/pages.php?action=create`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    const data = await res.json();
    if (data.success) {
        await loadPagesList();
        loadPage(data.id);
    }
});

// ── Wiki mentions ([[página]] y @persona) ────────────────────────────────────

let wikiMentionQuery   = '';
let wikiMentionTrigger = null; // 'page' | 'person'
let wikiTeamCache      = [];

async function loadWikiTeamMembers() {
    if (wikiTeamCache.length) return;
    try {
        const res  = await fetch(`${APP_URL}/app/api/team.php?action=members`);
        const data = await res.json();
        wikiTeamCache = data.data || [];
    } catch(e) {}
}

/** Texto completo desde el inicio del editor hasta la posición del cursor. */
function getTextBeforeCursor() {
    if (!quill) return '';
    const sel = quill.getSelection();
    if (!sel) return '';
    return quill.getText(0, sel.index);
}

function closeMentionDropdown() {
    const d = document.getElementById('wiki-mention-dropdown');
    if (d) d.style.display = 'none';
    wikiMentionTrigger = null;
}

function renderWikiMentionDropdown(results) {
    const dropdown = document.getElementById('wiki-mention-dropdown');
    if (!dropdown) return;

    if (!results.length) {
        dropdown.innerHTML = '<div style="padding:0.5rem 0.75rem;color:var(--text-secondary);font-size:0.875rem;">Sin resultados</div>';
    } else {
        dropdown.innerHTML = results.map(item => {
            const isPerson = wikiMentionTrigger === 'person';
            const avatar   = isPerson && item.avatar
                ? `<img src="${escapeHtml(item.avatar)}" style="width:16px;height:16px;border-radius:50%;object-fit:cover;flex-shrink:0;">`
                : isPerson
                    ? `<span style="width:16px;height:16px;border-radius:50%;background:var(--color-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:700;flex-shrink:0;">${escapeHtml((item.name||'?').charAt(0).toUpperCase())}</span>`
                    : `<span style="font-size:0.75rem;opacity:0.5;flex-shrink:0;">📄</span>`;
            const label = escapeHtml(isPerson ? item.name : item.title);
            return `<div class="wiki-mention-item"
                         data-id="${item.id}"
                         data-label="${label}"
                         style="padding:0.45rem 0.75rem;cursor:pointer;font-size:0.875rem;
                                display:flex;align-items:center;gap:0.4rem;
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        ${avatar}${label}
                    </div>`;
        }).join('');
    }

    // Posicionar debajo del cursor
    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
        const rect = sel.getRangeAt(0).getBoundingClientRect();
        dropdown.style.top  = (rect.bottom + 4) + 'px';
        dropdown.style.left = Math.min(rect.left, window.innerWidth - 330) + 'px';
    }
    dropdown.style.display = 'block';
}

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

function initMentionAutocomplete() {
    const dropdown = document.getElementById('wiki-mention-dropdown');
    if (!dropdown || !quill) return;

    // Prevent editor from losing focus when clicking dropdown
    dropdown.addEventListener('mousedown', e => e.preventDefault());

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

// ── Init ──────────────────────────────────────────────────────────────────────
initQuill();
Promise.all([loadPagesList(), loadWikiTeamMembers()]).then(() => {
    const params     = new URLSearchParams(window.location.search);
    const openPageId = parseInt(params.get('open_page') || '0');
    if (openPageId) loadPage(openPageId);
    initMentionAutocomplete();
    if (typeof initWikiSearch === 'function') initWikiSearch();
});
