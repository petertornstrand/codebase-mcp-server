<?php

namespace petertornstrand;

/**
 * Streamable HTTP transport for the Codebase MCP server.
 *
 * Stateless: every request carries an OAuth bearer token that resolves to the
 * caller's personal Codebase credentials.
 */
class HttpTransport {

  private const MAX_BODY_BYTES = 1048576;

  /**
   * @param callable $authenticate
   *   Called with the bearer token; returns ['username' => ..., 'api_key' => ...]
   *   or NULL if the token is not valid.
   * @param string $resourceMetadataUrl
   *   Advertised to clients on 401 so they can discover the OAuth server.
   * @param string[] $allowedOrigins
   *   Origins allowed to call the endpoint from a browser. Requests carrying
   *   any other Origin header are rejected (DNS rebinding protection).
   * @param ?string $apiUrl
   *   Optional Codebase API base URL override.
   */
  public function __construct(
    private $authenticate,
    private string $resourceMetadataUrl,
    private array $allowedOrigins = [],
    private ?string $apiUrl = null,
  ) {}

  /**
   * Handles one HTTP request.
   *
   * @param string $method
   *   The HTTP method.
   * @param array $headers
   *   Request headers, keyed by lower-case name.
   * @param string $body
   *   The raw request body.
   *
   * @return array{status: int, headers: array<string, string>, body: string}
   */
  public function handle(string $method, array $headers, string $body): array {
    if (isset($headers['origin']) && !in_array($headers['origin'], $this->allowedOrigins, true)) {
      return $this->error(403, 'Origin not allowed.');
    }

    if ($method !== 'POST') {
      return $this->error(405, 'Only POST is supported.', ['Allow' => 'POST']);
    }

    $credentials = null;
    if (preg_match('/^Bearer\s+(\S+)$/i', $headers['authorization'] ?? '', $matches)) {
      $credentials = ($this->authenticate)($matches[1]);
    }
    if ($credentials === null) {
      return $this->error(401, 'Invalid or missing access token.', [
        'WWW-Authenticate' => sprintf('Bearer resource_metadata="%s"', $this->resourceMetadataUrl),
      ]);
    }

    if (strlen($body) > self::MAX_BODY_BYTES) {
      return $this->error(413, 'Request body too large.');
    }

    $message = json_decode($body, true);
    if (!is_array($message) || $message === []) {
      return $this->rpcError(-32700, 'Parse error.');
    }

    $server = new CodebaseMCPServer($credentials['username'], $credentials['api_key'], null, $this->apiUrl);

    // A JSON array of messages is a batch; an object is a single message.
    $isBatch = array_is_list($message);
    $responses = [];
    foreach ($isBatch ? $message : [$message] as $item) {
      if (!is_array($item) || ($item['jsonrpc'] ?? '') !== '2.0') {
        $responses[] = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request.']];
        continue;
      }
      $response = $server->handle($item);
      if ($response !== null) {
        $responses[] = $response;
      }
    }

    // Only notifications/responses were received: accepted, nothing to say.
    if ($responses === []) {
      return ['status' => 202, 'headers' => [], 'body' => ''];
    }

    return $this->json(200, $isBatch ? $responses : $responses[0]);
  }

  private function json(int $status, array $payload, array $headers = []): array {
    return Response::json($status, $payload, $headers);
  }

  private function error(int $status, string $message, array $headers = []): array {
    return $this->json($status, ['error' => $message], $headers);
  }

  private function rpcError(int $code, string $message): array {
    return $this->json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]]);
  }

}
