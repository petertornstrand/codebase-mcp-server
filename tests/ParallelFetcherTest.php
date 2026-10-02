<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\TestCase;
use petertornstrand\ParallelFetcher;

class ParallelFetcherTest extends TestCase {

  private static FakeCodebase $api;

  public static function setUpBeforeClass(): void {
    self::$api = new FakeCodebase();
  }

  protected function setUp(): void {
    self::$api->reset();
  }

  private function fetcher(int $concurrency = 8, float $timeLimit = 20.0, float $requestTimeout = 15.0, string $key = 'good-key'): ParallelFetcher {
    return new ParallelFetcher(self::$api->url, 'acme/peter', $key, $concurrency, $timeLimit, $requestTimeout);
  }

  public function testReturnsEveryKeyInOrderWithStatusAndBody(): void {
    $results = $this->fetcher()->get([
      'b' => '/beta/tickets.json?query=x',
      'a' => '/acme/tickets.json',
      'q' => '/quiet/tickets.json',
    ]);
    $this->assertSame(['b', 'a', 'q'], array_keys($results));
    $this->assertSame(200, $results['a']['status']);
    $this->assertCount(2, json_decode($results['a']['body'], TRUE));
    $this->assertSame(404, $results['q']['status'], 'HTTP errors are results, not failures');
    $this->assertNull($results['q']['error']);
    $beta = array_values(array_filter(self::$api->requests(), fn($r) => $r['path'] === '/beta/tickets.json'));
    $this->assertSame('x', $beta[0]['query']['query']);
  }

  public function testSendsTheCallersCredentials(): void {
    $this->fetcher()->get(['a' => '/acme/tickets.json']);
    $this->assertSame(['acme/peter', 'good-key'], [self::$api->requests()[0]['user'], self::$api->requests()[0]['pass']]);
    $this->assertSame(401, $this->fetcher(key: 'wrong')->get(['a' => '/acme/tickets.json'])['a']['status']);
  }

  /**
   * The most requests a fetcher with the given concurrency had in flight at
   * once, measured by a server that holds requests until enough have arrived.
   */
  private function peak(int $concurrency, int $requests, int $waitFor): int {
    $barrier = new BarrierServer($waitFor, 0.3, $requests);
    $fetcher = new ParallelFetcher($barrier->url, 'u', 'k', $concurrency);
    $results = $fetcher->get(array_fill_keys(array_map(fn($i) => "r$i", range(1, $requests)), '/x.json'));
    $this->assertSame(array_fill(0, $requests, 200), array_values(array_column($results, 'status')));
    return $barrier->peak();
  }

  public function testRequestsAreSentAtTheSameTime(): void {
    // The server answers only once all six have arrived, so this can only
    // reach six if the client really had them in flight together.
    $this->assertSame(6, $this->peak(concurrency: 6, requests: 6, waitFor: 6));
  }

  public function testConcurrencyIsCapped(): void {
    $this->assertSame(1, $this->peak(concurrency: 1, requests: 3, waitFor: 3), 'one at a time');
    $this->assertSame(3, $this->peak(concurrency: 3, requests: 7, waitFor: 7), 'never more than the cap');
  }

  public function testTransportFailuresAreReportedPerKey(): void {
    $fetcher = new ParallelFetcher('http://127.0.0.1:1', 'u', 'k');
    $results = $fetcher->get(['a' => '/x.json', 'b' => '/y.json']);
    foreach ($results as $result) {
      $this->assertNull($result['status']);
      $this->assertNull($result['body']);
      $this->assertStringContainsString('cURL error', $result['error']);
    }
  }

  public function testSlowRequestsTimeOutAndEarlierResultsAreKept(): void {
    // One at a time, so the fast request cannot be queued behind the slow one.
    $start = microtime(TRUE);
    $results = $this->fetcher(concurrency: 1, requestTimeout: 1.0)->get(['a' => '/acme/tickets.json', 'slow' => '/slow/tickets.json']);
    $this->assertLessThan(2.5, microtime(TRUE) - $start, 'must not wait for the slow request');
    $this->assertSame(200, $results['a']['status']);
    $this->assertStringContainsString('timed out', $results['slow']['error']);
  }

  public function testRequestsThatNeverStartedAreReportedAsNotChecked(): void {
    // One at a time with a 1s budget: the first request eats it.
    $results = $this->fetcher(concurrency: 1, timeLimit: 1.0)->get([
      'slow' => '/slow/tickets.json', 'a' => '/acme/tickets.json', 'b' => '/beta/tickets.json',
    ]);
    $this->assertNotNull($results['slow']['error']);
    foreach (['a', 'b'] as $key) {
      $this->assertSame('Not checked: time limit reached.', $results[$key]['error']);
    }
    $this->assertSame(['slow', 'a', 'b'], array_keys($results));
  }

  public function testEmptyInput(): void {
    $this->assertSame([], $this->fetcher()->get([]));
  }

}
