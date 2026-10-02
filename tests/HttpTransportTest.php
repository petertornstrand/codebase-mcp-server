<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use petertornstrand\HttpTransport;

class HttpTransportTest extends TestCase {

  private const METADATA = 'https://mcp.test/.well-known/oauth-protected-resource';

  private static FakeCodebase $api;

  /** Tokens passed to the authenticate callback. */
  private array $seenTokens = [];

  public static function setUpBeforeClass(): void {
    self::$api = new FakeCodebase();
  }

  protected function setUp(): void {
    self::$api->reset();
    $this->seenTokens = [];
  }

  private function transport(array $origins = [], bool $allowDestructive = FALSE): HttpTransport {
    return new HttpTransport(function (string $token): ?array {
      $this->seenTokens[] = $token;
      return $token === 'good-token' ? ['username' => 'acme/peter', 'api_key' => 'good-key'] : NULL;
    }, self::METADATA, $origins, self::$api->url, $allowDestructive);
  }

  private function post(array|string $message, array $headers = [], ?HttpTransport $transport = NULL): array {
    return ($transport ?? $this->transport())->handle(
      'POST',
      $headers + ['authorization' => 'Bearer good-token'],
      is_string($message) ? $message : json_encode($message),
    );
  }

  private function json(array $response): mixed {
    return json_decode($response['body'], TRUE);
  }

  private static function rpc(string $method, int $id = 1, array $params = []): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
  }

  public function testSingleRequestReturnsJson(): void {
    $res = $this->post(self::rpc('ping'));
    $this->assertSame(200, $res['status']);
    $this->assertSame('application/json', $res['headers']['Content-Type']);
    $this->assertSame(1, $this->json($res)['id']);
  }

  public function testToolCallUsesTheCredentialsOfTheToken(): void {
    $res = $this->post(self::rpc('tools/call', 3, ['name' => 'list_projects']));
    $this->assertArrayHasKey('result', $this->json($res));
    $request = self::$api->requests()[0];
    $this->assertSame(['acme/peter', 'good-key'], [$request['user'], $request['pass']]);
  }

  #[DataProvider('unauthorizedHeaders')]
  public function testRejectsMissingOrMalformedAuthorization(array $headers): void {
    $res = $this->transport()->handle('POST', $headers, json_encode(self::rpc('ping')));
    $this->assertSame(401, $res['status']);
    $this->assertSame('Bearer resource_metadata="' . self::METADATA . '"', $res['headers']['WWW-Authenticate']);
    $this->assertSame([], self::$api->requests());
  }

  public static function unauthorizedHeaders(): array {
    return [
      'none' => [[]],
      'empty' => [['authorization' => '']],
      'basic' => [['authorization' => 'Basic Z29vZC10b2tlbg==']],
      'no token' => [['authorization' => 'Bearer']],
      'no token with space' => [['authorization' => 'Bearer ']],
      'two tokens' => [['authorization' => 'Bearer good-token extra']],
      'wrong token' => [['authorization' => 'Bearer nope']],
      'token only' => [['authorization' => 'good-token']],
    ];
  }

  public function testBearerSchemeIsCaseInsensitiveAndTokenReachesTheCallback(): void {
    $res = $this->post(self::rpc('ping'), ['authorization' => 'bearer good-token']);
    $this->assertSame(200, $res['status']);
    $this->assertSame(['good-token'], $this->seenTokens);
  }

  public function testAuthenticationIsCheckedBeforeTheBodyIsParsed(): void {
    $res = $this->post('not json', ['authorization' => 'Bearer nope']);
    $this->assertSame(401, $res['status']);
  }

  #[DataProvider('otherMethods')]
  public function testOnlyPostIsAllowed(string $method): void {
    $res = $this->transport()->handle($method, ['authorization' => 'Bearer good-token'], '');
    $this->assertSame(405, $res['status']);
    $this->assertSame('POST', $res['headers']['Allow']);
  }

  public static function otherMethods(): array {
    return [['GET'], ['PUT'], ['DELETE'], ['OPTIONS'], ['HEAD']];
  }

  public function testOriginIsValidated(): void {
    $this->assertSame(403, $this->post(self::rpc('ping'), ['origin' => 'https://evil.test'])['status']);
    $this->assertSame(403, $this->post(self::rpc('ping'), ['origin' => 'https://good.test'], $this->transport([]))['status']);
    $this->assertSame(200, $this->post(self::rpc('ping'), ['origin' => 'https://good.test'], $this->transport(['https://good.test']))['status']);
    // No Origin header (server-to-server clients such as Claude.ai) is fine.
    $this->assertSame(200, $this->post(self::rpc('ping'))['status']);
  }

  public function testOriginIsCheckedBeforeAuthentication(): void {
    $res = $this->transport()->handle('POST', ['origin' => 'https://evil.test'], '');
    $this->assertSame(403, $res['status']);
    $this->assertSame([], $this->seenTokens);
  }

  public function testNotificationsGet202WithNoBody(): void {
    $res = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    $this->assertSame(202, $res['status']);
    $this->assertSame('', $res['body']);
  }

  public function testBatchOfOnlyNotificationsGets202(): void {
    $res = $this->post([
      ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
      ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled'],
    ]);
    $this->assertSame(202, $res['status']);
  }

  public function testBatchReturnsAnArrayInOrderAndSkipsNotifications(): void {
    $res = $this->post([
      self::rpc('ping', 1),
      ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
      self::rpc('nope', 2),
      self::rpc('tools/list', 3),
    ]);
    $this->assertSame(200, $res['status']);
    $body = $this->json($res);
    $this->assertSame([1, 2, 3], array_column($body, 'id'));
    $this->assertArrayHasKey('error', $body[1]);
    $this->assertArrayHasKey('result', $body[2]);
  }

  public function testSingleMessageInAnArrayStaysAnArray(): void {
    $body = $this->json($this->post([self::rpc('ping')]));
    $this->assertTrue(array_is_list($body));
  }

  #[DataProvider('invalidBodies')]
  public function testInvalidBodiesGetParseErrors(string $body): void {
    $res = $this->post($body);
    $this->assertSame(400, $res['status']);
    $this->assertSame(-32700, $this->json($res)['error']['code']);
  }

  public static function invalidBodies(): array {
    return [['not json'], [''], ['{'], ['[]'], ['{}'], ['"string"'], ['123'], ['null']];
  }

  public function testRequestsWithoutJsonRpcVersionAreInvalid(): void {
    $res = $this->post(['id' => 1, 'method' => 'ping']);
    $this->assertSame(-32600, $this->json($res)['error']['code']);
  }

  public function testInvalidItemsInABatchDoNotBlockTheRest(): void {
    $res = $this->post([self::rpc('ping', 1), 'garbage', ['id' => 5, 'method' => 'ping']]);
    $body = $this->json($res);
    $this->assertCount(3, $body);
    $this->assertSame(-32600, $body[1]['error']['code']);
    $this->assertSame(-32600, $body[2]['error']['code']);
    $this->assertArrayHasKey('result', $body[0]);
  }

  public function testOversizedBodiesAreRejected(): void {
    $big = json_encode(self::rpc('ping') + ['padding' => str_repeat('a', 1048577)]);
    $this->assertSame(413, $this->post($big)['status']);
  }

  public function testEachRequestIsIndependent(): void {
    // A different token on the next request must not reuse earlier credentials.
    $this->assertSame(200, $this->post(self::rpc('ping'))['status']);
    $this->assertSame(401, $this->post(self::rpc('ping'), ['authorization' => 'Bearer other'])['status']);
  }

  public function testDestructiveToolsFollowTheSetting(): void {
    $names = fn(HttpTransport $t) => array_column($this->json($this->post(self::rpc('tools/list'), [], $t))['result']['tools'], 'name');
    $this->assertNotContains('unassign_from_projects', $names($this->transport()), 'off by default');
    $this->assertNotContains('unassign_from_projects', $names($this->transport(allowDestructive: FALSE)));
    $this->assertContains('unassign_from_projects', $names($this->transport(allowDestructive: TRUE)));
  }

  public function testATransportBuiltWithoutTheSettingOffersNoDestructiveTools(): void {
    // The default itself must be safe, not only what callers pass in.
    $bare = new HttpTransport(fn() => ['username' => 'acme/peter', 'api_key' => 'good-key'], self::METADATA);
    $res = $this->post(self::rpc('tools/list'), [], $bare);
    $this->assertNotContains('unassign_from_projects', array_column($this->json($res)['result']['tools'], 'name'));

    $call = $this->post(self::rpc('tools/call', 2, ['name' => 'unassign_from_projects', 'arguments' => ['projects' => ['x'], 'confirm' => TRUE]]), [], $bare);
    $this->assertStringContainsString('is disabled', $this->json($call)['error']['message']);
  }

  public function testACallToADisabledToolIsRefusedOverHttp(): void {
    $res = $this->post(self::rpc('tools/call', 4, ['name' => 'unassign_from_projects', 'arguments' => ['projects' => ['ia-inactive'], 'confirm' => TRUE]]));
    $this->assertStringContainsString('is disabled', $this->json($res)['error']['message']);
    $this->assertSame([], self::$api->requests());
  }

}
