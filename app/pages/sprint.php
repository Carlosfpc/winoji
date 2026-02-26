<?php
$page_title = 'Sprint';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header flex-wrap gap-2">
    <div>
        <h2 id="sprint-title">Sprint</h2>
        <div class="flex items-center gap-3 flex-wrap mt-1">
            <span id="sprint-dates" class="text-sm text-muted"></span>
            <span id="sprint-stats" class="text-sm text-muted" style="display:none;"></span>
        </div>
    </div>
    <button class="btn btn-secondary" id="manage-sprints-btn">&#9881; Gestionar sprints</button>
</div>

<div id="sprint-kanban-wrap">
    <div class="empty-state">Cargando...</div>
</div>

<!-- Backlog section -->
<div class="mt-6">
    <h4 class="section-title mb-3">&#128203; Backlog</h4>
    <div id="sprint-backlog" class="card card-flush"></div>
</div>

<!-- Sprint management modal -->
<div id="sprint-modal" class="modal hidden">
    <div class="modal-box" style="max-width:560px;max-height:80vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Gestionar sprints</h3>
            <button id="sprint-modal-close" class="btn-link text-muted" style="font-size:1.25rem;line-height:1;">&#10005;</button>
        </div>
        <!-- Create / edit form -->
        <div class="filter-bar flex-col mb-4">
            <h4 class="section-title mb-3" id="sprint-form-title">Nuevo sprint</h4>
            <input type="text" id="sprint-name-input" placeholder="Nombre del sprint"
                class="form-input w-full mb-2">
            <div class="grid-2 mb-2">
                <input type="date" id="sprint-start-input" class="form-input w-full">
                <input type="date" id="sprint-end-input" class="form-input w-full">
            </div>
            <div class="flex gap-2">
                <button class="btn btn-primary btn-sm" id="sprint-save-btn">+ Crear sprint</button>
                <button class="btn btn-secondary btn-sm" id="sprint-cancel-edit-btn" style="display:none;">Cancelar</button>
            </div>
        </div>
        <!-- Sprint list -->
        <div id="sprint-list-modal"></div>
    </div>
</div>

<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
</script>
<script src="<?= APP_URL ?>/app/assets/js/sprint.js"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
