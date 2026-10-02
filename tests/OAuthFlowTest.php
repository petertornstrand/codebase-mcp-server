<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use petertornstrand\App;

class OAuthFlowTest extends TestCase {

  private const BASE = 'https://codebase-mcp.test';

  private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

  private TestIdp $idp;

  private App $app;

  private string $key;

  private string $dir;

  /** Credentials the fake Codebase accepts. */
  private array $validCredentials = ['acme/peter' => 'secret-api-key'];

  protected function setUp(): void {
    $this->idp = new TestIdp();
    $this->dir = sys_get_temp_dir() . '/mcp-test-' . bin2hex(random_bytes(4));
    $this->key = base64_encode(random_bytes(32));
    $this->app = $this->makeApp();
  }

  /**
   * An app for this test's fake identity provider and data directory.
   *
   * @param array $extra
   *   Extra configuration, such as allow_destructive.
   */
  private function makeApp(array $extra = []): App {
    return new App([
      'base_url' => self::BASE,
      'data_dir' => $this->dir,
      'encryption_key' => $this->key,
      'allowed_domain' => 'happiness.se',
      'saml' => ['entity_id' => $this->idp->entityId, 'sso_url' => $this->idp->ssoUrl, 'cert' => $this->idp->cert],
    ] + $extra, function (string $username, string $apiKey): void {
      if (($this->validCredentials[$username] ?? NULL) !== $apiKey) {
        throw new \Exception('rejected');
      }
    });
  }

  protected function tearDown(): void {
    array_map('unlink', glob($this->dir . '/*'));
    @rmdir($this->dir);
  }

  private function req(string $method, string $path, array $extra = []): array {
    $_SERVER['REQUEST_URI'] = $path;
    return $this->app->handle($extra + [
      'method' => $method, 'path' => $path, 'query' => [], 'post' => [], 'headers' => [],
      'cookies' => [], 'body' => '', 'ip' => '203.0.113.7',
    ]);
  }

  private function body(array $res): array {
    return json_decode($res['body'], TRUE);
  }

  private function register(array $uris = [self::REDIRECT]): string {
    $res = $this->req('POST', '/register', ['body' => json_encode(['client_name' => 'Claude', 'redirect_uris' => $uris])]);
    $this->assertSame(201, $res['status']);
    return $this->body($res)['client_id'];
  }

  private static function pkce(): array {
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=')];
  }

  /** Runs /authorize and returns [flowId, samlRequestId]. */
  private function authorize(string $clientId, string $challenge, string $state = 'xyz'): array {
    $res = $this->req('GET', '/authorize', ['query' => [
      'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
      'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => $state,
      'resource' => self::BASE . '/mcp',
    ]]);
    $this->assertSame(302, $res['status']);
    $this->assertStringStartsWith($this->idp->ssoUrl, $res['headers']['Location']);
    parse_str(parse_url($res['headers']['Location'], PHP_URL_QUERY), $q);
    $xml = gzinflate(base64_decode($q['SAMLRequest']));
    preg_match('/ID="([^"]+)"/', $xml, $m);
    return [$q['RelayState'], $m[1]];
  }

  private function acs(string $flow, string $samlResponse): array {
    return $this->req('POST', '/saml/acs', ['post' => ['SAMLResponse' => $samlResponse, 'RelayState' => $flow]]);
  }

  /** Signs in through the fake IdP; returns [nonce, flow] from the setup page. */
  private function signIn(string $clientId, string $challenge, string $email = 'peter@happiness.se'): array {
    [$flow, $reqId] = $this->authorize($clientId, $challenge);
    $res = $this->acs($flow, $this->idp->response($email, $reqId, self::BASE));
    $this->assertSame(200, $res['status'], $res['body']);
    preg_match('/name="nonce" value="([^"]+)"/', $res['body'], $m);
    return [$m[1], $flow];
  }

  private function allow(string $flow, string $nonce, array $fields = [], ?string $cookie = NULL): array {
    return $this->req('POST', '/setup', [
      'post' => $fields + ['flow' => $flow, 'nonce' => $nonce, 'username' => 'acme/peter', 'api_key' => 'secret-api-key', 'action' => 'allow'],
      'cookies' => ['mcp_setup' => $cookie ?? $nonce],
    ]);
  }

  private function codeFrom(array $res): string {
    $this->assertSame(302, $res['status'], $res['body']);
    parse_str(parse_url($res['headers']['Location'], PHP_URL_QUERY), $q);
    $this->assertSame('xyz', $q['state']);
    return $q['code'];
  }

  private function exchange(string $clientId, string $code, string $verifier, array $override = []): array {
    return $this->req('POST', '/token', ['post' => $override + [
      'grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code,
      'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT,
    ]]);
  }

  private function mcp(?string $token, string $method = 'tools/list'): array {
    return $this->req('POST', '/mcp', [
      'headers' => $token ? ['authorization' => "Bearer $token"] : [],
      'body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method]),
    ]);
  }

  /** Completes the whole flow; returns the token response body. */
  private function fullFlow(): array {
    [$verifier, $challenge] = self::pkce();
    $clientId = $this->register();
    [$nonce, $flow] = $this->signIn($clientId, $challenge);
    $code = $this->codeFrom($this->allow($flow, $nonce));
    $res = $this->exchange($clientId, $code, $verifier);
    $this->assertSame(200, $res['status']);
    return $this->body($res) + ['client_id' => $clientId];
  }

  public function testMetadata(): void {
    $as = $this->body($this->req('GET', '/.well-known/oauth-authorization-server'));
    $this->assertSame(self::BASE, $as['issuer']);
    $this->assertSame(['S256'], $as['code_challenge_methods_supported']);

    foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'] as $path) {
      $pr = $this->body($this->req('GET', $path));
      $this->assertSame(self::BASE . '/mcp', $pr['resource']);
      $this->assertSame([self::BASE], $pr['authorization_servers']);
    }

    $this->assertStringContainsString(self::BASE . '/saml/acs', $this->req('GET', '/saml/metadata')['body']);
  }

  public function testRegistrationValidatesRedirectUris(): void {
    foreach ([['http://evil.example/cb'], ['javascript:alert(1)'], ['https://a.test/cb#frag'], [], 'nope'] as $uris) {
      $res = $this->req('POST', '/register', ['body' => json_encode(['redirect_uris' => $uris])]);
      $this->assertSame(400, $res['status']);
    }
    $this->register(['http://localhost:53682/callback']);
  }

  public function testFullFlowAndMcpAccess(): void {
    $this->assertSame(401, $this->mcp(NULL)['status']);
    $this->assertStringContainsString(
      'resource_metadata="' . self::BASE . '/.well-known/oauth-protected-resource"',
      $this->mcp('bogus')['headers']['WWW-Authenticate']
    );

    $tokens = $this->fullFlow();
    $this->assertSame('Bearer', $tokens['token_type']);

    $res = $this->mcp($tokens['access_token']);
    $this->assertSame(200, $res['status']);
    $this->assertNotEmpty($this->body($res)['result']['tools']);
  }

  public function testApiKeyIsEncryptedAtRest(): void {
    $this->fullFlow();
    // Recent writes may still sit in the WAL file, so scan everything.
    $raw = implode('', array_map('file_get_contents', glob($this->dir . '/*')));
    $this->assertFalse(str_contains($raw, 'secret-api-key'), 'API key found in plaintext');
    $this->assertTrue(str_contains($raw, 'peter@happiness.se'), 'Expected the user row to be present');
  }

  public function testRefreshRotatesAndOldTokenIsRejected(): void {
    $first = $this->fullFlow();
    $res = $this->req('POST', '/token', ['post' => [
      'grant_type' => 'refresh_token', 'client_id' => $first['client_id'], 'refresh_token' => $first['refresh_token'],
    ]]);
    $this->assertSame(200, $res['status']);
    $second = $this->body($res);
    $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
    $this->assertSame(200, $this->mcp($second['access_token'])['status']);

    $replay = $this->req('POST', '/token', ['post' => [
      'grant_type' => 'refresh_token', 'client_id' => $first['client_id'], 'refresh_token' => $first['refresh_token'],
    ]]);
    $this->assertSame('invalid_grant', $this->body($replay)['error']);
  }

  public function testRevokeInvalidatesAccessToken(): void {
    $tokens = $this->fullFlow();
    $this->req('POST', '/revoke', ['post' => ['token' => $tokens['access_token']]]);
    $this->assertSame(401, $this->mcp($tokens['access_token'])['status']);
  }

  public function testRejectsOtherEmailDomains(): void {
    [$verifier, $challenge] = self::pkce();
    [$flow, $reqId] = $this->authorize($this->register(), $challenge);
    $res = $this->acs($flow, $this->idp->response('mallory@evil.com', $reqId, self::BASE));
    $this->assertSame(403, $res['status']);
    $this->assertStringNotContainsString('name="nonce"', $res['body']);
  }

  public function testRejectsLookalikeDomain(): void {
    [, $challenge] = self::pkce();
    [$flow, $reqId] = $this->authorize($this->register(), $challenge);
    $res = $this->acs($flow, $this->idp->response('mallory@evilhappiness.se', $reqId, self::BASE));
    $this->assertSame(403, $res['status']);
  }

  public function testRejectsForgedSignature(): void {
    [, $challenge] = self::pkce();
    [$flow, $reqId] = $this->authorize($this->register(), $challenge);
    $res = $this->acs($flow, $this->idp->response('peter@happiness.se', $reqId, self::BASE, TestIdp::forgedKey()));
    $this->assertSame(403, $res['status']);
  }

  public function testRejectsResponseForAnotherRequest(): void {
    [, $challenge] = self::pkce();
    [$flow] = $this->authorize($this->register(), $challenge);
    $res = $this->acs($flow, $this->idp->response('peter@happiness.se', '_someone_elses_request', self::BASE));
    $this->assertSame(403, $res['status']);
  }

  public function testSamlResponseCannotBeReplayed(): void {
    [, $challenge] = self::pkce();
    [$flow, $reqId] = $this->authorize($this->register(), $challenge);
    $response = $this->idp->response('peter@happiness.se', $reqId, self::BASE);
    $this->assertSame(200, $this->acs($flow, $response)['status']);
    $this->assertSame(400, $this->acs($flow, $response)['status']);
  }

  public function testSetupRequiresMatchingCookie(): void {
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    $this->assertSame(400, $this->allow($flow, $nonce, [], 'other')['status']);
    $this->assertSame(400, $this->allow($flow, 'forged-nonce')['status']);
  }

  public function testWrongCodebaseCredentialsIssueNoCode(): void {
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    $res = $this->allow($flow, $nonce, ['api_key' => 'wrong']);
    $this->assertSame(422, $res['status']);
    $this->assertArrayNotHasKey('Location', $res['headers']);
    // The flow stays usable with the right credentials.
    $this->assertSame(302, $this->allow($flow, $nonce)['status']);
  }

  public function testDenyRedirectsWithAccessDenied(): void {
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    $res = $this->allow($flow, $nonce, ['action' => 'deny']);
    $this->assertStringContainsString('error=access_denied', $res['headers']['Location']);
  }

  public function testCodeIsSingleUseAndPkceIsEnforced(): void {
    [$verifier, $challenge] = self::pkce();
    $clientId = $this->register();
    [$nonce, $flow] = $this->signIn($clientId, $challenge);
    $code = $this->codeFrom($this->allow($flow, $nonce));

    $bad = $this->exchange($clientId, $code, str_repeat('a', 43));
    $this->assertSame('invalid_grant', $this->body($bad)['error']);
    // The failed attempt burned the code.
    $this->assertSame('invalid_grant', $this->body($this->exchange($clientId, $code, $verifier))['error']);
  }

  public function testCodeBoundToClientAndRedirectUri(): void {
    [$verifier, $challenge] = self::pkce();
    $clientId = $this->register();
    $other = $this->register();
    [$nonce, $flow] = $this->signIn($clientId, $challenge);
    $code = $this->codeFrom($this->allow($flow, $nonce));
    $this->assertSame('invalid_grant', $this->body($this->exchange($other, $code, $verifier))['error']);

    [$nonce, $flow] = $this->signIn($clientId, $challenge);
    $code = $this->codeFrom($this->allow($flow, $nonce));
    $this->assertSame('invalid_grant', $this->body($this->exchange($clientId, $code, $verifier, ['redirect_uri' => 'https://evil.test/cb']))['error']);
  }

  public function testAuthorizeRejectsBadRequests(): void {
    [, $challenge] = self::pkce();
    $clientId = $this->register();
    $base = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
      'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];

    // Unknown redirect URI: error page, never a redirect.
    $res = $this->req('GET', '/authorize', ['query' => ['redirect_uri' => 'https://evil.test/cb'] + $base]);
    $this->assertSame(400, $res['status']);
    $this->assertArrayNotHasKey('Location', $res['headers']);

    // No PKCE.
    $res = $this->req('GET', '/authorize', ['query' => ['code_challenge_method' => 'plain'] + $base]);
    $this->assertStringContainsString('error=invalid_request', $res['headers']['Location']);

    // Wrong resource.
    $res = $this->req('GET', '/authorize', ['query' => ['resource' => 'https://other.test/mcp'] + $base]);
    $this->assertStringContainsString('error=invalid_target', $res['headers']['Location']);
  }

  // Edge cases.

  public function testClientRegistrationIsRateLimitedPerIp(): void {
    $body = json_encode(['redirect_uris' => [self::REDIRECT]]);
    for ($i = 0; $i < 20; $i++) {
      $this->assertSame(201, $this->req('POST', '/register', ['body' => $body])['status'], "registration $i");
    }
    $this->assertSame(429, $this->req('POST', '/register', ['body' => $body])['status']);
    // Another address is unaffected.
    $this->assertSame(201, $this->req('POST', '/register', ['body' => $body, 'ip' => '198.51.100.9'])['status']);
  }

  public function testAuthorizeIsRateLimitedPerIp(): void {
    $clientId = $this->register();
    $query = ['client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code'];
    for ($i = 0; $i < 60; $i++) {
      $this->assertNotSame(429, $this->req('GET', '/authorize', ['query' => $query])['status']);
    }
    $this->assertSame(429, $this->req('GET', '/authorize', ['query' => $query])['status']);
  }

  public function testCredentialGuessingOnTheSetupFormIsLimited(): void {
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    for ($i = 0; $i < 5; $i++) {
      $this->assertSame(422, $this->allow($flow, $nonce, ['api_key' => "guess$i"])['status'], "attempt $i");
    }
    // The sixth attempt is refused even with correct credentials.
    $res = $this->allow($flow, $nonce);
    $this->assertSame(429, $res['status']);
    $this->assertArrayNotHasKey('Location', $res['headers']);
  }

  public function testUnknownPathsAndWrongMethods(): void {
    $this->assertSame(404, $this->req('GET', '/nope')['status']);
    $this->assertSame(404, $this->req('GET', '/')['status']);
    foreach (['/register', '/token', '/revoke', '/setup', '/saml/acs'] as $path) {
      $this->assertSame(405, $this->req('GET', $path)['status'], "GET $path");
    }
    foreach (['/authorize', '/saml/metadata'] as $path) {
      $this->assertSame(405, $this->req('POST', $path)['status'], "POST $path");
    }
  }

  public function testTokenEndpointRejectsBadRequests(): void {
    $clientId = $this->register();
    $this->assertSame(401, $this->req('POST', '/token', ['post' => ['grant_type' => 'authorization_code', 'client_id' => 'nope']])['status']);
    $this->assertSame(401, $this->req('POST', '/token', ['post' => ['grant_type' => 'authorization_code']])['status']);

    $res = $this->req('POST', '/token', ['post' => ['grant_type' => 'password', 'client_id' => $clientId]]);
    $this->assertSame(400, $res['status']);
    $this->assertSame('unsupported_grant_type', $this->body($res)['error']);
    $this->assertSame('no-store', $res['headers']['Cache-Control']);

    foreach ([['grant_type' => 'authorization_code'], ['grant_type' => 'authorization_code', 'code' => 'x'], ['grant_type' => 'refresh_token']] as $post) {
      $res = $this->req('POST', '/token', ['post' => $post + ['client_id' => $clientId]]);
      $this->assertSame('invalid_grant', $this->body($res)['error']);
    }
  }

  public function testTokenTypesAreNotInterchangeable(): void {
    $tokens = $this->fullFlow();
    $this->assertSame(401, $this->mcp($tokens['refresh_token'])['status'], 'refresh token as access token');

    $res = $this->req('POST', '/token', ['post' => [
      'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['access_token'],
    ]]);
    $this->assertSame('invalid_grant', $this->body($res)['error'], 'access token as refresh token');
  }

  public function testRefreshTokenIsBoundToItsClient(): void {
    $tokens = $this->fullFlow();
    $res = $this->req('POST', '/token', ['post' => [
      'grant_type' => 'refresh_token', 'client_id' => $this->register(), 'refresh_token' => $tokens['refresh_token'],
    ]]);
    $this->assertSame('invalid_grant', $this->body($res)['error']);
    // The legitimate client's token must survive the failed attempt.
    $res = $this->req('POST', '/token', ['post' => [
      'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token'],
    ]]);
    $this->assertSame(200, $res['status']);
  }

  public function testRevokingAnUnknownTokenStillSucceeds(): void {
    $res = $this->req('POST', '/revoke', ['post' => ['token' => 'never-issued']]);
    $this->assertSame(200, $res['status']);
    $this->assertSame(200, $this->req('POST', '/revoke')['status']);
  }

  public function testRemovingAUserStopsTheirTokens(): void {
    $tokens = $this->fullFlow();
    $this->assertSame(200, $this->mcp($tokens['access_token'])['status']);

    (new \petertornstrand\Storage($this->dir))->deleteUser('peter@happiness.se');

    $this->assertSame(401, $this->mcp($tokens['access_token'])['status']);
    $res = $this->req('POST', '/token', ['post' => [
      'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token'],
    ]]);
    $this->assertSame('invalid_grant', $this->body($res)['error']);
  }

  public function testExpiredAccessTokensAreRejected(): void {
    $tokens = $this->fullFlow();
    $db = new \PDO('sqlite:' . $this->dir . '/codebase-mcp.sqlite');
    $db->exec("UPDATE tokens SET expires_at = 1 WHERE type = 'access'");
    $this->assertSame(401, $this->mcp($tokens['access_token'])['status']);
  }

  public function testCompletedFlowCannotBeSubmittedAgain(): void {
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    $this->assertSame(302, $this->allow($flow, $nonce)['status']);
    $this->assertSame(400, $this->allow($flow, $nonce)['status']);
  }

  public function testReturningUserKeepsTheSavedKeyWhenLeftBlank(): void {
    $this->fullFlow();
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    // Blank key: the stored one is verified and reused.
    $this->assertSame(302, $this->allow($flow, $nonce, ['api_key' => '', 'username' => ''])['status']);

    // Once Codebase rotates the key, the stale saved key is rejected.
    $this->validCredentials = ['acme/peter' => 'rotated-key'];
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    $this->assertSame(422, $this->allow($flow, $nonce, ['api_key' => ''])['status']);
    $this->assertSame(302, $this->allow($flow, $nonce, ['api_key' => 'rotated-key'])['status']);
  }

  public function testChangingTheUsernameRequiresANewKey(): void {
    $this->fullFlow();
    [, $challenge] = self::pkce();
    [$nonce, $flow] = $this->signIn($this->register(), $challenge);
    $res = $this->allow($flow, $nonce, ['username' => 'acme/someone-else', 'api_key' => '']);
    $this->assertSame(422, $res['status']);
  }

  public function testSetupPageEscapesClientControlledText(): void {
    $res = $this->req('POST', '/register', ['body' => json_encode([
      'client_name' => '<script>alert(1)</script>', 'redirect_uris' => [self::REDIRECT],
    ])]);
    [, $challenge] = self::pkce();
    [$flow, $reqId] = $this->authorize($this->body($res)['client_id'], $challenge);
    $page = $this->acs($flow, $this->idp->response('peter@happiness.se', $reqId, self::BASE))['body'];
    $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
    $this->assertStringContainsString('&lt;script&gt;', $page);
  }

  public function testSetupPageIsNotCacheableOrFrameable(): void {
    [, $challenge] = self::pkce();
    [$flow, $reqId] = $this->authorize($this->register(), $challenge);
    $res = $this->acs($flow, $this->idp->response('peter@happiness.se', $reqId, self::BASE));
    $this->assertSame('no-store', $res['headers']['Cache-Control']);
    $this->assertSame('DENY', $res['headers']['X-Frame-Options']);
    $this->assertStringContainsString("frame-ancestors 'none'", $res['headers']['Content-Security-Policy']);
    $this->assertStringContainsString('HttpOnly', $res['headers']['Set-Cookie']);
    $this->assertStringContainsString('Secure', $res['headers']['Set-Cookie']);
  }

  public function testRedirectUriWithExistingQueryGetsParametersAppended(): void {
    $uri = 'https://claude.ai/cb?existing=1';
    [$verifier, $challenge] = self::pkce();
    $clientId = $this->register([$uri]);
    $res = $this->req('GET', '/authorize', ['query' => [
      'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $uri,
      'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'a b&c',
    ]]);
    parse_str(parse_url($res['headers']['Location'], PHP_URL_QUERY), $q);
    $flow = $q['RelayState'];
    $xml = gzinflate(base64_decode($q['SAMLRequest']));
    preg_match('/ID="([^"]+)"/', $xml, $m);
    $page = $this->acs($flow, $this->idp->response('peter@happiness.se', $m[1], self::BASE));
    preg_match('/name="nonce" value="([^"]+)"/', $page['body'], $n);

    $allow = $this->allow($flow, $n[1]);
    $location = $allow['headers']['Location'];
    $this->assertStringStartsWith('https://claude.ai/cb?existing=1&code=', $location);
    parse_str(parse_url($location, PHP_URL_QUERY), $result);
    $this->assertSame('1', $result['existing']);
    $this->assertSame('a b&c', $result['state'], 'state must survive encoding unchanged');
  }

  // The allow_destructive setting.

  /** The names of the tools the signed-in user is offered. */
  private function offeredTools(): array {
    $tokens = $this->fullFlow();
    return array_column($this->body($this->mcp($tokens['access_token']))['result']['tools'], 'name');
  }

  public function testDestructiveToolsAreOffWhenTheSettingIsMissing(): void {
    $tools = $this->offeredTools();
    $this->assertNotContains('unassign_from_projects', $tools);
    $this->assertContains('find_inactive_projects', $tools);
  }

  #[DataProvider('settingsThatKeepDestructiveToolsOff')]
  public function testDestructiveToolsStayOffForFalseOrNull(mixed $value): void {
    $this->app = $this->makeApp(['allow_destructive' => $value]);
    $this->assertNotContains('unassign_from_projects', $this->offeredTools());
  }

  public static function settingsThatKeepDestructiveToolsOff(): array {
    return [[FALSE], [NULL]];
  }

  public function testDestructiveToolsCanBeSwitchedOnInTheConfig(): void {
    $this->app = $this->makeApp(['allow_destructive' => TRUE]);
    $this->assertContains('unassign_from_projects', $this->offeredTools());
  }

  public function testACallIsRefusedWhenTheSettingIsOff(): void {
    $tokens = $this->fullFlow();
    $res = $this->req('POST', '/mcp', [
      'headers' => ['authorization' => 'Bearer ' . $tokens['access_token']],
      'body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
        'name' => 'unassign_from_projects', 'arguments' => ['projects' => ['x'], 'confirm' => TRUE],
      ]]),
    ]);
    $this->assertStringContainsString('is disabled', $this->body($res)['error']['message']);
  }

  #[DataProvider('settingsThatAreNotBooleans')]
  public function testTheSettingMustBeARealBoolean(mixed $value): void {
    // A string such as 'false' is truthy in PHP, which would switch the tools
    // on by mistake. Anything that is not true or false is a config error.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('allow_destructive must be true or false.');
    $this->makeApp(['allow_destructive' => $value]);
  }

  public static function settingsThatAreNotBooleans(): array {
    return [['false'], ['true'], ['no'], ['yes'], ['0'], ['1'], [0], [1], [[]], [['true']]];
  }

}
