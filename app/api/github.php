<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/logger.php';

function encrypt_token(string $token): string {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($token, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt_token(string $stored): string {
    $data = base64_decode($stored);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
}

function github_request(string $token, string $endpoint, string $method = 'GET', array $body = []): array {
    $ch = curl_init("https://api.github.com/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_TIMEOUT          => 15,
        CURLOPT_CONNECTTIMEOUT   => 5,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Accept: application/vnd.github+json",
            "User-Agent: TeamApp/1.0",
            "X-GitHub-Api-Version: 2022-11-28"
        ],
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        app_log('error', 'GitHub API error', ['endpoint' => $endpoint, 'status' => 0, 'body' => $curl_error]);
        return ['status' => 0, 'body' => null, 'error' => $curl_error];
    }
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        app_log('error', 'GitHub API error', ['endpoint' => $endpoint, 'status' => $status, 'body' => substr(json_encode($decoded), 0, 500)]);
    }
    return ['status' => $status, 'body' => $decoded];
}

function connect_repo(int $project_id, string $repo_full_name, string $access_token): array {
    // Validate token against GitHub before saving
    $check = github_request($access_token, "repos/$repo_full_name");
    if ($check['status'] === 401) {
        return ['success' => false, 'error' => 'Invalid token — GitHub rejected it (401 Unauthorized). Check that the token is correct.'];
    }
    if ($check['status'] === 403) {
        $msg = $check['body']['message'] ?? '';
        return ['success' => false, 'error' => "Token lacks permissions: $msg. For Classic PATs enable the 'repo' scope. For Fine-grained PATs enable Contents (read & write) on this repository."];
    }
    if ($check['status'] === 404) {
        return ['success' => false, 'error' => "Repository '$repo_full_name' not found. Check the name (owner/repo) and that the token has access to it."];
    }
    if ($check['status'] !== 200) {
        $msg = $check['body']['message'] ?? "HTTP {$check['status']}";
        return ['success' => false, 'error' => "GitHub error: $msg"];
    }

    $pdo = get_db();
    $encrypted = encrypt_token($access_token);
    $stmt = $pdo->prepare('INSERT INTO github_repos (project_id, repo_full_name, access_token) VALUES (?,?,?) ON DUPLICATE KEY UPDATE repo_full_name=VALUES(repo_full_name), access_token=VALUES(access_token)');
    $stmt->execute([$project_id, $repo_full_name, $encrypted]);
    return ['success' => true, 'repo' => $check['body']['full_name'], 'default_branch' => $check['body']['default_branch'] ?? 'main'];
}

function list_repo_branches(int $project_id, int $user_id): array {
    $repo = get_repo_for_project($project_id);
    if (!$repo) return ['success' => false, 'error' => 'No repo connected'];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return ['success' => false, 'error' => 'No token available'];
    $result = github_request($token, "repos/{$repo['repo_full_name']}/branches?per_page=100");
    if ($result['status'] !== 200) return ['success' => false, 'error' => $result['body']['message'] ?? 'Could not list branches'];
    $branches = array_column($result['body'], 'name');
    return ['success' => true, 'data' => $branches, 'default' => $repo['repo_full_name']];
}

function disconnect_repo(int $project_id): array {
    $pdo = get_db();
    $pdo->prepare('DELETE FROM github_repos WHERE project_id = ?')->execute([$project_id]);
    return ['success' => true];
}

function get_repo_for_project(int $project_id): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM github_repos WHERE project_id = ?');
    $stmt->execute([$project_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['access_token'] = decrypt_token($row['access_token']);
    return $row;
}

function get_user_github_token(int $user_id): ?string {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT github_token FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['github_token']) return null;
    return decrypt_token($row['github_token']);
}

function create_branch_for_issue(int $issue_id, string $branch_name, int $user_id, string $base_branch = ''): array {
    $pdo = get_db();
    $issue = get_issue($issue_id);
    if (!$issue) return ['success' => false, 'error' => 'Issue not found'];

    $repo = get_repo_for_project($issue['project_id']);
    if (!$repo) return ['success' => false, 'error' => 'No GitHub repo connected to this project'];

    // Use project token, fallback to user's personal token
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return ['success' => false, 'error' => 'No GitHub token available. Set one in your Profile.'];

    $repoData = github_request($token, "repos/{$repo['repo_full_name']}");
    if ($repoData['status'] !== 200) {
        $msg = $repoData['body']['message'] ?? 'Could not access GitHub repo';
        return ['success' => false, 'error' => "GitHub error: $msg"];
    }

    $defaultBranch = $base_branch ?: ($repoData['body']['default_branch'] ?? 'main');
    $refData = github_request($token, "repos/{$repo['repo_full_name']}/git/ref/heads/$defaultBranch");
    if ($refData['status'] === 404) {
        return ['success' => false, 'error' => "The repository appears to be empty (no commits on '$defaultBranch'). Push an initial commit to the repo before creating branches."];
    }
    if ($refData['status'] !== 200) {
        $msg = $refData['body']['message'] ?? "Could not read branch '$defaultBranch'";
        return ['success' => false, 'error' => "GitHub error reading base branch: $msg"];
    }

    $sha = $refData['body']['object']['sha'] ?? null;
    if (!$sha) return ['success' => false, 'error' => 'Could not get branch SHA'];

    $result = github_request($token, "repos/{$repo['repo_full_name']}/git/refs", 'POST', [
        'ref' => "refs/heads/$branch_name",
        'sha' => $sha
    ]);

    if ($result['status'] !== 201) {
        $msg = $result['body']['message'] ?? 'GitHub error creating branch';
        if (str_contains($msg, 'Resource not accessible')) {
            $msg = "Token lacks write permissions. For Classic PATs enable the 'repo' scope. For Fine-grained PATs enable Contents (read & write).";
        } elseif (str_contains($msg, 'Reference already exists')) {
            $msg = "Branch '$branch_name' already exists in the repository.";
        } elseif (str_contains($msg, 'Reference update failed') || str_contains($msg, 'update_ref')) {
            $msg = "Branch '$branch_name' could not be created. The branch may already exist in GitHub — try a different name.";
        }
        return ['success' => false, 'error' => $msg];
    }

    $pdo->prepare('INSERT INTO branches (issue_id, branch_name, created_by) VALUES (?,?,?)')
        ->execute([$issue_id, $branch_name, $user_id]);

    return ['success' => true, 'branch' => $branch_name];
}

function create_pull_request(int $issue_id, string $head_branch, string $title, string $body, int $user_id, string $base_branch = 'main'): array {
    $pdo = get_db();
    $issue = get_issue($issue_id);
    if (!$issue) return ['success' => false, 'error' => 'Issue not found'];
    $repo = get_repo_for_project($issue['project_id']);
    if (!$repo) return ['success' => false, 'error' => 'No GitHub repo connected'];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return ['success' => false, 'error' => 'No GitHub token available'];

    $result = github_request($token, "repos/{$repo['repo_full_name']}/pulls", 'POST', [
        'title' => $title,
        'body'  => $body,
        'head'  => $head_branch,
        'base'  => $base_branch ?: 'main',
    ]);

    if ($result['status'] !== 201) {
        $msg = $result['body']['message'] ?? 'GitHub error';
        $errors = $result['body']['errors'][0]['message'] ?? '';
        return ['success' => false, 'error' => $errors ?: $msg];
    }

    $pr_number = $result['body']['number'];
    $pr_url    = $result['body']['html_url'];
    $pdo->prepare('UPDATE branches SET pr_number = ?, pr_url = ? WHERE issue_id = ? AND branch_name = ?')
        ->execute([$pr_number, $pr_url, $issue_id, $head_branch]);

    return ['success' => true, 'pr_number' => $pr_number, 'pr_url' => $pr_url];
}

function merge_pull_request(int $issue_id, int $pr_number, string $merge_method, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT project_id FROM issues WHERE id = ?');
    $stmt->execute([$issue_id]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'Issue not found'];
    $repo = get_repo_for_project((int)$row['project_id']);
    if (!$repo) return ['success' => false, 'error' => 'No repo connected'];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return ['success' => false, 'error' => 'No token'];

    // Check mergeability before attempting merge
    $pr = github_request($token, "repos/{$repo['repo_full_name']}/pulls/{$pr_number}");
    if ($pr['status'] !== 200) {
        return ['success' => false, 'error' => 'Could not load PR details'];
    }
    $prData    = $pr['body'];
    $mergeable = $prData['mergeable'];       // true | false | null (GitHub computing)
    $state     = $prData['mergeable_state']; // clean | dirty | blocked | behind | unknown
    $pr_url    = $prData['html_url'] ?? '';

    // If GitHub is still computing mergeability, wait and retry once
    if ($mergeable === null) {
        sleep(2);
        $pr2 = github_request($token, "repos/{$repo['repo_full_name']}/pulls/{$pr_number}");
        if ($pr2['status'] === 200) {
            $mergeable = $pr2['body']['mergeable'];
            $state     = $pr2['body']['mergeable_state'];
        }
    }

    if ($mergeable === false || $state === 'dirty') {
        return [
            'success'  => false,
            'conflict' => true,
            'pr_url'   => $pr_url,
            'error'    => 'La PR tiene conflictos con la rama destino. Debes resolverlos antes de hacer merge.',
        ];
    }

    if ($state === 'blocked') {
        return [
            'success' => false,
            'pr_url'  => $pr_url,
            'error'   => 'Merge bloqueado: puede requerir revisiones aprobadas o que pasen los checks de CI.',
        ];
    }

    $result = github_request($token, "repos/{$repo['repo_full_name']}/pulls/{$pr_number}/merge", 'PUT', [
        'merge_method' => in_array($merge_method, ['merge', 'squash', 'rebase']) ? $merge_method : 'merge',
    ]);

    if ($result['status'] === 200) {
        return ['success' => true, 'message' => $result['body']['message'] ?? 'Pull request merged'];
    }
    $msg = $result['body']['message'] ?? 'GitHub error';
    return ['success' => false, 'pr_url' => $pr_url, 'error' => $msg];
}

function get_pr_diff(int $issue_id, int $pr_number, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT project_id FROM issues WHERE id = ?');
    $stmt->execute([$issue_id]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'Issue not found'];
    $repo = get_repo_for_project((int)$row['project_id']);
    if (!$repo) return ['success' => false, 'error' => 'No repo connected'];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return ['success' => false, 'error' => 'No token'];

    $result = github_request($token, "repos/{$repo['repo_full_name']}/pulls/{$pr_number}/files?per_page=50");
    if ($result['status'] !== 200) return ['success' => false, 'error' => $result['body']['message'] ?? 'GitHub error'];

    $files = array_map(fn($f) => [
        'filename'  => $f['filename'],
        'status'    => $f['status'],   // added, modified, removed, renamed
        'additions' => $f['additions'],
        'deletions' => $f['deletions'],
        'patch'     => $f['patch'] ?? null,
    ], $result['body']);

    return ['success' => true, 'files' => $files];
}

function get_issue_branches(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT b.*, u.name as creator_name FROM branches b JOIN users u ON b.created_by = u.id WHERE b.issue_id = ?');
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll();
}

function get_issue_branches_live(int $issue_id, int $user_id): array {
    $branches = get_issue_branches($issue_id);
    if (!$branches) return [];

    // Get the issue's project to find the connected repo
    $pdo = get_db();
    $issue = $pdo->prepare('SELECT project_id FROM issues WHERE id = ?');
    $issue->execute([$issue_id]);
    $row = $issue->fetch();
    if (!$row) return $branches;

    $repo = get_repo_for_project((int)$row['project_id']);
    if (!$repo) return $branches;
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return $branches;

    // Fetch all branches from GitHub to check existence
    $result = github_request($token, "repos/{$repo['repo_full_name']}/branches?per_page=100");
    if ($result['status'] !== 200) return $branches;

    $liveBranches = array_column($result['body'], 'name');

    // Filter: only return branches that still exist in GitHub; remove deleted ones from DB
    $active = [];
    foreach ($branches as $b) {
        if (in_array($b['branch_name'], $liveBranches)) {
            $active[] = $b;
        } elseif (empty($b['pr_number'])) {
            // Branch deleted in GitHub and no PR linked — safe to remove from DB
            $pdo->prepare('DELETE FROM branches WHERE id = ?')->execute([$b['id']]);
        }
        // If branch has a PR, keep the DB record so we can still view its diff
    }
    return $active;
}

function list_issue_prs(int $issue_id, int $user_id): array {
    $pdo = get_db();

    $stmt = $pdo->prepare('SELECT b.*, i.project_id FROM branches b JOIN issues i ON b.issue_id = i.id WHERE b.issue_id = ?');
    $stmt->execute([$issue_id]);
    $branches = $stmt->fetchAll();
    if (!$branches) return [];

    $project_id = $branches[0]['project_id'];
    $repo = get_repo_for_project($project_id);
    if (!$repo) return [];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return [];

    $prs  = [];
    $seen = [];

    // Primary source: stored pr_number in branches table (fast, always correct)
    foreach ($branches as $branch) {
        if (empty($branch['pr_number'])) continue;
        $num = (int)$branch['pr_number'];
        if (isset($seen[$num])) continue;
        $result = github_request($token, "repos/{$repo['repo_full_name']}/pulls/{$num}");
        if ($result['status'] === 200) {
            $pr = $result['body'];
            $seen[$num] = true;
            $prs[] = [
                'number'     => $pr['number'],
                'title'      => $pr['title'],
                'state'      => $pr['state'],
                'merged'     => !empty($pr['merged_at']),
                'url'        => $pr['html_url'],
                'author'     => $pr['user']['login'] ?? '',
                'created_at' => $pr['created_at'],
                'head'       => $pr['head']['ref'] ?? $branch['branch_name'],
                'base'       => $pr['base']['ref'] ?? 'main',
            ];
        }
    }

    // Secondary source: discover PRs created outside the app via branch head lookup
    foreach ($branches as $branch) {
        $head   = rawurlencode($repo['repo_full_name'] . ':' . $branch['branch_name']);
        $result = github_request($token, "repos/{$repo['repo_full_name']}/pulls?head={$head}&state=all&per_page=10");
        if ($result['status'] === 200 && !empty($result['body'])) {
            foreach ($result['body'] as $pr) {
                if (isset($seen[$pr['number']])) continue;
                $seen[$pr['number']] = true;
                $prs[] = [
                    'number'     => $pr['number'],
                    'title'      => $pr['title'],
                    'state'      => $pr['state'],
                    'merged'     => !empty($pr['merged_at']),
                    'url'        => $pr['html_url'],
                    'author'     => $pr['user']['login'] ?? '',
                    'created_at' => $pr['created_at'],
                    'head'       => $pr['head']['ref'] ?? $branch['branch_name'],
                    'base'       => $pr['base']['ref'] ?? 'main',
                ];
            }
        }
    }

    return $prs;
}

function get_branch_commits(int $issue_id, string $branch_name, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT i.project_id FROM issues i JOIN branches b ON b.issue_id = i.id WHERE b.issue_id = ? LIMIT 1');
    $stmt->execute([$issue_id]);
    $row = $stmt->fetch();
    if (!$row) return [];

    $repo = get_repo_for_project($row['project_id']);
    if (!$repo) return [];
    $token = $repo['access_token'] ?: get_user_github_token($user_id);
    if (!$token) return [];

    $result = github_request($token, "repos/{$repo['repo_full_name']}/commits?sha=" . urlencode($branch_name) . "&per_page=10");
    if ($result['status'] !== 200) return [];

    return array_map(fn($c) => [
        'sha'     => substr($c['sha'], 0, 7),
        'message' => explode("\n", $c['commit']['message'])[0],
        'author'  => $c['commit']['author']['name'] ?? '',
        'date'    => $c['commit']['author']['date'] ?? '',
        'url'     => $c['html_url'] ?? '',
    ], $result['body'] ?? []);
}

function sync_pr_status_to_issue(int $issue_id, int $user_id): array {
    $prs = list_issue_prs($issue_id, $user_id);
    if (empty($prs)) return ['synced' => false, 'reason' => 'no_prs'];

    foreach ($prs as $pr) {
        if ($pr['merged']) {
            update_issue($issue_id, ['status' => 'done']);
            return ['synced' => true, 'new_status' => 'done', 'reason' => 'pr_merged'];
        }
        if ($pr['state'] === 'closed' && !$pr['merged']) {
            update_issue($issue_id, ['status' => 'todo']);
            return ['synced' => true, 'new_status' => 'todo', 'reason' => 'pr_closed'];
        }
        if ($pr['state'] === 'open') {
            update_issue($issue_id, ['status' => 'review']);
            return ['synced' => true, 'new_status' => 'review', 'reason' => 'pr_open'];
        }
    }
    return ['synced' => false, 'reason' => 'no_change'];
}

// HTTP routing
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/issues.php';
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'POST') { verify_csrf(); }
    match(true) {
        $method === 'POST' && $action === 'connect_repo'    => print json_encode(!empty($b['project_id']) && !empty($b['repo_full_name']) && !empty($b['access_token']) ? connect_repo((int)$b['project_id'], $b['repo_full_name'], $b['access_token']) : ['success'=>false,'error'=>'Missing required fields']),
        $method === 'POST' && $action === 'disconnect_repo' => print json_encode(!empty($b['project_id']) ? disconnect_repo((int)$b['project_id']) : ['success'=>false,'error'=>'Missing project_id']),
        $method === 'POST' && $action === 'create_branch'  => print json_encode(!empty($b['issue_id']) && !empty($b['branch_name']) ? create_branch_for_issue((int)$b['issue_id'], $b['branch_name'], current_user()['id'], $b['base_branch'] ?? '') : ['success'=>false,'error'=>'Missing required fields']),
        $method === 'POST' && $action === 'create_pr'      => print json_encode(!empty($b['issue_id']) && !empty($b['branch']) && !empty($b['title']) ? create_pull_request((int)$b['issue_id'], $b['branch'], $b['title'], $b['body'] ?? '', current_user()['id'], $b['base'] ?? 'main') : ['success'=>false,'error'=>'Missing required fields']),
        $method === 'POST' && $action === 'merge_pr'       => print json_encode(!empty($b['issue_id']) && !empty($b['pr_number']) ? merge_pull_request((int)$b['issue_id'], (int)$b['pr_number'], $b['merge_method'] ?? 'merge', current_user()['id']) : ['success'=>false,'error'=>'Missing required fields']),
        $method === 'GET'  && $action === 'pr_diff'        => print json_encode(get_pr_diff((int)($_GET['issue_id']??0), (int)($_GET['pr_number']??0), current_user()['id'])),
        $method === 'GET'  && $action === 'repo_status'    => print json_encode((function() { $r = get_repo_for_project((int)($_GET['project_id']??0)); return ['connected' => (bool)$r, 'repo' => $r ? $r['repo_full_name'] : null]; })()),
        $method === 'GET'  && $action === 'repo_branches'  => print json_encode(list_repo_branches((int)($_GET['project_id']??0), current_user()['id'])),
        $method === 'GET'  && $action === 'branches'       => print json_encode(['success'=>true,'data'=>get_issue_branches_live((int)($_GET['issue_id']??0), current_user()['id'])]),
        $method === 'GET'  && $action === 'prs'            => print json_encode(['success'=>true,'data'=>list_issue_prs((int)($_GET['issue_id']??0), current_user()['id'])]),
        $method === 'GET'  && $action === 'commits'        => print json_encode(['success'=>true,'data'=>get_branch_commits((int)($_GET['issue_id']??0), $_GET['branch']??'', current_user()['id'])]),
        $method === 'POST' && $action === 'sync_pr_status' => print json_encode(array_merge(sync_pr_status_to_issue((int)($b['issue_id']??0), current_user()['id']), ['success'=>true])),
        default => null
    };
    exit;
}
