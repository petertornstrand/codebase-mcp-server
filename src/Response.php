<?php

namespace petertornstrand;

/**
 * Builders for the plain response arrays used by the HTTP layer.
 *
 * @phpstan-type Res array{status: int, headers: array<string, string|string[]>, body: string}
 */
final class Response {

  public static function json(int $status, mixed $payload, array $headers = []): array {
    return [
      'status' => $status,
      'headers' => ['Content-Type' => 'application/json'] + $headers,
      'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
  }

  public static function redirect(string $url, array $headers = []): array {
    return ['status' => 302, 'headers' => ['Location' => $url, 'Cache-Control' => 'no-store'] + $headers, 'body' => ''];
  }

  public static function html(int $status, string $body, array $headers = []): array {
    return [
      'status' => $status,
      'headers' => [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        // No form-action: Chrome applies it to the redirect after submit too.
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
      ] + $headers,
      'body' => $body,
    ];
  }

  public static function text(int $status, string $body, string $type, array $headers = []): array {
    return ['status' => $status, 'headers' => ['Content-Type' => $type] + $headers, 'body' => $body];
  }

}
