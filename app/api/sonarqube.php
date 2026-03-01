<?php
require_once __DIR__ . '/../bootstrap.php';

/* ── Encryption (same algorithm as github.php) ── */
function sonar_encrypt(string $token): string {
    $iv  = openssl_random_pseudo_bytes(16);
    $enc = openssl_encrypt($token, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}
function sonar_decrypt(string $stored): string {
    $data = base64_decode($stored);
    $iv   = substr($data, 0, 16);
    $enc  = substr($data, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
}

/* ── SonarQube HTTP helper ── */
function sonar_request(string $sonar_url, string $token, string $endpoint): array {
    $url = rtrim($sonar_url, '/') . '/api/' . $endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $response   = curl_exec($ch);
    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($response === false) return ['status' => 0, 'body' => null, 'error' => $curl_error];
    return ['status' => $status, 'body' => json_decode($response, true)];
}

/* ── Config CRUD ── */
function get_sonar_config(int $project_id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM sonarqube_projects WHERE project_id = ?');
    $stmt->execute([$project_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['sonar_token'] = sonar_decrypt($row['sonar_token']);
    return $row;
}

function save_sonar_config(int $project_id, string $sonar_url, string $token, string $project_key): array {
    if (empty($sonar_url) || empty($token) || empty($project_key)) {
        return ['success' => false, 'error' => 'Todos los campos son obligatorios'];
    }
    $encrypted = sonar_encrypt($token);
    $stmt = get_db()->prepare(
        'INSERT INTO sonarqube_projects (project_id, sonar_url, sonar_token, sonar_project_key)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE sonar_url=VALUES(sonar_url), sonar_token=VALUES(sonar_token), sonar_project_key=VALUES(sonar_project_key)'
    );
    $stmt->execute([$project_id, rtrim($sonar_url, '/'), $encrypted, $project_key]);
    return ['success' => true];
}

function delete_sonar_config(int $project_id): array {
    get_db()->prepare('DELETE FROM sonarqube_projects WHERE project_id = ?')->execute([$project_id]);
    return ['success' => true];
}

/* ── SonarQube data ── */
function get_sonar_project_status(int $project_id, string $branch = '', string $pullRequest = ''): array {
    $cfg = get_sonar_config($project_id);
    if (!$cfg) return ['success' => false, 'error' => 'SonarQube no configurado'];

    $key      = urlencode($cfg['sonar_project_key']);
    if ($pullRequest) {
        $contextQ = '&pullRequest=' . urlencode($pullRequest);
    } elseif ($branch) {
        $contextQ = '&branch=' . urlencode($branch);
    } else {
        $contextQ = '';
    }

    // Quality gate
    $qg = sonar_request($cfg['sonar_url'], $cfg['sonar_token'],
        "qualitygates/project_status?projectKey={$key}{$contextQ}");

    if ($qg['status'] === 401) return ['success' => false, 'error' => 'Token inválido (401)'];
    if ($qg['status'] === 404) return ['success' => false, 'error' => 'Proyecto no encontrado en SonarQube'];
    if ($qg['status'] !== 200) return ['success' => false, 'error' => "SonarQube error HTTP {$qg['status']}"];

    $qgStatus = $qg['body']['projectStatus']['status'] ?? 'NONE';

    // Extended metrics
    $metricKeys = implode(',', [
        // Ratings & quality gate core
        'bugs', 'vulnerabilities', 'code_smells', 'security_hotspots',
        'security_rating', 'reliability_rating', 'sqale_rating', 'security_review_rating',
        // Debt & density
        'sqale_index', 'duplicated_lines_density',
        // Coverage
        'coverage', 'lines_to_cover', 'uncovered_lines',
        'tests', 'test_success_density', 'test_failures', 'test_errors', 'skipped_tests',
        // Duplication detail
        'duplicated_lines', 'duplicated_blocks', 'duplicated_files',
        // Size
        'ncloc', 'lines', 'statements', 'functions', 'classes', 'files',
        // Complexity
        'complexity', 'cognitive_complexity',
        // New code period metrics
        'new_bugs', 'new_vulnerabilities', 'new_code_smells',
        'new_coverage', 'new_duplicated_lines_density',
    ]);

    $metrics = sonar_request($cfg['sonar_url'], $cfg['sonar_token'],
        "measures/component?component={$key}{$contextQ}&metricKeys={$metricKeys}");

    $measures = [];
    if ($metrics['status'] === 200) {
        foreach ($metrics['body']['component']['measures'] ?? [] as $m) {
            if (isset($m['value'])) {
                $measures[$m['metric']] = $m['value'];
            } elseif (!empty($m['periods'])) {
                // New-code period metrics
                $measures[$m['metric']] = $m['periods'][0]['value'] ?? '—';
            } else {
                $measures[$m['metric']] = '—';
            }
        }
    }

    // Last analysis info
    $lastAnalysis = null;
    $analyses = sonar_request($cfg['sonar_url'], $cfg['sonar_token'],
        "project_analyses/search?project={$key}&ps=1");
    if ($analyses['status'] === 200 && !empty($analyses['body']['analyses'])) {
        $a = $analyses['body']['analyses'][0];
        $lastAnalysis = [
            'date'    => $a['date']           ?? null,
            'version' => $a['projectVersion'] ?? null,
        ];
    }

    $sonarLink = $cfg['sonar_url'] . '/dashboard?id=' . urlencode($cfg['sonar_project_key']);
    if ($pullRequest) {
        $sonarLink .= '&pullRequest=' . urlencode($pullRequest);
    } elseif ($branch) {
        $sonarLink .= '&branch=' . urlencode($branch);
    }

    return [
        'success'       => true,
        'status'        => $qgStatus,
        'metrics'       => $measures,
        'url'           => $sonarLink,
        'project_key'   => $cfg['sonar_project_key'],
        'sonar_url'     => $cfg['sonar_url'],
        'last_analysis' => $lastAnalysis,
    ];
}

/* ── HTTP routing ── */
if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        $method === 'GET'  && $action === 'config'  => print json_encode((function() {
            $pid = (int)($_GET['project_id'] ?? 0);
            require_project_access($pid);
            $cfg = get_sonar_config($pid);
            if ($cfg) unset($cfg['sonar_token']); // never expose token
            return ['success' => true, 'data' => $cfg];
        })()),

        $method === 'GET'  && $action === 'status'  => print json_encode((function() {
            $pid = (int)($_GET['project_id'] ?? 0);
            $branch = $_GET['branch']       ?? '';
            $pr     = $_GET['pull_request'] ?? '';
            require_project_access($pid);
            return get_sonar_project_status($pid, $branch, $pr);
        })()),

        $method === 'GET'  && $action === 'branches' => print json_encode((function() {
            $pid = (int)($_GET['project_id'] ?? 0);
            require_project_access($pid);
            $cfg = get_sonar_config($pid);
            if (!$cfg) return ['success' => false, 'error' => 'SonarQube no configurado'];
            $key = urlencode($cfg['sonar_project_key']);

            $rb = sonar_request($cfg['sonar_url'], $cfg['sonar_token'], "project_branches/list?project={$key}");
            $branches = $rb['status'] === 200
                ? array_map(fn($b) => [
                    'name'   => $b['name'],
                    'isMain' => $b['isMain'] ?? false,
                    'status' => $b['status']['qualityGateStatus'] ?? 'NONE',
                  ], $rb['body']['branches'] ?? [])
                : [];

            $rp = sonar_request($cfg['sonar_url'], $cfg['sonar_token'], "project_pull_requests/list?project={$key}");
            $pullRequests = $rp['status'] === 200
                ? array_map(fn($pr) => [
                    'key'    => $pr['key'],
                    'title'  => $pr['title']  ?? '',
                    'branch' => $pr['branch'] ?? '',
                    'base'   => $pr['base']   ?? '',
                    'status' => $pr['status']['qualityGateStatus'] ?? 'NONE',
                  ], $rp['body']['pullRequests'] ?? [])
                : [];

            return ['success' => true, 'branches' => $branches, 'pullRequests' => $pullRequests];
        })()),

        $method === 'POST' && $action === 'save'    => print json_encode((function() use ($b) {
            $pid = (int)($b['project_id'] ?? 0);
            require_project_access($pid);
            require_role('admin');
            return save_sonar_config(
                $pid,
                trim($b['sonar_url'] ?? ''),
                trim($b['sonar_token'] ?? ''),
                trim($b['sonar_project_key'] ?? '')
            );
        })()),

        $method === 'POST' && $action === 'delete'  => print json_encode((function() use ($b) {
            $pid = (int)($b['project_id'] ?? 0);
            require_project_access($pid);
            require_role('admin');
            return delete_sonar_config($pid);
        })()),

        default => print json_encode(['success' => false, 'error' => 'Unknown action'])
    };
    exit;
}
