<?php

// Router for a fake Codebase API, run with: php -S 127.0.0.1:<port> <this file>
// (with PHP_CLI_SERVER_WORKERS set, so requests are served in parallel)
// Logs every request as a JSON line to the file named by FAKE_LOG.
// Credentials accepted: acme/peter : good-key. Project "broken" always fails; "missing" does not exist (404 [] everywhere); searches in "quiet" have no matches (404 []).

$startedAt = microtime(TRUE);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// A slow endpoint, to test time limits. Logged after the wait.
if ($path === '/slow/tickets.json') {
  sleep(3);
}

$user = $pass = NULL;
if (preg_match('/^Basic\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
  [$user, $pass] = array_pad(explode(':', base64_decode($m[1]), 2), 2, NULL);
}

file_put_contents(getenv('FAKE_LOG'), json_encode([
  'method' => $method,
  'path' => $path,
  'query' => $_GET,
  'user' => $user,
  'pass' => $pass,
  'accept' => $_SERVER['HTTP_ACCEPT'] ?? NULL,
  'started' => $startedAt,
  'finished' => microtime(TRUE),
  'body' => json_decode(file_get_contents('php://input'), TRUE),
]) . "\n", FILE_APPEND | LOCK_EX);

if ($user !== 'acme/peter' || $pass !== 'good-key') {
  http_response_code(401);
  echo "HTTP Basic: Access denied.\n";
  return;
}

if (str_starts_with($path, '/broken/')) {
  http_response_code(500);
  echo 'kaboom';
  return;
}

if ($path === '/missing.json' || str_starts_with($path, '/missing/')) {
  http_response_code(404);
  echo '[]';
  return;
}

// Codebase answers a ticket search without matches with 404 and [].
if ($path === '/quiet/tickets.json') {
  http_response_code(404);
  echo '[]';
  return;
}

header('Content-Type: application/json');

/**
 * A ticket shaped like the real JSON API: nested status, priority and so on.
 */
function ticket(int $id, string $summary, string $status = 'Open', string $priority = 'Normal', ?array $milestone = NULL, string $type = 'bug'): array {
  return ['ticket' => [
    'ticket_id' => $id, 'summary' => $summary, 'ticket_type' => $type,
    'reporter_id' => 1, 'assignee_id' => 2, 'assignee' => 'peter', 'reporter' => 'anna',
    'category_id' => 30, 'category' => ['id' => 30, 'name' => 'Bug'],
    'priority_id' => 21, 'priority' => ['id' => 21, 'name' => $priority, 'colour' => '#f00', 'default' => TRUE, 'position' => 2],
    'status_id' => 10, 'status' => ['id' => 10, 'name' => $status, 'colour' => '#0f0', 'order' => 1, 'treat-as-closed' => FALSE],
    'type_id' => 1, 'type' => ['id' => 1, 'name' => ucfirst($type), 'icon' => 'bug'],
    'milestone_id' => $milestone['id'] ?? NULL, 'milestone' => $milestone,
    'start_on' => NULL, 'deadline' => NULL, 'tags' => 'x,y',
    'updated_at' => '2026-10-01T10:00:00Z', 'created_at' => '2026-09-01T10:00:00Z',
    'description' => str_repeat('A very long description. ', 50),
    'project_id' => 9, 'total_time_spent' => 0, 'estimated_time' => NULL,
  ]];
}

/** Pages of 20, like Codebase; $total tickets in all. */
function pagedTickets(int $total, int $base): array {
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $first = ($page - 1) * 20;
  $count = max(0, min(20, $total - $first));
  if ($count === 0) {
    return [];
  }
  return array_map(fn($i) => ticket($base + $first + $i, "Ticket " . ($first + $i + 1)), range(0, $count - 1));
}

$project = fn(string $name, string $permalink, int $open, string $status = 'active') => ['project' => [
  'project_id' => 1, 'account_name' => 'acme', 'group_id' => '', 'icon' => 0, 'name' => $name, 'overview' => '',
  'start_page' => 'overview', 'status' => $status, 'permalink' => $permalink, 'disk_usage' => 0,
  'total_tickets' => $open + 5, 'open_tickets' => $open, 'closed_tickets' => 5,
]];

$milestone = ['id' => 5, 'identifier' => 'rel-1', 'name' => 'Rel 1', 'start_at' => '2026-10-01', 'deadline' => '2026-11-01', 'parent_id' => NULL, 'description' => '', 'responsible_user_id' => 2, 'estimated_time' => 0, 'status' => 'active'];

$lists = [
  '/acme/tickets/statuses.json' => [
    ['ticketing_status' => ['id' => 11, 'name' => 'Closed', 'colour' => '#000', 'order' => 5, 'treat_as_closed' => TRUE]],
    ['ticketing_status' => ['id' => 12, 'name' => 'In Progress', 'colour' => '#00f', 'order' => 2, 'treat_as_closed' => FALSE]],
    ['ticketing_status' => ['id' => 10, 'name' => 'Open', 'colour' => '#0f0', 'order' => 1, 'treat_as_closed' => FALSE]],
  ],
  '/acme/tickets/priorities.json' => [
    ['ticketing_priority' => ['id' => 20, 'name' => 'High', 'colour' => '#f00', 'default' => FALSE, 'position' => 3]],
    ['ticketing_priority' => ['id' => 21, 'name' => 'Normal', 'colour' => '#ff0', 'default' => TRUE, 'position' => 2]],
    ['ticketing_priority' => ['id' => 22, 'name' => 'Low', 'colour' => '#0ff', 'default' => FALSE, 'position' => 1]],
  ],
  '/acme/tickets/categories.json' => [['ticketing_category' => ['id' => 30, 'name' => 'Bug']]],
  '/acme/assignments.json' => [
    ['user' => ['company' => 'Acme', 'first_name' => 'Peter', 'last_name' => 'Tornstrand', 'id' => 5, 'username' => 'peter', 'email_address' => 'p@acme.test']],
    ['user' => ['company' => 'Acme', 'first_name' => 'Anna', 'last_name' => 'Andersson', 'id' => 6, 'username' => 'anna.a', 'email_address' => 'a@acme.test']],
    ['user' => ['company' => 'Acme', 'first_name' => 'Anna', 'last_name' => 'Andersson', 'id' => 7, 'username' => 'anna.b', 'email_address' => 'b@acme.test']],
  ],
  '/projects.json' => [
    $project('Acme', 'acme', 2),
    $project('Beta', 'beta', 1),
    $project('Quiet', 'quiet', 3),
    $project('Broken', 'broken', 4),
    $project('Weird', 'weird', 1),
    $project('Many', 'many', 45),
    $project('Endless', 'endless', 400),
    $project('Idle', 'idle', 0),
    $project('Old', 'old', 7, 'archived'),
    $project('Bad', '../bad', 1),
  ],
  '/acme/tickets.json' => [
    ticket(12, 'Fix login', 'In Progress', 'High', $milestone),
    ticket(13, 'Åäö på svenska', 'New', 'Normal', NULL, 'task'),
  ],
  '/beta/tickets.json' => [ticket(7, 'Beta thing', 'Testing')],
  '/weird/tickets.json' => ['oops' => TRUE],
  '/idle/tickets.json' => [],
  '/many/tickets.json' => pagedTickets(45, 1000),
  '/endless/tickets.json' => pagedTickets(10000, 5000),
];

if ($method === 'POST') {
  http_response_code(201);
  echo json_encode(str_ends_with($path, '/notes.json') ? ['ticket_note' => ['id' => 1]] : ['ticket' => ['ticket_id' => 99]]);
  return;
}

$result = $lists[$path] ?? ['echo' => $path];

// Like Codebase, answer a ticket search without (more) matches with 404 [].
if ($result === [] && str_ends_with($path, '/tickets.json')) {
  http_response_code(404);
}
echo json_encode($result);
