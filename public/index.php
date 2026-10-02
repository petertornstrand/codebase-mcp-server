<?php

// HTTP entry point: MCP endpoint (/mcp) plus the OAuth and SAML endpoints.

require_once __DIR__ . '/../vendor/autoload.php';

use petertornstrand\App;

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Server is not configured.']);
  exit;
}

try {
  $app = new App(require $configFile);
}
catch (\Throwable $e) {
  // Fail closed, and say what is missing without exposing any values.
  error_log('Configuration error: ' . $e->getMessage());
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Server is not configured.']);
  exit;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
  if (str_starts_with($key, 'HTTP_')) {
    $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
  }
}
// Some SAPIs (CGI/LiteSpeed) expose Authorization under another name.
if (empty($headers['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
  $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

$response = $app->handle([
  'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
  'path' => $path,
  'query' => $_GET,
  'post' => $_POST,
  'headers' => $headers,
  'cookies' => $_COOKIE,
  // Read one byte past the limit so oversized bodies are detected.
  'body' => (string) file_get_contents('php://input', false, null, 0, 1048577),
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
]);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
  header("$name: $value", false);
}
echo $response['body'];
