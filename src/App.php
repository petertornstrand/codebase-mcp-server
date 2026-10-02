<?php

namespace petertornstrand;

/**
 * Wires the OAuth server and the MCP endpoint together and routes requests.
 */
class App {

  private const REQUIRED = [
    'base_url', 'data_dir', 'encryption_key', 'allowed_domain',
    'saml.entity_id', 'saml.sso_url', 'saml.cert',
  ];

  private OAuthServer $oauth;

  private HttpTransport $mcp;

  /**
   * @param array $config
   *   See config.example.php.
   * @param ?callable $verifyCredentials
   *   Overrides the Codebase credential check (used by tests).
   *
   * @throws \InvalidArgumentException If required configuration is missing.
   */
  public function __construct(array $config, ?callable $verifyCredentials = NULL) {
    $missing = array_filter(self::REQUIRED, fn($path) => !is_string($this->value($config, $path)) || $this->value($config, $path) === '');
    if ($missing) {
      throw new \InvalidArgumentException('Missing configuration: ' . implode(', ', $missing));
    }

    $baseUrl = rtrim($config['base_url'], '/');
    if (!preg_match('#^https://[^/]+$#', $baseUrl) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $baseUrl)) {
      throw new \InvalidArgumentException('base_url must be an https origin without a path.');
    }

    $apiUrl = $config['api_url'] ?? NULL;
    $verifyCredentials ??= function (string $username, string $apiKey) use ($apiUrl): void {
      (new CodebaseMCPServer($username, $apiKey, NULL, $apiUrl))->verifyCredentials();
    };

    $this->oauth = new OAuthServer(
      $baseUrl,
      $config['allowed_domain'],
      new Storage($config['data_dir']),
      new Crypto($config['encryption_key']),
      new SamlService($baseUrl, [
        'entity_id' => $config['saml']['entity_id'],
        'sso_url' => $config['saml']['sso_url'],
        'cert' => $config['saml']['cert'],
      ]),
      $verifyCredentials,
    );
    $this->mcp = new HttpTransport(
      $this->oauth->authenticate(...),
      $this->oauth->resourceMetadataUrl(),
      $config['allowed_origins'] ?? [],
      $apiUrl,
    );
  }

  private function value(array $config, string $path): mixed {
    foreach (explode('.', $path) as $key) {
      $config = is_array($config) ? ($config[$key] ?? NULL) : NULL;
    }
    return $config;
  }

  /**
   * @param array $request
   *   Keys: method, path, query, post, headers, cookies, body, ip.
   */
  public function handle(array $request): array {
    if ($request['path'] === '/mcp') {
      return $this->mcp->handle($request['method'], $request['headers'], $request['body']);
    }
    return $this->oauth->handle($request) ?? Response::json(404, ['error' => 'not_found']);
  }

}
