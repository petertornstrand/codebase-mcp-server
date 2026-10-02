<?php

// Router for a fake Codebase API, run with: php -S 127.0.0.1:<port> <this file>
// Logs every request as a JSON line to the file named by FAKE_LOG.
// Credentials accepted: acme/peter : good-key. Project "broken" always fails; "missing" does not exist (404 [] everywhere); searches in "quiet" have no matches (404 []).

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

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

$lists = [
  '/acme/tickets/statuses.json' => [['ticketing_status' => ['id' => 10, 'name' => 'Open']], ['ticketing_status' => ['id' => 11, 'name' => 'Closed']]],
  '/acme/tickets/priorities.json' => [['ticketing_priority' => ['id' => 20, 'name' => 'High']]],
  '/acme/tickets/categories.json' => [['ticketing_category' => ['id' => 30, 'name' => 'Bug']]],
  '/acme/assignments.json' => [
    ['user' => ['id' => 5, 'username' => 'peter', 'first_name' => 'Peter', 'last_name' => 'Tornstrand']],
    ['user' => ['id' => 6, 'username' => 'anna.a', 'first_name' => 'Anna', 'last_name' => 'Andersson']],
    ['user' => ['id' => 7, 'username' => 'anna.b', 'first_name' => 'Anna', 'last_name' => 'Andersson']],
  ],
  '/projects.json' => [['project' => ['name' => 'Acme', 'permalink' => 'acme']]],
];

if ($method === 'POST') {
  http_response_code(201);
  echo json_encode(str_ends_with($path, '/notes.json') ? ['ticket_note' => ['id' => 1]] : ['ticket' => ['ticket_id' => 99]]);
  return;
}

echo json_encode($lists[$path] ?? ['echo' => $path]);
