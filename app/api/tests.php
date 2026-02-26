<?php
require_once __DIR__ . '/../bootstrap.php';
ini_set('display_errors', '0');

require_auth();
header('Content-Type: application/json');

$pdo    = get_db();
$user   = current_user();
$uid    = (int)$user['id'];
$action = $_GET['action'] ?? '';

// ── list: GET ?action=list&issue_id=N ────────────────────────────────────────
if ($action === 'list') {
    $issue_id = (int)($_GET['issue_id'] ?? 0);
    if (!$issue_id) { echo json_encode(['success' => false, 'error' => 'issue_id requerido']); exit; }

    $stmt = $pdo->prepare("
        SELECT tc.*,
               u.name   AS assignee_name,
               u.avatar AS assignee_avatar,
               cu.name  AS creator_name,
               (SELECT te.result
                FROM test_executions te
                WHERE te.test_case_id = tc.id
                ORDER BY te.executed_at DESC LIMIT 1) AS last_result,
               (SELECT te.executed_at
                FROM test_executions te
                WHERE te.test_case_id = tc.id
                ORDER BY te.executed_at DESC LIMIT 1) AS last_executed_at,
               (SELECT COUNT(*)
                FROM test_executions te
                WHERE te.test_case_id = tc.id)        AS execution_count
        FROM test_cases tc
        LEFT JOIN users u  ON u.id  = tc.assignee_id
        LEFT JOIN users cu ON cu.id = tc.created_by
        WHERE tc.issue_id = ?
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$issue_id]);
    $cases = $stmt->fetchAll();

    foreach ($cases as &$tc) {
        $s = $pdo->prepare('SELECT * FROM test_steps WHERE test_case_id = ? ORDER BY sort_order ASC, id ASC');
        $s->execute([$tc['id']]);
        $steps = $s->fetchAll();
        foreach ($steps as &$step) {
            $imgs = $pdo->prepare('SELECT image FROM test_step_images WHERE step_id = ? ORDER BY sort_order ASC, id ASC');
            $imgs->execute([$step['id']]);
            $step['images'] = array_column($imgs->fetchAll(), 'image');
        }
        unset($step);
        $tc['steps'] = $steps;
    }
    unset($tc);

    echo json_encode(['success' => true, 'data' => $cases]);
    exit;
}

// ── create: POST ?action=create ──────────────────────────────────────────────
if ($action === 'create') {
    verify_csrf();
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id   = (int)($data['issue_id']   ?? 0);
    $title      = trim($data['title']       ?? '');
    $assignee   = !empty($data['assignee_id']) ? (int)$data['assignee_id'] : null;
    $steps      = $data['steps']            ?? [];

    if (!$issue_id || !$title) {
        echo json_encode(['success' => false, 'error' => 'issue_id y title son requeridos']); exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO test_cases (issue_id, title, assignee_id, created_by) VALUES (?,?,?,?)')
            ->execute([$issue_id, $title, $assignee, $uid]);
        $tc_id = (int)$pdo->lastInsertId();

        foreach ($steps as $i => $step) {
            $act = trim($step['action'] ?? '');
            $exp = trim($step['expected_result'] ?? '');
            if (!$act) continue;
            $pdo->prepare('INSERT INTO test_steps (test_case_id, sort_order, action, expected_result) VALUES (?,?,?,?)')
                ->execute([$tc_id, $i, $act, $exp ?: null]);
            $step_id = (int)$pdo->lastInsertId();
            foreach ($step['images'] ?? [] as $j => $img) {
                $img = trim($img);
                if (!$img || !str_starts_with($img, 'data:image/')) continue;
                if (strlen($img) > 5000000) continue;
                $pdo->prepare('INSERT INTO test_step_images (step_id, sort_order, image) VALUES (?,?,?)')
                    ->execute([$step_id, $j, $img]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $tc_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── update: POST ?action=update ──────────────────────────────────────────────
if ($action === 'update') {
    verify_csrf();
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($data['id']          ?? 0);
    $title    = trim($data['title']        ?? '');
    $assignee = !empty($data['assignee_id']) ? (int)$data['assignee_id'] : null;
    $steps    = $data['steps']             ?? [];

    if (!$id || !$title) {
        echo json_encode(['success' => false, 'error' => 'id y title son requeridos']); exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE test_cases SET title = ?, assignee_id = ? WHERE id = ?')
            ->execute([$title, $assignee, $id]);
        $pdo->prepare('DELETE FROM test_steps WHERE test_case_id = ?')->execute([$id]);

        foreach ($steps as $i => $step) {
            $act = trim($step['action'] ?? '');
            $exp = trim($step['expected_result'] ?? '');
            if (!$act) continue;
            $pdo->prepare('INSERT INTO test_steps (test_case_id, sort_order, action, expected_result) VALUES (?,?,?,?)')
                ->execute([$id, $i, $act, $exp ?: null]);
            $step_id = (int)$pdo->lastInsertId();
            foreach ($step['images'] ?? [] as $j => $img) {
                $img = trim($img);
                if (!$img || !str_starts_with($img, 'data:image/')) continue;
                if (strlen($img) > 5000000) continue;
                $pdo->prepare('INSERT INTO test_step_images (step_id, sort_order, image) VALUES (?,?,?)')
                    ->execute([$step_id, $j, $img]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── delete: POST ?action=delete ──────────────────────────────────────────────
if ($action === 'delete') {
    verify_csrf();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'id requerido']); exit; }
    $pdo->prepare('DELETE FROM test_cases WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── execute: POST ?action=execute ────────────────────────────────────────────
if ($action === 'execute') {
    verify_csrf();
    $data         = json_decode(file_get_contents('php://input'), true) ?? [];
    $test_case_id = (int)($data['test_case_id'] ?? 0);
    $step_results = $data['step_results']        ?? [];

    if (!$test_case_id || empty($step_results)) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos']); exit;
    }

    $overall = 'pass';
    foreach ($step_results as $sr) {
        if (($sr['result'] ?? '') === 'fail') { $overall = 'fail'; break; }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO test_executions (test_case_id, executed_by, result) VALUES (?,?,?)')
            ->execute([$test_case_id, $uid, $overall]);
        $exec_id = (int)$pdo->lastInsertId();

        foreach ($step_results as $sr) {
            $step_id = (int)($sr['step_id'] ?? 0);
            $result  = in_array($sr['result'] ?? '', ['pass','fail','skip']) ? $sr['result'] : 'skip';
            $comment = trim($sr['comment'] ?? '');
            if (!$step_id) continue;
            $pdo->prepare('INSERT INTO test_execution_steps (execution_id, step_id, result, comment) VALUES (?,?,?,?)')
                ->execute([$exec_id, $step_id, $result, $comment ?: null]);
            $exec_step_id = (int)$pdo->lastInsertId();

            foreach ($sr['images'] ?? [] as $img) {
                $img = trim($img);
                if (!$img || !str_starts_with($img, 'data:image/')) continue;
                if (strlen($img) > 5000000) continue; // ~3.75MB raw image limit
                $pdo->prepare('INSERT INTO test_evidence (execution_step_id, image) VALUES (?,?)')
                    ->execute([$exec_step_id, $img]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'execution_id' => $exec_id, 'result' => $overall]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── executions: GET ?action=executions&test_case_id=N ────────────────────────
if ($action === 'executions') {
    $tc_id = (int)($_GET['test_case_id'] ?? 0);
    if (!$tc_id) { echo json_encode(['success' => false, 'error' => 'test_case_id requerido']); exit; }
    $stmt = $pdo->prepare('
        SELECT te.*, u.name AS executor_name, u.avatar AS executor_avatar
        FROM test_executions te
        JOIN users u ON u.id = te.executed_by
        WHERE te.test_case_id = ?
        ORDER BY te.executed_at DESC
    ');
    $stmt->execute([$tc_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

// ── execution_detail: GET ?action=execution_detail&execution_id=N ────────────
if ($action === 'execution_detail') {
    $exec_id = (int)($_GET['execution_id'] ?? 0);
    if (!$exec_id) { echo json_encode(['success' => false, 'error' => 'execution_id requerido']); exit; }
    $stmt = $pdo->prepare('
        SELECT tes.*, ts.action, ts.expected_result, ts.sort_order
        FROM test_execution_steps tes
        JOIN test_steps ts ON ts.id = tes.step_id
        WHERE tes.execution_id = ?
        ORDER BY ts.sort_order ASC, ts.id ASC
    ');
    $stmt->execute([$exec_id]);
    $steps = $stmt->fetchAll();

    foreach ($steps as &$step) {
        $ev = $pdo->prepare('SELECT id, image FROM test_evidence WHERE execution_step_id = ? ORDER BY created_at ASC');
        $ev->execute([$step['id']]);
        $step['evidence'] = $ev->fetchAll();
    }
    unset($step);

    echo json_encode(['success' => true, 'data' => $steps]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
