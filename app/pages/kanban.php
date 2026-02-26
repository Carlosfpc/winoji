<?php
$page_title = 'Kanban';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
    <h2>Kanban Board</h2>
    <button class="btn btn-primary" id="new-issue-btn">+ Nueva Issue</button>
</div>

<!-- Filter bar -->
<div id="filter-bar" class="filter-bar">
    <span class="filter-label">Filtros:</span>
    <select id="filter-priority" class="form-select form-select-sm">
        <option value="">Prioridad: Todas</option>
        <option value="low">Baja</option>
        <option value="medium">Media</option>
        <option value="high">Alta</option>
        <option value="critical">Crítica</option>
    </select>
    <select id="filter-assignee" class="form-select form-select-sm" style="min-width:130px;">
        <option value="">Asignado: Todos</option>
        <option value="none">Sin asignar</option>
    </select>
    <button id="filter-clear" class="btn btn-secondary btn-sm">&#10005; Limpiar</button>
    <span id="filter-count" class="text-xs text-muted ml-auto"></span>
</div>

<div id="kanban-cap-warning" class="kanban-cap-warning"></div>
<div id="kanban-board" class="kanban-board"></div>

<!-- New Issue Modal -->
<div id="issue-modal" class="modal hidden">
    <div class="modal-box">
        <h3 class="mb-4">Nueva Issue</h3>
        <div class="form-group">
            <input type="text" id="issue-title" placeholder="Título" class="form-input w-full">
        </div>
        <div class="form-group">
            <textarea id="issue-desc" placeholder="Descripción" class="form-textarea w-full" style="height:100px;"></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="modal-cancel">Cancelar</button>
            <button class="btn btn-primary" id="modal-save">Crear</button>
        </div>
    </div>
</div>

<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
if (!PROJECT_ID) {
    const board = document.getElementById('kanban-board');
    if (board) board.innerHTML = '<div class="empty-state">Selecciona un proyecto en el menú lateral para ver el tablero.</div>';
    const newBtn = document.getElementById('new-issue-btn');
    if (newBtn) { newBtn.disabled = true; newBtn.title = 'Selecciona un proyecto primero'; }
}
</script>
<script src="<?= APP_URL ?>/app/assets/js/kanban.js"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
