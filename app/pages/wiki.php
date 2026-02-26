<?php
$page_title = 'Wiki';
$extra_head = '
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.js"></script>
';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="wiki-layout">
    <aside class="wiki-sidebar">
        <!-- Tabs -->
        <div class="wiki-tabs">
            <button id="tab-general" class="wiki-tab active" onclick="switchTab('general')">
                🌐 General
            </button>
            <button id="tab-project" class="wiki-tab" onclick="switchTab('project')">
                📁 Proyecto
            </button>
        </div>
        <div class="wiki-sidebar-header">
            <strong id="tab-label">Páginas generales</strong>
            <button class="btn btn-primary btn-xs" id="new-page-btn">+ Nueva</button>
        </div>
        <div class="wiki-search-wrap">
            <input type="text" id="wiki-search-input" class="form-input" placeholder="Buscar en wiki..." autocomplete="off">
        </div>
        <div id="wiki-search-results" class="hidden"></div>
        <div id="pages-list"></div>
    </aside>
    <div class="wiki-editor">
        <div id="editor-placeholder" class="empty-state">Selecciona una página o crea una nueva.</div>
        <div id="editor-area" class="hidden">
            <!-- Breadcrumb -->
            <div id="page-breadcrumb" class="breadcrumb mb-2"></div>
            <input type="text" id="page-title" placeholder="Título de la página"
                class="wiki-page-title">

            <!-- ── View mode (default) ───────────────────────────── -->
            <div id="wiki-view-area">
                <div id="wiki-content-view" class="ql-editor wiki-content-view"></div>
                <div class="editor-actions flex items-center gap-2 mt-2">
                    <button onclick="toggleHistory()" class="btn btn-secondary btn-sm">📋 Historial</button>
                    <button onclick="showMoveModal()" class="btn btn-secondary btn-sm">📂 Mover</button>
                    <button onclick="enterEditMode()" class="btn btn-primary btn-sm ml-auto">✏️ Editar</button>
                </div>
            </div>

            <!-- ── Edit mode (hidden by default) ────────────────── -->
            <div id="wiki-edit-area" class="hidden">
                <div id="quill-wrap">
                    <div id="quill-editor"></div>
                </div>
                <div class="editor-actions flex items-center gap-2 mt-2">
                    <div id="save-status" class="text-xs text-muted flex-1"></div>
                    <button onclick="savePage(true)" id="save-btn" class="btn btn-primary btn-sm">💾 Guardar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History panel -->
<div id="history-panel" class="side-panel hidden">
    <div class="side-panel-header">
        <strong>Historial de versiones</strong>
        <button onclick="toggleHistory()" class="btn-link" style="font-size:1.25rem;line-height:1;">×</button>
    </div>
    <div id="history-list"></div>
</div>

<!-- Move page modal -->
<div id="move-page-modal" class="modal hidden">
    <div class="modal-box" style="max-width:400px;">
        <h3 id="move-page-modal-title" class="mb-4">Mover página</h3>
        <label class="form-label">Nuevo padre:</label>
        <select id="move-parent-select" class="form-select w-full mb-4"></select>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="move-cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="move-confirm-btn">Mover</button>
        </div>
    </div>
</div>

<!-- Wiki mention dropdown -->
<div id="wiki-mention-dropdown" class="mention-dropdown" style="display:none;"></div>

<script>
const PROJECT_ID = parseInt(localStorage.getItem('active_project_id') || '0');
</script>
<script src="<?= APP_URL ?>/app/assets/js/wiki.js"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
