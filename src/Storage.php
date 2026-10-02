<?php

namespace petertornstrand;

/**
 * SQLite storage for OAuth clients, flow state, tokens and user credentials.
 *
 * Codes and tokens are only ever stored as SHA-256 hashes.
 */
class Storage {

  private \PDO $db;

  public function __construct(string $dataDir) {
    if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
      throw new \RuntimeException('Unable to create data directory.');
    }
    $file = rtrim($dataDir, '/') . '/codebase-mcp.sqlite';
    $new = !file_exists($file);
    $this->db = new \PDO('sqlite:' . $file, null, null, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    if ($new) {
      chmod($file, 0600);
    }
    $this->db->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    $this->migrate();
  }

  private function migrate(): void {
    $this->db->exec(<<<'SQL'
      CREATE TABLE IF NOT EXISTS clients (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, redirect_uris TEXT NOT NULL, created_at INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS pending (
        id TEXT PRIMARY KEY, client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL, state TEXT,
        code_challenge TEXT NOT NULL, saml_request_id TEXT NOT NULL, email TEXT, nonce_hash TEXT,
        expires_at INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS codes (
        hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL,
        code_challenge TEXT NOT NULL, email TEXT NOT NULL, expires_at INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS tokens (
        hash TEXT PRIMARY KEY, type TEXT NOT NULL, client_id TEXT NOT NULL, email TEXT NOT NULL,
        expires_at INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS users (
        email TEXT PRIMARY KEY, username TEXT NOT NULL, api_key_enc TEXT NOT NULL, updated_at INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS rate_limits (
        key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, hits INTEGER NOT NULL
      );
    SQL);
  }

  public static function hash(string $secret): string {
    return hash('sha256', $secret);
  }

  public function saveClient(string $id, string $name, array $redirectUris): void {
    $this->db->prepare('INSERT INTO clients VALUES (?, ?, ?, ?)')
      ->execute([$id, $name, json_encode($redirectUris), time()]);
  }

  public function getClient(string $id): ?array {
    $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? $row + ['uris' => json_decode($row['redirect_uris'], true)] : null;
  }

  public function savePending(array $row): void {
    $this->db->prepare('INSERT INTO pending (id, client_id, redirect_uri, state, code_challenge, saml_request_id, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$row['id'], $row['client_id'], $row['redirect_uri'], $row['state'], $row['code_challenge'], $row['saml_request_id'], $row['expires_at']]);
  }

  public function getPending(string $id): ?array {
    $stmt = $this->db->prepare('SELECT * FROM pending WHERE id = ? AND expires_at > ?');
    $stmt->execute([$id, time()]);
    return $stmt->fetch() ?: null;
  }

  /**
   * Marks a pending flow as authenticated. Succeeds only once per flow.
   */
  public function authenticatePending(string $id, string $email, string $nonceHash): bool {
    $stmt = $this->db->prepare('UPDATE pending SET email = ?, nonce_hash = ? WHERE id = ? AND email IS NULL AND expires_at > ?');
    $stmt->execute([$email, $nonceHash, $id, time()]);
    return $stmt->rowCount() === 1;
  }

  /**
   * Atomically removes a pending flow, so it can complete only once.
   */
  public function consumePending(string $id): bool {
    $stmt = $this->db->prepare('DELETE FROM pending WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() === 1;
  }

  public function saveCode(string $code, string $clientId, string $redirectUri, string $challenge, string $email, int $ttl): void {
    $this->db->prepare('INSERT INTO codes VALUES (?, ?, ?, ?, ?, ?)')
      ->execute([self::hash($code), $clientId, $redirectUri, $challenge, $email, time() + $ttl]);
  }

  /**
   * Returns and deletes an unexpired code (single use).
   */
  public function consumeCode(string $code): ?array {
    $hash = self::hash($code);
    $stmt = $this->db->prepare('SELECT * FROM codes WHERE hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    $delete = $this->db->prepare('DELETE FROM codes WHERE hash = ?');
    $delete->execute([$hash]);
    return $row && $delete->rowCount() === 1 && $row['expires_at'] > time() ? $row : null;
  }

  public function saveToken(string $token, string $type, string $clientId, string $email, int $ttl): void {
    $this->db->prepare('INSERT INTO tokens VALUES (?, ?, ?, ?, ?)')
      ->execute([self::hash($token), $type, $clientId, $email, time() + $ttl]);
  }

  public function getToken(string $token, string $type): ?array {
    $stmt = $this->db->prepare('SELECT * FROM tokens WHERE hash = ? AND type = ? AND expires_at > ?');
    $stmt->execute([self::hash($token), $type, time()]);
    return $stmt->fetch() ?: null;
  }

  /**
   * Deletes a token; returns whether it existed.
   */
  public function deleteToken(string $token): bool {
    $stmt = $this->db->prepare('DELETE FROM tokens WHERE hash = ?');
    $stmt->execute([self::hash($token)]);
    return $stmt->rowCount() === 1;
  }

  public function getUser(string $email): ?array {
    $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
  }

  public function saveUser(string $email, string $username, string $apiKeyEnc): void {
    $this->db->prepare('INSERT OR REPLACE INTO users VALUES (?, ?, ?, ?)')
      ->execute([$email, $username, $apiKeyEnc, time()]);
  }

  /**
   * Removes a user, their stored credentials and all their tokens.
   */
  public function deleteUser(string $email): void {
    $this->db->prepare('DELETE FROM tokens WHERE email = ?')->execute([$email]);
    $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
  }

  /**
   * Counts a hit against a fixed window; returns false once over the limit.
   */
  public function rateLimit(string $key, int $limit, int $window): bool {
    $now = time();
    // Values must be bound as integers: as text they compare greater than any
    // integer expression in SQLite, which would reset the window every time.
    $stmt = $this->db->prepare('INSERT INTO rate_limits VALUES (:key, :now, 1)
      ON CONFLICT(key) DO UPDATE SET
        hits = CASE WHEN window_start + :window <= :now THEN 1 ELSE hits + 1 END,
        window_start = CASE WHEN window_start + :window <= :now THEN :now ELSE window_start END');
    $stmt->bindValue(':key', $key);
    $stmt->bindValue(':now', $now, \PDO::PARAM_INT);
    $stmt->bindValue(':window', $window, \PDO::PARAM_INT);
    $stmt->execute();

    $stmt = $this->db->prepare('SELECT hits FROM rate_limits WHERE key = ?');
    $stmt->execute([$key]);
    return (int) $stmt->fetchColumn() <= $limit;
  }

  /**
   * Deletes expired rows and clients that were registered but never used.
   */
  public function purge(): void {
    $now = time();
    $this->db->prepare('DELETE FROM pending WHERE expires_at <= ?')->execute([$now]);
    $this->db->prepare('DELETE FROM codes WHERE expires_at <= ?')->execute([$now]);
    $this->db->prepare('DELETE FROM tokens WHERE expires_at <= ?')->execute([$now]);
    $this->db->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);
    $this->db->prepare('DELETE FROM clients WHERE created_at < ? AND id NOT IN (SELECT client_id FROM tokens) AND id NOT IN (SELECT client_id FROM pending)')
      ->execute([$now - 86400]);
  }

}
