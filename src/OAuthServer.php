<?php

namespace petertornstrand;

/**
 * Minimal OAuth 2.1 authorization server for MCP clients.
 *
 * Users sign in through Google Workspace (SAML) and store their personal
 * Codebase credentials once. Clients register dynamically (public clients,
 * PKCE required) and receive tokens issued by this server.
 */
class OAuthServer {

  private const CODE_TTL = 120;
  private const ACCESS_TTL = 3600;
  private const REFRESH_TTL = 2592000;
  private const FLOW_TTL = 600;

  /**
   * @param string $baseUrl
   *   The public base URL, without trailing slash (the OAuth issuer).
   * @param string $allowedDomain
   *   The only email domain allowed to sign in.
   * @param callable $verifyCredentials
   *   Called with (username, apiKey); must throw if Codebase rejects them.
   */
  public function __construct(
    private string $baseUrl,
    private string $allowedDomain,
    private Storage $storage,
    private Crypto $crypto,
    private SamlService $saml,
    private $verifyCredentials,
  ) {}

  /**
   * Dispatches an OAuth/SAML request, or returns NULL if the path is not one.
   *
   * @param array $request
   *   Keys: method, path, query, post, headers, cookies, ip.
   */
  public function handle(array $request): ?array {
    $path = $request['path'];
    $method = $request['method'];

    return match (TRUE) {
      str_starts_with($path, '/.well-known/oauth-protected-resource') => $this->protectedResourceMetadata(),
      $path === '/.well-known/oauth-authorization-server' => $this->authorizationServerMetadata(),
      $path === '/register' && $method === 'POST' => $this->register($request),
      $path === '/authorize' && $method === 'GET' => $this->authorize($request),
      $path === '/saml/acs' && $method === 'POST' => $this->samlAcs($request),
      $path === '/saml/metadata' && $method === 'GET' => Response::text(200, $this->saml->metadata(), 'application/samlmetadata+xml'),
      $path === '/setup' && $method === 'POST' => $this->setup($request),
      $path === '/token' && $method === 'POST' => $this->tokenEndpoint($request),
      $path === '/revoke' && $method === 'POST' => $this->revoke($request),
      in_array($path, ['/register', '/authorize', '/saml/acs', '/saml/metadata', '/setup', '/token', '/revoke'], TRUE)
        => Response::json(405, ['error' => 'method_not_allowed']),
      default => NULL,
    };
  }

  public function resourceMetadataUrl(): string {
    return $this->baseUrl . '/.well-known/oauth-protected-resource';
  }

  /**
   * Resolves a bearer access token to the user's Codebase credentials.
   *
   * @return array{username: string, api_key: string}|null
   */
  public function authenticate(string $accessToken): ?array {
    $token = $this->storage->getToken($accessToken, 'access');
    $user = $token ? $this->storage->getUser($token['email']) : NULL;
    if (!$user) {
      return NULL;
    }
    try {
      return [
        'username' => $user['username'],
        'api_key' => $this->crypto->decrypt($user['api_key_enc'], $user['email']),
      ];
    }
    catch (\RuntimeException) {
      return NULL;
    }
  }

  private function protectedResourceMetadata(): array {
    return Response::json(200, [
      'resource' => $this->baseUrl . '/mcp',
      'authorization_servers' => [$this->baseUrl],
      'bearer_methods_supported' => ['header'],
    ]);
  }

  private function authorizationServerMetadata(): array {
    return Response::json(200, [
      'issuer' => $this->baseUrl,
      'authorization_endpoint' => $this->baseUrl . '/authorize',
      'token_endpoint' => $this->baseUrl . '/token',
      'registration_endpoint' => $this->baseUrl . '/register',
      'revocation_endpoint' => $this->baseUrl . '/revoke',
      'response_types_supported' => ['code'],
      'grant_types_supported' => ['authorization_code', 'refresh_token'],
      'code_challenge_methods_supported' => ['S256'],
      'token_endpoint_auth_methods_supported' => ['none'],
    ]);
  }

  /**
   * Dynamic client registration (RFC 7591), public clients only.
   */
  private function register(array $request): array {
    if (!$this->storage->rateLimit('register:' . $request['ip'], 20, 3600)) {
      return Response::json(429, ['error' => 'rate_limited']);
    }
    $body = json_decode($request['body'] ?? '', TRUE);
    $uris = is_array($body) ? ($body['redirect_uris'] ?? NULL) : NULL;
    if (!is_array($uris) || $uris === [] || count($uris) > 5 || array_filter($uris, fn($u) => !$this->isValidRedirectUri($u))) {
      return Response::json(400, ['error' => 'invalid_redirect_uri']);
    }

    $name = is_string($body['client_name'] ?? NULL) ? mb_substr(trim($body['client_name']), 0, 100) : '';
    $id = bin2hex(random_bytes(16));
    $this->storage->saveClient($id, $name !== '' ? $name : 'MCP client', array_values($uris));

    return Response::json(201, [
      'client_id' => $id,
      'client_name' => $name,
      'redirect_uris' => array_values($uris),
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'token_endpoint_auth_method' => 'none',
    ], ['Cache-Control' => 'no-store']);
  }

  /**
   * Accepts https URIs and loopback http URIs (native clients, RFC 8252).
   */
  private function isValidRedirectUri(mixed $uri): bool {
    if (!is_string($uri) || strlen($uri) > 500 || str_contains($uri, '#')) {
      return FALSE;
    }
    $parts = parse_url($uri);
    if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }
    return ($parts['scheme'] ?? '') === 'https'
      || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], TRUE));
  }

  private function authorize(array $request): array {
    $q = $request['query'];
    if (!$this->storage->rateLimit('authorize:' . $request['ip'], 60, 600)) {
      return $this->errorPage(429, 'Too many requests. Try again later.');
    }
    $this->storage->purge();

    // Until the client and redirect URI are verified, errors must not redirect.
    $client = $this->storage->getClient((string) ($q['client_id'] ?? ''));
    $redirectUri = (string) ($q['redirect_uri'] ?? '');
    if (!$client || !in_array($redirectUri, $client['uris'], TRUE)) {
      return $this->errorPage(400, 'Unknown client or redirect URI.');
    }
    $state = isset($q['state']) ? (string) $q['state'] : NULL;

    if (($q['response_type'] ?? '') !== 'code') {
      return $this->redirectError($redirectUri, $state, 'unsupported_response_type');
    }
    $challenge = (string) ($q['code_challenge'] ?? '');
    if (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
      return $this->redirectError($redirectUri, $state, 'invalid_request', 'PKCE with S256 is required.');
    }
    if (isset($q['resource']) && rtrim((string) $q['resource'], '/') !== $this->baseUrl . '/mcp') {
      return $this->redirectError($redirectUri, $state, 'invalid_target');
    }

    $id = $this->random(24);
    [$url, $samlRequestId] = $this->saml->loginUrl($id);
    $this->storage->savePending([
      'id' => $id,
      'client_id' => $client['id'],
      'redirect_uri' => $redirectUri,
      'state' => $state,
      'code_challenge' => $challenge,
      'saml_request_id' => $samlRequestId,
      'expires_at' => time() + self::FLOW_TTL,
    ]);
    return Response::redirect($url);
  }

  /**
   * SAML assertion consumer service: Google posts the signed login here.
   */
  private function samlAcs(array $request): array {
    $pending = $this->storage->getPending((string) ($request['post']['RelayState'] ?? ''));
    if (!$pending || $pending['email'] !== NULL) {
      return $this->errorPage(400, 'This sign-in session has expired. Start again from your client.');
    }

    try {
      $email = $this->saml->validate((string) ($request['post']['SAMLResponse'] ?? ''), $pending['saml_request_id']);
    }
    catch (\RuntimeException $e) {
      error_log('SAML validation failed: ' . $e->getMessage());
      return $this->errorPage(403, 'Sign-in failed.');
    }

    if (!str_ends_with($email, '@' . strtolower($this->allowedDomain))) {
      return $this->errorPage(403, 'Only @' . $this->allowedDomain . ' accounts may sign in.');
    }

    $nonce = $this->random(24);
    if (!$this->storage->authenticatePending($pending['id'], $email, Storage::hash($nonce))) {
      return $this->errorPage(400, 'This sign-in session has expired. Start again from your client.');
    }

    return $this->setupPage($pending, $email, $nonce, NULL, [
      'Set-Cookie' => $this->cookie('mcp_setup', $nonce, self::FLOW_TTL),
    ]);
  }

  /**
   * Handles the consent / Codebase credentials form.
   */
  private function setup(array $request): array {
    $post = $request['post'];
    $pending = $this->storage->getPending((string) ($post['flow'] ?? ''));
    $nonce = (string) ($post['nonce'] ?? '');
    $cookie = (string) ($request['cookies']['mcp_setup'] ?? '');

    // The form nonce and the cookie must both match the authenticated flow.
    if (!$pending || $pending['email'] === NULL
      || !hash_equals((string) $pending['nonce_hash'], Storage::hash($nonce))
      || !hash_equals($nonce, $cookie)) {
      return $this->errorPage(400, 'This sign-in session has expired. Start again from your client.');
    }

    $clear = ['Set-Cookie' => $this->cookie('mcp_setup', '', 0)];

    if (($post['action'] ?? '') === 'deny') {
      $this->storage->consumePending($pending['id']);
      return $this->redirectError($pending['redirect_uri'], $pending['state'], 'access_denied', NULL, $clear);
    }

    $email = $pending['email'];
    if (!$this->storage->rateLimit('setup:' . $pending['id'], 5, self::FLOW_TTL)) {
      return $this->errorPage(429, 'Too many attempts. Start again from your client.');
    }

    $existing = $this->storage->getUser($email);
    $username = trim((string) ($post['username'] ?? ''));
    $apiKey = trim((string) ($post['api_key'] ?? ''));
    if ($apiKey === '' && $existing && ($username === '' || $username === $existing['username'])) {
      $username = $existing['username'];
      $apiKey = $this->crypto->decrypt($existing['api_key_enc'], $email);
    }
    if ($username === '' || $apiKey === '') {
      return $this->setupPage($pending, $email, $nonce, 'Enter your Codebase username and API key.');
    }

    try {
      ($this->verifyCredentials)($username, $apiKey);
    }
    catch (\Throwable) {
      return $this->setupPage($pending, $email, $nonce, 'Codebase rejected these credentials.');
    }

    // Single use: the flow can complete only once, even with concurrent posts.
    if (!$this->storage->consumePending($pending['id'])) {
      return $this->errorPage(400, 'This sign-in session has expired. Start again from your client.');
    }
    $this->storage->saveUser($email, $username, $this->crypto->encrypt($apiKey, $email));

    $code = $this->random(32);
    $this->storage->saveCode($code, $pending['client_id'], $pending['redirect_uri'], $pending['code_challenge'], $email, self::CODE_TTL);

    return Response::redirect(
      $this->appendQuery($pending['redirect_uri'], ['code' => $code, 'state' => $pending['state']]),
      $clear
    );
  }

  private function random(int $bytes): string {
    return $this->base64Url(random_bytes($bytes));
  }

  private function base64Url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

  private function tokenEndpoint(array $request): array {
    $p = $request['post'];
    $client = $this->storage->getClient((string) ($p['client_id'] ?? ''));
    if (!$client) {
      return $this->tokenError('invalid_client', 401);
    }

    switch ($p['grant_type'] ?? '') {
      case 'authorization_code':
        $code = $this->storage->consumeCode((string) ($p['code'] ?? ''));
        $verifier = (string) ($p['code_verifier'] ?? '');
        if (!$code
          || $code['client_id'] !== $client['id']
          || !hash_equals($code['redirect_uri'], (string) ($p['redirect_uri'] ?? ''))
          || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
          || !hash_equals($code['code_challenge'], $this->base64Url(hash('sha256', $verifier, TRUE)))) {
          return $this->tokenError('invalid_grant');
        }
        if (!$this->storage->getUser($code['email'])) {
          return $this->tokenError('invalid_grant');
        }
        return $this->issueTokens($client['id'], $code['email']);

      case 'refresh_token':
        $refresh = (string) ($p['refresh_token'] ?? '');
        $row = $this->storage->getToken($refresh, 'refresh');
        // Rotation: deleting is the atomic check that the token is unused.
        if (!$row || $row['client_id'] !== $client['id'] || !$this->storage->deleteToken($refresh)
          || !$this->storage->getUser($row['email'])) {
          return $this->tokenError('invalid_grant');
        }
        return $this->issueTokens($client['id'], $row['email']);

      default:
        return $this->tokenError('unsupported_grant_type');
    }
  }

  private function issueTokens(string $clientId, string $email): array {
    $access = $this->random(32);
    $refresh = $this->random(32);
    $this->storage->saveToken($access, 'access', $clientId, $email, self::ACCESS_TTL);
    $this->storage->saveToken($refresh, 'refresh', $clientId, $email, self::REFRESH_TTL);
    return Response::json(200, [
      'access_token' => $access,
      'token_type' => 'Bearer',
      'expires_in' => self::ACCESS_TTL,
      'refresh_token' => $refresh,
    ], ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
  }

  private function tokenError(string $error, int $status = 400): array {
    return Response::json($status, ['error' => $error], ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
  }

  /**
   * Token revocation (RFC 7009); always succeeds so tokens cannot be probed.
   */
  private function revoke(array $request): array {
    $token = (string) ($request['post']['token'] ?? '');
    if ($token !== '') {
      $this->storage->deleteToken($token);
    }
    return Response::json(200, new \stdClass());
  }

  private function redirectError(string $redirectUri, ?string $state, string $error, ?string $description = NULL, array $headers = []): array {
    return Response::redirect(
      $this->appendQuery($redirectUri, ['error' => $error, 'error_description' => $description, 'state' => $state]),
      $headers
    );
  }

  private function appendQuery(string $url, array $params): string {
    $query = http_build_query(array_filter($params, fn($v) => $v !== NULL), '', '&', PHP_QUERY_RFC3986);
    return $url . (str_contains($url, '?') ? '&' : '?') . $query;
  }

  private function cookie(string $name, string $value, int $maxAge): string {
    return sprintf('%s=%s; Path=/setup; Max-Age=%d; HttpOnly; SameSite=Lax%s',
      $name, $value, $maxAge, str_starts_with($this->baseUrl, 'https://') ? '; Secure' : '');
  }

  private function e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private function errorPage(int $status, string $message): array {
    return Response::html($status, $this->layout('Codebase MCP', '<p>' . $this->e($message) . '</p>'));
  }

  private function setupPage(array $pending, string $email, string $nonce, ?string $error, array $headers = []): array {
    $client = $this->storage->getClient($pending['client_id']);
    $user = $this->storage->getUser($email);
    $host = parse_url($pending['redirect_uri'], PHP_URL_HOST);

    $body = '<h1>Connect Codebase</h1>'
      . '<p><strong>' . $this->e($client['name']) . '</strong> (redirects to <code>' . $this->e((string) $host) . '</code>) '
      . 'wants to use Codebase HQ as <strong>' . $this->e($email) . '</strong>.</p>'
      . ($error ? '<p class="error">' . $this->e($error) . '</p>' : '')
      . '<form method="post" action="/setup" autocomplete="off">'
      . '<input type="hidden" name="flow" value="' . $this->e($pending['id']) . '">'
      . '<input type="hidden" name="nonce" value="' . $this->e($nonce) . '">'
      . '<label>Codebase username<input name="username" required value="' . $this->e($user['username'] ?? '') . '"></label>'
      . '<label>Codebase API key<input name="api_key" type="password"'
      . ($user ? ' placeholder="Leave blank to keep the saved key"' : ' required') . '></label>'
      . '<p class="hint">Your personal credentials from Codebase (Settings &rarr; Profile &rarr; API credentials). '
      . 'The key is stored encrypted and used only for your requests.</p>'
      . '<button name="action" value="allow">Allow</button> '
      . '<button name="action" value="deny" class="secondary" formnovalidate>Deny</button>'
      . '</form>';

    return Response::html($error ? 422 : 200, $this->layout('Connect Codebase', $body), $headers);
  }

  private function layout(string $title, string $body): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<title>' . $this->e($title) . '</title><style>'
      . ':root{color-scheme:light dark}body{font:16px/1.5 system-ui,sans-serif;max-width:30rem;margin:3rem auto;padding:0 1rem}'
      . 'label{display:block;margin:1rem 0}input{display:block;width:100%;box-sizing:border-box;padding:.5rem;margin-top:.25rem;font:inherit}'
      . 'button{padding:.5rem 1.25rem;font:inherit;cursor:pointer}.secondary{opacity:.8}.error{color:#c00}.hint{font-size:.85rem;opacity:.75}'
      . '</style></head><body>' . $body . '</body></html>';
  }

}
