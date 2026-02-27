<?php
$page_title = 'Issues';
require __DIR__ . '/../includes/layout_top.php';
?>

<!-- ── Issues list view ──────────────────────────────────────────────────── -->
<div id="issues-view">

<div class="page-header">
    <h2>Issues</h2>
    <button class="btn btn-primary" id="new-issue-btn">+ Nueva Issue</button>
</div>

<!-- Filter bar -->
<div id="filter-bar" class="filter-bar">
    <input type="checkbox" id="select-all-cb" title="Seleccionar todos" style="width:1rem;height:1rem;cursor:pointer;">
    <span class="filter-label">Filtros:</span>
    <select id="filter-status" class="form-select form-select-sm">
        <option value="">Estado: Todos</option>
        <option value="todo">Pendiente</option>
        <option value="in_progress">En curso</option>
        <option value="review">Revisión</option>
        <option value="done">Hecho</option>
    </select>
    <select id="filter-priority" class="form-select form-select-sm">
        <option value="">Prioridad: Todas</option>
        <option value="low">Baja</option>
        <option value="medium">Media</option>
        <option value="high">Alta</option>
        <option value="critical">Crítica</option>
    </select>
    <select id="filter-type" class="form-select form-select-sm" style="min-width:120px;">
        <option value="">Tipo: Todos</option>
    </select>
    <select id="filter-assignee" class="form-select form-select-sm" style="min-width:130px;">
        <option value="">Asignado: Todos</option>
        <option value="none">Sin asignar</option>
    </select>
    <button id="filter-clear" class="btn btn-secondary btn-sm">✕ Limpiar</button>
    <button id="export-csv-btn" class="btn btn-secondary btn-sm">&#8595; CSV</button>
    <span id="filter-count" class="text-xs text-muted ml-auto"></span>
</div>

<!-- Bulk action bar (hidden until ≥1 selected) -->
<div id="bulk-bar" class="bulk-bar">
    <span id="bulk-count" class="text-sm font-semibold text-primary-color"></span>
    <button class="btn btn-secondary btn-sm" id="bulk-done-btn">&#10003; Marcar como Hecho</button>
    <select id="bulk-priority-sel" class="form-select form-select-sm">
        <option value="">Cambiar prioridad...</option>
        <option value="low">Baja</option>
        <option value="medium">Media</option>
        <option value="high">Alta</option>
        <option value="critical">Crítica</option>
    </select>
    <select id="bulk-assignee-sel" class="form-select form-select-sm" style="min-width:140px;">
        <option value="">Reasignar a...</option>
    </select>
    <button class="btn btn-secondary btn-sm ml-auto" id="bulk-clear-btn">&#10005; Cancelar</button>
</div>

<div id="issue-list"></div>
<div id="pagination" class="pagination" style="display:none;"></div>

<!-- Issue Detail Panel -->
<div id="issue-detail" class="issue-detail hidden">
    <div class="issue-detail-header">
        <button id="close-detail">&#10005;</button>
        <h3 id="detail-title"></h3>
        <div id="detail-meta"></div>
    </div>
    <div id="detail-desc" class="mb-4"></div>
    <div id="labels-section" class="mb-3">
        <div id="issue-labels-list" class="flex flex-wrap gap-1 mb-2"></div>
        <div class="flex gap-2 items-center">
            <select id="label-picker" class="form-select flex-1">
                <option value="">Añadir label...</option>
            </select>
            <button class="btn btn-secondary btn-sm" id="add-label-btn">Añadir</button>
            <button id="new-label-toggle" title="Create new label" class="btn btn-secondary btn-sm">+</button>
        </div>
        <div id="new-label-form" class="filter-bar mt-2" style="display:none;">
            <div class="flex gap-1 items-center mb-2">
                <input type="text" id="new-label-name" placeholder="Nombre del label" class="form-input flex-1">
                <input type="color" id="new-label-color" value="#34BF1F" style="width:2rem;height:1.8rem;padding:0.1rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;">
                <button class="btn btn-primary btn-sm" id="save-label-btn">Crear</button>
                <button id="cancel-label-btn" class="btn-link text-muted">&#10005;</button>
            </div>
            <div id="all-labels-list" class="flex flex-wrap gap-1"></div>
        </div>
    </div>
    <div class="flex items-center gap-3 mb-3">
        <label class="text-sm text-muted nowrap">Asignado a:</label>
        <select id="assignee-picker" class="form-select flex-1">
            <option value="">Sin asignar</option>
        </select>
    </div>
    <hr class="mb-3">
    <div class="comments-section">
        <strong>Comentarios</strong>
        <div id="comments-list" class="mt-3"></div>
        <div class="flex gap-2 mt-3">
            <textarea id="comment-input" placeholder="Escribe un comentario..." class="form-textarea flex-1" style="height:60px;"></textarea>
            <button class="btn btn-primary" id="add-comment-btn" style="align-self:flex-end;">Enviar</button>
        </div>
    </div>
    <hr class="mb-3 mt-3">
    <div class="github-section">
        <strong>Ramas GitHub</strong>
        <div id="github-repo-status" class="text-sm text-muted mt-1 mb-2"></div>
        <div id="branch-list" class="mb-2"></div>
        <div id="create-branch-area" style="display:none;margin-top:0.5rem;">
            <button class="btn btn-primary btn-sm w-full" id="create-branch-btn">+ Crear rama</button>
        </div>
    </div>
    <hr class="mb-3 mt-3">
    <div class="prs-section">
        <strong>Pull Requests</strong>
        <div id="prs-list" class="mt-2"></div>
    </div>
</div>

</div><!-- /#issues-view -->

<!-- @mention autocomplete dropdown -->
<div id="mention-dropdown" class="mention-dropdown" style="display:none;"></div>

<!-- ── Full Issue View (inline, replaces list) ──────────────────────────── -->
<div id="full-issue-view" class="hidden">

    <!-- Top bar -->
    <div class="flex items-center gap-4 mb-6 flex-wrap">
        <button id="fi-back" class="btn btn-secondary nowrap">&#8592; Volver</button>
        <div class="flex-1 min-w-0">
            <div id="fi-id" class="text-xs text-muted mb-1"></div>
            <input id="fi-title" type="text"
                style="width:100%;font-size:1.2rem;font-weight:700;border:none;border-bottom:2px solid transparent;outline:none;padding:0.1rem 0;background:transparent;box-sizing:border-box;transition:border-color 0.15s;color:var(--text-primary);"
                placeholder="Issue title"
                onfocus="this.style.borderBottomColor='var(--color-primary)'"
                onblur="this.style.borderBottomColor='transparent'">
        </div>
        <button id="fi-save" class="btn btn-primary nowrap">Guardar cambios</button>
    </div>

    <!-- 2-column grid -->
    <div style="display:grid;grid-template-columns:1fr 360px;gap:1.25rem;align-items:start;">

        <!-- Left: tabs (Descripción | Tests | Comentarios) -->
        <div>
            <div class="wiki-tabs mb-3" id="fi-tabs">
                <button class="wiki-tab active" data-tab="desc">Descripción</button>
                <button class="wiki-tab" data-tab="tests">Tests <span id="fi-tests-badge" style="display:none;background:var(--color-primary);color:#fff;border-radius:999px;font-size:0.65rem;padding:0.05rem 0.35rem;font-weight:700;vertical-align:middle;"></span></button>
                <button class="wiki-tab" data-tab="comments">Comentarios</button>
            </div>

            <!-- Tab: Descripción -->
            <div id="fi-tab-desc" class="flex-col gap-4">
                <div class="card card-compact">
                    <div class="text-label mb-2">Descripción</div>
                    <textarea id="fi-desc" placeholder="Sin descripción..." class="form-textarea w-full" style="min-height:140px;"></textarea>
                </div>
                <div class="card card-compact">
                    <div class="flex flex-between items-center mb-3">
                        <div class="text-label">Checklist</div>
                        <span id="fi-checklist-progress" class="text-xs text-muted"></span>
                    </div>
                    <div id="fi-checklist-items" class="mb-3"></div>
                    <div class="flex gap-2">
                        <input type="text" id="fi-checklist-input" placeholder="Añadir elemento..."
                            class="form-input flex-1">
                        <button class="btn btn-secondary btn-sm" id="fi-checklist-add">+ Añadir</button>
                    </div>
                </div>
            </div>

            <!-- Tab: Tests -->
            <div id="fi-tab-tests" class="hidden">
                <div class="card card-compact">
                    <div class="flex flex-between items-center mb-3">
                        <div class="text-label">Test Cases</div>
                        <button class="btn btn-primary btn-sm" id="fi-test-new-btn">+ Nuevo Test</button>
                    </div>
                    <div id="fi-tests-list">
                        <div class="empty-state">Sin test cases</div>
                    </div>
                </div>
            </div>

            <!-- Tab: Comentarios -->
            <div id="fi-tab-comments" class="hidden">
                <div class="card card-compact">
                    <div class="text-label mb-3">Comentarios</div>
                    <div id="fi-comments-list" class="mb-3"></div>
                    <div class="flex gap-2">
                        <textarea id="fi-comment-input" placeholder="Escribe un comentario..." class="form-textarea flex-1" style="height:60px;"></textarea>
                        <button class="btn btn-primary" id="fi-add-comment" style="align-self:flex-end;">Enviar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: metadata -->
        <div class="flex-col gap-3">
            <div class="card card-compact flex-col gap-3">
                <div class="form-group">
                    <div class="text-label">Tipo</div>
                    <select id="fi-type" class="form-select">
                        <option value="">Sin tipo</option>
                    </select>
                </div>
                <div class="form-group">
                    <div class="text-label">Estado</div>
                    <select id="fi-status" class="form-select">
                        <option value="todo">Pendiente</option>
                        <option value="in_progress">En curso</option>
                        <option value="review">Revisión</option>
                        <option value="done">Hecho</option>
                    </select>
                </div>
                <div class="form-group">
                    <div class="text-label">Prioridad</div>
                    <select id="fi-priority" class="form-select">
                        <option value="low">Baja</option>
                        <option value="medium">Media</option>
                        <option value="high">Alta</option>
                        <option value="critical">Crítica</option>
                    </select>
                </div>
                <div class="form-group">
                    <div class="text-label">Asignado a</div>
                    <select id="fi-assignee" class="form-select">
                        <option value="">Sin asignar</option>
                    </select>
                </div>
                <div class="form-group">
                    <div class="text-label">Fecha límite</div>
                    <input type="date" id="fi-due-date" class="form-input w-full">
                </div>
                <div class="form-group">
                    <div class="text-label">Puntos</div>
                    <input type="number" id="fi-points" min="1" max="100" placeholder="—"
                        class="form-input w-full">
                </div>
                <div class="form-group">
                    <div class="text-label mb-2">Labels</div>
                    <div id="fi-labels" class="flex flex-wrap gap-1 mb-2"></div>
                    <div class="flex gap-1 items-center">
                        <select id="fi-label-picker" class="form-select flex-1 form-select-sm">
                            <option value="">Añadir label...</option>
                        </select>
                        <button class="btn btn-secondary btn-xs" id="fi-add-label-btn">OK</button>
                        <button id="fi-new-label-toggle" title="Crear nuevo label" class="btn btn-secondary btn-xs">+</button>
                    </div>
                    <div id="fi-new-label-form" class="filter-bar mt-2" style="display:none;">
                        <div class="flex gap-1 items-center">
                            <input type="text" id="fi-new-label-name" placeholder="Nombre" class="form-input flex-1 form-input-sm">
                            <input type="color" id="fi-new-label-color" value="#34BF1F" style="width:1.8rem;height:1.6rem;padding:0.05rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;">
                            <button class="btn btn-primary btn-xs" id="fi-save-label-btn">Crear</button>
                            <button id="fi-cancel-label-btn" class="btn-link text-muted" style="font-size:1.1rem;line-height:1;">&#215;</button>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="text-label">Creada</div>
                    <div id="fi-created-at" class="text-sm"></div>
                </div>
            </div>
            <!-- Dependencies section -->
            <div id="fi-deps-section" class="card card-compact">
                <h4 class="text-label mb-3">Dependencias</h4>
                <div id="fi-deps-list" class="mb-3"></div>
                <div class="flex gap-2 items-center flex-wrap">
                    <select id="fi-dep-type" class="form-select form-select-sm">
                        <option value="blocks">Esta bloquea a</option>
                        <option value="relates_to">Relacionada con</option>
                    </select>
                    <input type="number" id="fi-dep-issue-id" placeholder="ID de issue..." min="1"
                        class="form-input form-input-sm" style="width:120px;">
                    <button class="btn btn-secondary btn-sm" id="fi-add-dep-btn">+ Añadir</button>
                </div>
            </div>
            <div class="card card-compact">
                <div class="text-label mb-2">Ramas</div>
                <div id="fi-branches" class="text-sm mb-2"></div>
                <div id="fi-create-branch-area" style="display:none;margin-top:0.25rem;">
                    <button class="btn btn-primary btn-sm" id="fi-create-branch-btn">+ Crear rama</button>
                </div>
            </div>
            <div class="card card-compact">
                <div class="text-label mb-2">Pull Requests</div>
                <div id="fi-prs" class="text-sm"></div>
            </div>
            <div class="card card-compact">
                <div class="text-label mb-2">Historial de estados</div>
                <div id="fi-status-log" class="text-sm"></div>
            </div>
            <button onclick="deleteCurrentIssue()" class="btn w-full btn-danger-outline">&#128465; Eliminar issue</button>
        </div>
    </div>
</div><!-- /#full-issue-view -->

<!-- New Issue Modal -->
<div id="new-issue-modal" class="modal hidden">
    <div class="modal-box">
        <h3 class="mb-4">Nueva Issue</h3>
        <div class="form-group">
            <label class="form-label">Usar plantilla (opcional)</label>
            <select id="template-picker" class="form-select">
                <option value="">Sin plantilla</option>
            </select>
        </div>
        <div class="form-group">
            <input type="text" id="new-title" placeholder="Título" class="form-input w-full">
        </div>
        <div class="form-group">
            <label class="form-label" for="new-branch-name">Nombre de rama sugerido</label>
            <input type="text" id="new-branch-name" class="form-input w-full" placeholder="issue-nombre-de-la-tarea" style="font-family:monospace;font-size:0.85rem;">
        </div>
        <div class="form-row mb-3">
            <div class="flex-1">
                <label class="form-label">Tipo</label>
                <select id="new-type" class="form-select">
                    <option value="">Sin tipo</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="form-label">Prioridad</label>
                <select id="new-priority" class="form-select">
                    <option value="low">Baja</option>
                    <option value="medium" selected>Media</option>
                    <option value="high">Alta</option>
                    <option value="critical">Crítica</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <textarea id="new-desc" placeholder="Descripción (opcional)" class="form-textarea w-full" style="height:70px;"></textarea>
        </div>
        <div class="form-row mb-4">
            <div class="flex-1">
                <label class="form-label">Asignado a</label>
                <select id="new-assigned" class="form-select">
                    <option value="">Sin asignar</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="form-label">Fecha límite</label>
                <input type="date" id="new-due-date" class="form-input w-full">
            </div>
            <div style="flex:0 0 80px;">
                <label class="form-label">Puntos</label>
                <input type="number" id="new-story-points" min="1" max="100" placeholder="—" class="form-input w-full">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="new-cancel">Cancelar</button>
            <button class="btn btn-primary" id="new-save">Crear</button>
        </div>
    </div>
</div>

<!-- Create Branch Modal -->
<div id="branch-create-modal" class="modal hidden">
    <div class="modal-box" style="max-width:480px;">
        <h3 class="mb-4">Crear rama</h3>
        <div class="form-group">
            <label class="form-label" for="bcm-type">Tipo</label>
            <select id="bcm-type" class="form-select w-full">
                <option value="feature">feature/</option>
                <option value="bugfix">bugfix/</option>
                <option value="release">release/</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="bcm-name">Nombre de rama</label>
            <input type="text" id="bcm-name" class="form-input w-full" placeholder="issue-42-nombre" style="font-family:monospace;">
        </div>
        <div class="mb-4" style="font-size:0.875rem;color:var(--text-secondary);">
            Rama: <span id="bcm-preview" class="font-mono" style="color:var(--color-primary);font-weight:600;"></span>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="bcm-cancel">Cancelar</button>
            <button class="btn btn-primary" id="bcm-confirm">Crear rama</button>
        </div>
    </div>
</div>

<!-- Create PR Modal -->
<div id="create-pr-modal" class="modal hidden">
    <div class="modal-box">
        <h3 class="mb-3">Crear Pull Request</h3>
        <div class="flex items-center gap-1 flex-wrap mb-3 p-2" style="background:var(--bg-secondary);border-radius:var(--radius-md);font-size:0.875rem;">
            <span class="font-mono text-primary-color font-semibold" id="pr-branch-display"></span>
            <span class="text-muted">&#8594;</span>
            <select id="pr-base-branch" class="form-select form-select-sm font-mono">
                <option value="main">main</option>
            </select>
        </div>
        <div class="form-group">
            <input type="text" id="pr-title" placeholder="Título del PR" class="form-input w-full">
        </div>
        <div class="form-group">
            <textarea id="pr-body" placeholder="Descripción (opcional)" class="form-textarea w-full" style="height:80px;"></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="pr-cancel">Cancelar</button>
            <button class="btn btn-primary" id="pr-submit">Crear PR</button>
        </div>
    </div>
</div>

<!-- Diff Viewer Modal -->
<div id="diff-viewer-modal" class="modal hidden" style="z-index:200;">
    <div class="modal-box" style="max-width:min(900px,92vw);width:min(900px,92vw);max-height:88vh;display:flex;flex-direction:column;">
        <div class="flex flex-between gap-4 flex-wrap mb-3 flex-shrink-0 items-start">
            <div class="min-w-0">
                <h3 id="diff-pr-title" class="mb-1"></h3>
                <div id="diff-summary" class="text-sm text-muted"></div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <div id="diff-merge-area" style="display:none;align-items:center;gap:0.4rem;">
                    <select id="merge-method" class="form-select form-select-sm">
                        <option value="merge">Merge commit</option>
                        <option value="squash">Squash &amp; merge</option>
                        <option value="rebase">Rebase &amp; merge</option>
                    </select>
                    <button id="merge-pr-btn" class="btn btn-primary btn-sm" style="background:#16a34a;border-color:#16a34a;">
                        &#10003; Merge PR
                    </button>
                </div>
                <a id="diff-gh-link" href="#" target="_blank" class="text-sm text-primary-color" style="text-decoration:none;display:none;">Open in GitHub &#8599;</a>
                <button id="diff-close" class="btn-link text-muted" style="font-size:1.5rem;line-height:1;">&times;</button>
            </div>
        </div>
        <div id="diff-content" style="overflow-y:auto;flex:1;"></div>
    </div>
</div>

<!-- ── Test Case Modal (create / edit) ──────────────────────────────────── -->
<div id="test-case-modal" class="modal hidden">
    <div class="modal-box" style="max-width:600px;width:min(600px,94vw);">
        <h3 class="mb-4" id="test-case-modal-title">Nuevo Test Case</h3>
        <div class="form-group">
            <label class="form-label">Título del test *</label>
            <input type="text" id="tc-title" class="form-input w-full" placeholder="Ej: Verificar login con credenciales válidas">
        </div>
        <div class="form-group mb-4">
            <label class="form-label">Asignado a</label>
            <select id="tc-assignee" class="form-select w-full">
                <option value="">Sin asignar</option>
            </select>
        </div>
        <div class="mb-3">
            <div class="flex flex-between items-center mb-2">
                <div class="text-label">Pasos</div>
                <button class="btn btn-secondary btn-sm" id="tc-add-step-btn">+ Añadir paso</button>
            </div>
            <div id="tc-steps-list"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="tc-cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="tc-save-btn">Guardar</button>
        </div>
    </div>
</div>

<!-- ── Execute Test Modal (step-by-step) ─────────────────────────────────── -->
<div id="test-execute-modal" class="modal hidden">
    <div class="modal-box" style="max-width:560px;width:min(560px,94vw);">
        <div class="flex flex-between items-center mb-4">
            <h3 id="exec-modal-title" class="mb-0">Ejecutar Test</h3>
            <span id="exec-modal-counter" class="text-sm text-muted"></span>
        </div>
        <div id="exec-step-content"></div>
        <div class="flex flex-between items-center mt-4 pt-3" style="border-top:1px solid var(--border);">
            <button class="btn btn-secondary" id="exec-prev-btn">&#8592; Anterior</button>
            <div class="flex gap-2">
                <button class="btn btn-secondary" id="exec-cancel-btn">Cancelar</button>
                <button class="btn btn-primary" id="exec-next-btn">Siguiente &#8594;</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Execution Detail Modal (read-only) ────────────────────────────────── -->
<div id="test-detail-modal" class="modal hidden">
    <div class="modal-box" style="max-width:560px;width:min(560px,94vw);max-height:80vh;display:flex;flex-direction:column;">
        <div class="flex flex-between items-center mb-4 flex-shrink-0">
            <div>
                <h3 id="detail-modal-title" class="mb-1">Detalle de Ejecución</h3>
                <div id="detail-modal-meta" class="text-sm text-muted"></div>
            </div>
            <button id="detail-modal-close" class="btn-link text-muted" style="font-size:1.5rem;line-height:1;">&times;</button>
        </div>
        <div id="detail-modal-steps" style="overflow-y:auto;flex:1;"></div>
    </div>
</div>

<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
const CURRENT_USER_ID = <?= (int)(current_user()['id'] ?? 0) ?>;
const CURRENT_USER_ROLE = <?= json_encode(current_user()['role'] ?? '') ?>;
if (!PROJECT_ID) {
    const list = document.getElementById('issue-list');
    if (list) list.innerHTML = '<div class="empty-state">Selecciona un proyecto en el menú lateral para ver las issues.</div>';
}

// Keyboard shortcut: n = new issue
document.addEventListener('keydown', function(e) {
    const tag = document.activeElement ? document.activeElement.tagName : '';
    const isEditing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                   || (document.activeElement && document.activeElement.isContentEditable);
    if (isEditing) return;
    if (e.key === 'n') {
        var btn = document.getElementById('new-issue-btn');
        if (btn) btn.click();
    }
});

// Close issue panel on Escape
document.addEventListener('app:escape', function() {
    var panel = document.getElementById('issue-detail');
    if (panel && !panel.classList.contains('hidden')) {
        panel.classList.add('hidden');
    }
});
</script>
<script src="<?= APP_URL ?>/app/assets/js/issues.js"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
