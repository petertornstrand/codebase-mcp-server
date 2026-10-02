<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\TestCase;
use petertornstrand\Storage;

class StorageTest extends TestCase {

  private string $dir;

  private Storage $storage;

  protected function setUp(): void {
    $this->dir = sys_get_temp_dir() . '/mcp-storage-' . bin2hex(random_bytes(4));
    $this->storage = new Storage($this->dir);
  }

  protected function tearDown(): void {
    array_map('unlink', glob($this->dir . '/*'));
    @rmdir($this->dir);
  }

  /** A second connection to the same database, for backdating rows. */
  private function db(): \PDO {
    return new \PDO('sqlite:' . $this->dir . '/codebase-mcp.sqlite', NULL, NULL, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
  }

  private function pending(string $id = 'flow1', int $ttl = 600): array {
    return [
      'id' => $id, 'client_id' => 'c1', 'redirect_uri' => 'https://a.test/cb', 'state' => 's',
      'code_challenge' => 'ch', 'saml_request_id' => '_req', 'expires_at' => time() + $ttl,
    ];
  }

  public function testDataDirectoryAndDatabaseArePrivate(): void {
    $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
    $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/codebase-mcp.sqlite')), -4));
  }

  public function testReopeningKeepsData(): void {
    $this->storage->saveUser('a@b.se', 'u', 'enc');
    $again = new Storage($this->dir);
    $this->assertSame('u', $again->getUser('a@b.se')['username']);
  }

  public function testClients(): void {
    $this->storage->saveClient('c1', 'Claude', ['https://a.test/cb', 'http://localhost:1/cb']);
    $client = $this->storage->getClient('c1');
    $this->assertSame('Claude', $client['name']);
    $this->assertSame(['https://a.test/cb', 'http://localhost:1/cb'], $client['uris']);
    $this->assertNull($this->storage->getClient('missing'));
  }

  public function testPendingFlowCanBeAuthenticatedOnlyOnce(): void {
    $this->storage->savePending($this->pending());
    $this->assertNull($this->storage->getPending('flow1')['email']);

    $this->assertTrue($this->storage->authenticatePending('flow1', 'a@b.se', 'hash'));
    $this->assertFalse($this->storage->authenticatePending('flow1', 'evil@b.se', 'hash2'));
    $this->assertSame('a@b.se', $this->storage->getPending('flow1')['email']);
  }

  public function testExpiredPendingFlowsAreInvisible(): void {
    $this->storage->savePending($this->pending('old', -1));
    $this->assertNull($this->storage->getPending('old'));
    $this->assertFalse($this->storage->authenticatePending('old', 'a@b.se', 'h'));
  }

  public function testPendingFlowCanBeConsumedOnlyOnce(): void {
    $this->storage->savePending($this->pending());
    $this->assertTrue($this->storage->consumePending('flow1'));
    $this->assertFalse($this->storage->consumePending('flow1'));
    $this->assertNull($this->storage->getPending('flow1'));
  }

  public function testCodesAreSingleUse(): void {
    $this->storage->saveCode('the-code', 'c1', 'https://a.test/cb', 'ch', 'a@b.se', 60);
    $row = $this->storage->consumeCode('the-code');
    $this->assertSame(['c1', 'https://a.test/cb', 'ch', 'a@b.se'], [$row['client_id'], $row['redirect_uri'], $row['code_challenge'], $row['email']]);
    $this->assertNull($this->storage->consumeCode('the-code'));
    $this->assertNull($this->storage->consumeCode('never-issued'));
  }

  public function testExpiredCodesAreRejectedAndRemoved(): void {
    $this->storage->saveCode('old-code', 'c1', 'u', 'ch', 'a@b.se', -1);
    $this->assertNull($this->storage->consumeCode('old-code'));
    $this->assertNull($this->storage->consumeCode('old-code'));
  }

  public function testSecretsAreOnlyStoredHashed(): void {
    $this->storage->saveCode('plain-code-123', 'c1', 'u', 'ch', 'a@b.se', 60);
    $this->storage->saveToken('plain-token-456', 'access', 'c1', 'a@b.se', 60);
    $raw = implode('', array_map('file_get_contents', glob($this->dir . '/*')));
    $this->assertFalse(str_contains($raw, 'plain-code-123'));
    $this->assertFalse(str_contains($raw, 'plain-token-456'));
    $this->assertTrue(str_contains($raw, Storage::hash('plain-token-456')));
  }

  public function testTokensAreTypedAndExpire(): void {
    $this->storage->saveToken('t1', 'access', 'c1', 'a@b.se', 60);
    $this->storage->saveToken('t2', 'refresh', 'c1', 'a@b.se', 60);
    $this->storage->saveToken('t3', 'access', 'c1', 'a@b.se', -1);

    $this->assertSame('a@b.se', $this->storage->getToken('t1', 'access')['email']);
    $this->assertNull($this->storage->getToken('t1', 'refresh'), 'access token must not pass as refresh');
    $this->assertNull($this->storage->getToken('t2', 'access'), 'refresh token must not pass as access');
    $this->assertNull($this->storage->getToken('t3', 'access'), 'expired');
    $this->assertNull($this->storage->getToken('unknown', 'access'));
  }

  public function testDeleteTokenReportsWhetherItExisted(): void {
    $this->storage->saveToken('t1', 'refresh', 'c1', 'a@b.se', 60);
    $this->assertTrue($this->storage->deleteToken('t1'));
    $this->assertFalse($this->storage->deleteToken('t1'));
  }

  public function testUsersAreReplacedNotDuplicated(): void {
    $this->storage->saveUser('a@b.se', 'old', 'enc1');
    $this->storage->saveUser('a@b.se', 'new', 'enc2');
    $this->assertSame(['new', 'enc2'], [$this->storage->getUser('a@b.se')['username'], $this->storage->getUser('a@b.se')['api_key_enc']]);
    $this->assertNull($this->storage->getUser('other@b.se'));
  }

  public function testDeleteUserRemovesCredentialsAndOnlyTheirTokens(): void {
    $this->storage->saveUser('a@b.se', 'u', 'enc');
    $this->storage->saveUser('c@b.se', 'u2', 'enc2');
    $this->storage->saveToken('a1', 'access', 'c1', 'a@b.se', 60);
    $this->storage->saveToken('a2', 'refresh', 'c1', 'a@b.se', 60);
    $this->storage->saveToken('c1t', 'access', 'c1', 'c@b.se', 60);

    $this->storage->deleteUser('a@b.se');

    $this->assertNull($this->storage->getUser('a@b.se'));
    $this->assertNull($this->storage->getToken('a1', 'access'));
    $this->assertNull($this->storage->getToken('a2', 'refresh'));
    $this->assertNotNull($this->storage->getUser('c@b.se'));
    $this->assertNotNull($this->storage->getToken('c1t', 'access'));
  }

  public function testRateLimitBlocksAfterTheLimitPerKey(): void {
    foreach ([1, 2, 3] as $hit) {
      $this->assertTrue($this->storage->rateLimit('k', 3, 60), "hit $hit");
    }
    $this->assertFalse($this->storage->rateLimit('k', 3, 60));
    $this->assertFalse($this->storage->rateLimit('k', 3, 60));
    $this->assertTrue($this->storage->rateLimit('other', 3, 60), 'keys are independent');
  }

  public function testRateLimitWindowResets(): void {
    $this->assertTrue($this->storage->rateLimit('k', 1, 60));
    $this->assertFalse($this->storage->rateLimit('k', 1, 60));
    $this->db()->exec('UPDATE rate_limits SET window_start = window_start - 120');
    $this->assertTrue($this->storage->rateLimit('k', 1, 60));
    $this->assertFalse($this->storage->rateLimit('k', 1, 60));
  }

  public function testPurgeRemovesExpiredRowsAndStaleUnusedClients(): void {
    $this->storage->savePending($this->pending('expired', -1));
    $this->storage->savePending($this->pending('live', 600));
    $this->storage->saveCode('old', 'c1', 'u', 'ch', 'a@b.se', -1);
    $this->storage->saveToken('expired-token', 'access', 'used', 'a@b.se', -1);
    $this->storage->saveToken('live-token', 'refresh', 'used', 'a@b.se', 600);

    $this->storage->saveClient('stale-unused', 'x', ['https://a.test/cb']);
    $this->storage->saveClient('stale-used', 'x', ['https://a.test/cb']);
    $this->storage->saveClient('fresh', 'x', ['https://a.test/cb']);
    $this->storage->saveClient('used', 'x', ['https://a.test/cb']);
    $this->db()->exec("UPDATE clients SET created_at = created_at - 200000 WHERE id IN ('stale-unused', 'stale-used', 'used')");
    $this->storage->saveToken('keeps-client', 'refresh', 'stale-used', 'a@b.se', 600);

    $this->storage->purge();

    $db = $this->db();
    $this->assertSame(['live'], $db->query('SELECT id FROM pending')->fetchAll(\PDO::FETCH_COLUMN));
    $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM codes')->fetchColumn());
    $this->assertEqualsCanonicalizing(
      [Storage::hash('live-token'), Storage::hash('keeps-client')],
      $db->query('SELECT hash FROM tokens')->fetchAll(\PDO::FETCH_COLUMN)
    );
    $this->assertNull($this->storage->getClient('stale-unused'));
    $this->assertNotNull($this->storage->getClient('stale-used'), 'clients with tokens are kept');
    $this->assertNotNull($this->storage->getClient('fresh'), 'recent clients are kept');
    $this->assertNotNull($this->storage->getClient('used'), 'clients with live tokens are kept');
  }

}
