<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/activity.php';
function sanitize_html(string $html): string {
    $allowed = '<p><h1><h2><h3><ul><ol><li><strong><em><code><pre><a><br><blockquote><u><span><div><img><table><thead><tbody><tfoot><tr><th><td>';
    $clean = strip_tags($html, $allowed);
    // Remove dangerous event attributes (on*) and javascript: hrefs from allowed tags
    $clean = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $clean);
    $clean = preg_replace('/\s+href\s*=\s*(?:"javascript:[^"]*"|\'javascript:[^\']*\')/i', '', $clean);
    // Remove javascript: in src attributes (images)
    $clean = preg_replace('/\s+src\s*=\s*(?:"javascript:[^"]*"|\'javascript:[^\']*\')/i', '', $clean);
    return $clean;
}

function list_pages(string $scope = 'general', ?int $project_id = null): array {
    $pdo = get_db();
    if ($scope === 'project' && $project_id) {
        $stmt = $pdo->prepare('SELECT id, title, parent_id, created_by, updated_at, scope, project_id FROM pages WHERE scope = ? AND project_id = ? ORDER BY created_at');
        $stmt->execute(['project', $project_id]);
    } else {
        $stmt = $pdo->prepare('SELECT id, title, parent_id, created_by, updated_at, scope, project_id FROM pages WHERE scope = ? ORDER BY created_at');
        $stmt->execute(['general']);
    }
    return $stmt->fetchAll();
}

function get_page(int $id): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function create_page(string $title, ?int $parent_id, string $content, int $user_id, string $scope = 'general', ?int $project_id = null): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO pages (title, parent_id, content, created_by, scope, project_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $parent_id, sanitize_html($content), $user_id, $scope, $project_id]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function update_page(int $id, string $title, string $content, int $user_id): array {
    $pdo = get_db();
    // Save version snapshot first
    $current = get_page($id);
    if ($current) {
        $pdo->prepare('INSERT INTO page_versions (page_id, content, saved_by) VALUES (?, ?, ?)')
            ->execute([$id, $current['content'], $user_id]);
    }
    $pdo->prepare('UPDATE pages SET title = ?, content = ? WHERE id = ?')
        ->execute([$title, sanitize_html($content), $id]);
    return ['success' => true];
}

function delete_page_cascade(int $id): array {
    $pdo = get_db();
    // Recursively delete all descendant pages first
    $stmt = $pdo->prepare('SELECT id FROM pages WHERE parent_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
        delete_page_cascade((int)$childId);
    }
    $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

function get_page_depth(int $page_id): int {
    $pdo = get_db();
    $depth = 0;
    $cur   = $page_id;
    for ($i = 0; $i <= 5; $i++) {
        $stmt = $pdo->prepare('SELECT parent_id FROM pages WHERE id = ?');
        $stmt->execute([$cur]);
        $row = $stmt->fetch();
        if (!$row || $row['parent_id'] === null) break;
        $cur = (int)$row['parent_id'];
        $depth++;
    }
    return $depth;
}

function is_descendant(int $candidate_id, int $ancestor_id): bool {
    $pdo = get_db();
    $cur = $candidate_id;
    for ($i = 0; $i < 10; $i++) {
        if ($cur === $ancestor_id) return true;
        $stmt = $pdo->prepare('SELECT parent_id FROM pages WHERE id = ?');
        $stmt->execute([$cur]);
        $row = $stmt->fetch();
        if (!$row || $row['parent_id'] === null) break;
        $cur = (int)$row['parent_id'];
    }
    return false;
}

function move_page(int $id, ?int $parent_id): array {
    if ($parent_id !== null) {
        if ($parent_id === $id)                       return ['success' => false, 'error' => 'Una página no puede ser su propio padre'];
        if (is_descendant($parent_id, $id))           return ['success' => false, 'error' => 'No se puede mover una página dentro de sí misma'];
        if (get_page_depth($parent_id) >= 3)          return ['success' => false, 'error' => 'El padre seleccionado ya está en el nivel máximo (4 niveles)'];
    }
    get_db()->prepare('UPDATE pages SET parent_id = ? WHERE id = ?')->execute([$parent_id, $id]);
    return ['success' => true];
}

function list_page_versions(int $page_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT pv.id, pv.created_at as saved_at, u.name as saved_by_name
         FROM page_versions pv JOIN users u ON pv.saved_by = u.id
         WHERE pv.page_id = ? ORDER BY pv.created_at DESC LIMIT 20'
    );
    $stmt->execute([$page_id]);
    return $stmt->fetchAll();
}

function get_page_version(int $version_id): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM page_versions WHERE id = ?');
    $stmt->execute([$version_id]);
    return $stmt->fetch() ?: null;
}

// HTTP routing
if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'list') {
        $scope = $_GET['scope'] ?? 'general';
        $pid   = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
        echo json_encode(['success' => true, 'data' => list_pages($scope, $pid)]);
    } elseif ($method === 'GET' && $action === 'get') {
        $page = get_page((int)($_GET['id'] ?? 0));
        echo json_encode($page ? ['success' => true, 'data' => $page] : ['success' => false, 'error' => 'Not found']);
    } elseif ($method === 'POST' && $action === 'create') {
        verify_csrf();
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($b['title'])) {
            echo json_encode(['success' => false, 'error' => 'Title is required']);
        } else {
            $scope = $b['scope'] ?? 'general';
            $pid   = isset($b['project_id']) ? (int)$b['project_id'] : null;
            $result = create_page($b['title'], $b['parent_id'] ?? null, $b['content'] ?? '', current_user()['id'], $scope, $pid);
            if (!empty($result['success']) && $scope === 'project' && $pid) {
                $u = current_user();
                notify_project($pid, $u['id'], 'page_created', 'page', $result['id'], $b['title']);
            }
            echo json_encode($result);
        }
    } elseif ($method === 'POST' && $action === 'update') {
        verify_csrf();
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($b['id']) || empty($b['title'])) {
            echo json_encode(['success' => false, 'error' => 'id and title are required']);
        } else {
            $result = update_page((int)$b['id'], $b['title'], $b['content'] ?? '', current_user()['id']);
            if (!empty($result['success'])) {
                $page = get_page((int)$b['id']);
                if ($page && $page['scope'] === 'project' && $page['project_id']) {
                    $u = current_user();
                    notify_project((int)$page['project_id'], $u['id'], 'page_updated', 'page', (int)$b['id'], $b['title']);
                }
            }
            echo json_encode($result);
        }
    } elseif ($method === 'POST' && $action === 'delete') {
        verify_csrf();
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        echo json_encode(delete_page_cascade((int)($b['id'] ?? 0)));
    } elseif ($method === 'POST' && $action === 'move') {
        verify_csrf();
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $parentId = array_key_exists('parent_id', $b) && $b['parent_id'] !== null ? (int)$b['parent_id'] : null;
        echo json_encode(move_page((int)($b['id'] ?? 0), $parentId));
    } elseif ($method === 'GET' && $action === 'versions') {
        echo json_encode(['success' => true, 'data' => list_page_versions((int)($_GET['page_id'] ?? 0))]);
    } elseif ($method === 'GET' && $action === 'get_version') {
        $v = get_page_version((int)($_GET['id'] ?? 0));
        echo json_encode($v ? ['success' => true, 'data' => $v] : ['success' => false, 'error' => 'Not found']);
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
        echo json_encode(['success' => true, 'url' => $url]); exit;
    } elseif ($method === 'GET' && $action === 'search') {
        $q   = trim($_GET['q'] ?? '');
        $pid = isset($_GET['project_id']) && (int)$_GET['project_id'] > 0 ? (int)$_GET['project_id'] : null;
        if (strlen($q) < 2) { echo json_encode(['success' => true, 'data' => []]); exit; }
        if ($pid) require_project_access($pid);
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
        echo json_encode(['success' => true, 'data' => $results]); exit;
    }
    exit;
}
