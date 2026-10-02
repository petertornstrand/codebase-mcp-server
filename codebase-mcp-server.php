<?php

require_once __DIR__ . '/vendor/autoload.php';

use petertornstrand\CodebaseMCPServer;

// Check if we are running application via CLI.
if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This application must be run from the command line.\n");
  exit(1);
}

// The required environment variables.
$requiredEnvVars = [
  'CODEBASE_USERNAME',
  'CODEBASE_API_KEY',
];

// Make sure required environment variables are set.
foreach ($requiredEnvVars as $envVar) {
  if (getenv($envVar) === false || getenv($envVar) === '') {
    fwrite(STDERR, sprintf("Missing required environment variable: %s\n", $envVar));
    exit(1);
  }
}

// Create server.
$server = new CodebaseMCPServer(
  getenv('CODEBASE_USERNAME') ?: '',
  getenv('CODEBASE_API_KEY') ?: '',
  getenv('CODEBASE_PROJECT') ?: null,
  getenv('CODEBASE_API_URL') ?: null,
  // Tools that remove access or data are off unless explicitly enabled.
  allowDestructive: in_array(strtolower((string) getenv('CODEBASE_ALLOW_DESTRUCTIVE')), ['1', 'true'], true),
);

// Run server.
$server->run();