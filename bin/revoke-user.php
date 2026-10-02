<?php

// Removes a user's stored Codebase credentials and revokes all their tokens.
// Usage: php bin/revoke-user.php someone@happiness.se

require_once __DIR__ . '/../vendor/autoload.php';

use petertornstrand\Storage;

if ($argc !== 2) {
  fwrite(STDERR, "Usage: php bin/revoke-user.php <email>\n");
  exit(1);
}

$config = require __DIR__ . '/../config.php';
(new Storage($config['data_dir']))->deleteUser(strtolower(trim($argv[1])));
echo "Revoked {$argv[1]}.\n";
