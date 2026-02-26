<?php
$page_title = 'Dashboard';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
    <div class="flex items-center gap-3">
        <h2>Dashboard</h2>
        <span id="dash-project-name" class="text-sm text-muted"></span>
    </div>
    <button id="dash-refresh-btn" class="btn btn-secondary btn-sm">&#8635; Actualizar</button>
</div>

<!-- Stat cards row -->
<div class="dashboard-stats">
    <div class="card stat-card">
        <div class="stat-num" id="stat-todo">—</div>
        <div class="stat-label">Pendiente</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-in_progress">—</div>
        <div class="stat-label">En curso</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-review">—</div>
        <div class="stat-label">Revisión</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-done">—</div>
        <div class="stat-label">Hecho</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-prs">—</div>
        <div class="stat-label">PRs</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-wiki">—</div>
        <div class="stat-label">Páginas Wiki</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-members">—</div>
        <div class="stat-label">Miembros</div>
    </div>
    <div class="card stat-card">
        <div class="stat-num" id="stat-points">—</div>
        <div class="stat-label">Puntos pendientes</div>
    </div>
</div>

<!-- Burndown chart -->
<div class="card card-compact mb-6" style="position:relative;">
    <h4 class="section-title mb-3">&#128200; Story points cerrados (últimos 30 días)</h4>
    <div id="burndown-chart"></div>
</div>

<!-- Priority bar -->
<div class="card flex items-center gap-4 flex-wrap mb-6" style="padding:0.75rem 1rem;">
    <span class="filter-label">Issues abiertas por prioridad:</span>
    <div id="priority-bar" class="flex flex-1 items-center gap-2 flex-wrap"></div>
</div>

<!-- Main 3-column grid -->
<div class="dashboard-3col">

    <!-- My issues -->
    <div class="card card-compact">
        <h4 class="section-title mb-3">&#128100; Mis issues abiertas</h4>
        <div id="my-issues"><em class="text-sm text-muted">Cargando...</em></div>
    </div>

    <!-- Team workload -->
    <div class="card card-compact">
        <h4 class="section-title mb-3">&#128101; Carga del equipo</h4>
        <div id="team-workload"><em class="text-sm text-muted">Cargando...</em></div>
    </div>

    <!-- Open PRs -->
    <div class="card card-compact">
        <div class="section-header mb-3">
            <h4 class="section-title">&#128203; Pull Requests</h4>
            <button id="refresh-prs-btn" title="Actualizar estado de PRs" onclick="loadDashboard()" class="btn btn-secondary btn-xs">&#8635; Actualizar</button>
        </div>
        <div id="open-prs"><em class="text-sm text-muted">Cargando...</em></div>
    </div>

</div>

<!-- Recent issues full width -->
<div class="card card-compact mb-5">
    <h4 class="section-title mb-3">&#128336; Issues recientes</h4>
    <div id="recent-issues"></div>
</div>

<!-- Activity feed full width -->
<div class="card card-compact">
    <h4 class="section-title mb-3">&#9889; Feed de actividad</h4>
    <div id="activity-feed"><em class="text-sm text-muted">Cargando...</em></div>
</div>

<script src="<?= APP_URL ?>/app/assets/js/dashboard.js"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
