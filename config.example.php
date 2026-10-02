<?php

// Copy to config.php (gitignored). Never commit real values.
return [
  // Public origin of the server; also the SAML SP entity ID and OAuth issuer.
  'base_url' => 'https://codebase-mcp.hpns.dev',

  // Writable directory OUTSIDE the web root (holds the SQLite database).
  'data_dir' => '/home/<user>/codebase-mcp-data',

  // Encrypts stored Codebase API keys. Generate once and keep it safe:
  //   php -r 'echo base64_encode(random_bytes(32)), "\n";'
  // Losing it means users must re-enter their Codebase API key.
  'encryption_key' => '<base64 32 byte key>',

  // Only this Google Workspace email domain may sign in.
  'allowed_domain' => 'happiness.se',

  // Google Workspace SAML app (Admin console > Apps > Web and mobile apps).
  'saml' => [
    'entity_id' => '<IdP Entity ID>',
    'sso_url' => '<SSO URL>',
    'cert' => '<IdP certificate, PEM>',
  ],

  // Optional: allow tools that remove access or data (currently
  // unassign_from_projects). Must be exactly true or false; defaults to false.
  // When false the tools are hidden from clients and refuse to run.
  'allow_destructive' => false,

  // Optional: browser origins allowed to call /mcp (e.g. MCP Inspector).
  // 'allowed_origins' => [],

  // Optional: Codebase API base URL override.
  // 'api_url' => 'https://api3.codebasehq.com',
];
