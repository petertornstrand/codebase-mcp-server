<?php

require_once 'vendor/autoload.php';

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
);

// Run server.
$server->run();