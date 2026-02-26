<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/activity.php';

function get_dashboard_stats(int $user_id, int $project_id): array {
    $pdo = get_db();

    // Issues by status
    $stmt = $pdo->prepare('SELECT status, COUNT(*) as cnt FROM issues WHERE project_id = ? GROUP BY status');
    $stmt->execute([$project_id]);
    $byStatus = [];
    foreach ($stmt->fetchAll() as $row) $byStatus[$row['status']] = (int)$row['cnt'];

    // Issues by priority
    $stmt = $pdo->prepare('SELECT priority, COUNT(*) as cnt FROM issues WHERE project_id = ? AND status != "done" GROUP BY priority');
    $stmt->execute([$project_id]);
    $byPriority = [];
    foreach ($stmt->fetchAll() as $row) $byPriority[$row['priority']] = (int)$row['cnt'];

    // My open issues
    $stmt = $pdo->prepare('SELECT i.*, u.name as assignee_name FROM issues i LEFT JOIN users u ON i.assigned_to = u.id WHERE i.assigned_to = ? AND i.project_id = ? AND i.status != "done" ORDER BY FIELD(i.priority,"high","medium","low"), i.created_at DESC LIMIT 10');
    $stmt->execute([$user_id, $project_id]);
    $myIssues = $stmt->fetchAll();

    // Team workload: each member + their open issue count + list
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name,
            COUNT(i.id) as open_count,
            SUM(i.priority = "high") as high_count
         FROM team_members tm
         JOIN users u ON tm.user_id = u.id
         LEFT JOIN issues i ON i.assigned_to = u.id AND i.project_id = ? AND i.status != "done"
         WHERE tm.team_id = (SELECT team_id FROM team_members WHERE user_id = ? LIMIT 1)
         GROUP BY u.id, u.name
         ORDER BY open_count DESC'
    );
    $stmt->execute([$project_id, $user_id]);
    $teamWorkload = $stmt->fetchAll();

    // Open PRs (branches with pr_number, not yet merged)
    $stmt = $pdo->prepare(
        'SELECT b.branch_name, b.pr_number, b.pr_url, u.name as creator_name, i.title as issue_title, i.id as issue_id
         FROM branches b
         JOIN issues i ON b.issue_id = i.id
         JOIN users u ON b.created_by = u.id
         WHERE i.project_id = ? AND b.pr_number IS NOT NULL
         ORDER BY b.id DESC LIMIT 10'
    );
    $stmt->execute([$project_id]);
    $openPRs = $stmt->fetchAll();

    // Recent issues created (last 10)
    $stmt = $pdo->prepare(
        'SELECT i.id, i.title, i.status, i.priority, i.created_at, u.name as creator_name, a.name as assignee_name
         FROM issues i
         JOIN users u ON i.created_by = u.id
         LEFT JOIN users a ON i.assigned_to = a.id
         WHERE i.project_id = ?
         ORDER BY i.created_at DESC LIMIT 10'
    );
    $stmt->execute([$project_id]);
    $recentIssues = $stmt->fetchAll();

    // Wiki pages count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE scope = "general" OR (scope = "project" AND project_id = ?)');
    $stmt->execute([$project_id]);
    $wikiCount = (int)$stmt->fetchColumn();

    // Team members count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = (SELECT team_id FROM team_members WHERE user_id = ? LIMIT 1)');
    $stmt->execute([$user_id]);
    $membersCount = (int)$stmt->fetchColumn();

    // GitHub repo connected?
    $stmt = $pdo->prepare('SELECT repo_full_name FROM github_repos WHERE project_id = ?');
    $stmt->execute([$project_id]);
    $repo = $stmt->fetchColumn();

    // Story points total (pending issues)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(story_points), 0) FROM issues WHERE project_id = ? AND status != 'done'");
    $stmt->execute([$project_id]);
    $sp_total = (int)$stmt->fetchColumn();

    return [
        'stats'              => $byStatus,
        'by_priority'        => $byPriority,
        'my_issues'          => $myIssues,
        'team_workload'      => $teamWorkload,
        'open_prs'           => $openPRs,
        'recent_issues'      => $recentIssues,
        'wiki_count'         => $wikiCount,
        'members_count'      => $membersCount,
        'repo'               => $repo ?: null,
        'story_points_total' => $sp_total,
        'activity'           => get_recent_activity($project_id, 20),
    ];
}

function get_burndown_data(int $project_id): array {
    $pdo = get_db();

    $stmt = $pdo->prepare(
        "SELECT DATE(sl.changed_at) AS day, COALESCE(SUM(i.story_points), 0) AS points
         FROM issue_status_log sl
         JOIN issues i ON sl.issue_id = i.id
         WHERE i.project_id = ?
           AND sl.new_status = 'done'
           AND sl.changed_at >= CURDATE() - INTERVAL 29 DAY
         GROUP BY DATE(sl.changed_at)
         ORDER BY day ASC"
    );
    $stmt->execute([$project_id]);
    $byDay = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDay[$row['day']] = (int)$row['points'];
    }

    // Fill all 30 days (today - 29 days to today), oldest first
    $result = [];
    for ($i = 29; $i >= 0; $i--) {
        $day      = date('Y-m-d', strtotime("-{$i} days"));
        $result[] = ['day' => $day, 'points' => $byDay[$day] ?? 0];
    }
    return $result;
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $user       = current_user();
    $project_id = (int)($_GET['project_id'] ?? 0);
    $action     = $_GET['action'] ?? 'summary';
    if (!$project_id) { echo json_encode(['success' => false, 'error' => 'project_id required']); exit; }
    require_project_access($project_id);
    if ($action === 'burndown') {
        echo json_encode(['success' => true, 'data' => get_burndown_data($project_id)]);
    } else {
        echo json_encode(['success' => true, 'data' => get_dashboard_stats($user['id'], $project_id)]);
    }
    exit;
}
