<?php

namespace petertornstrand;

/**
 * Runs many GET requests against the Codebase API at once (curl_multi).
 *
 * Concurrency is capped, and everything shares one time limit: requests that
 * have not finished by then are reported as such, never silently dropped.
 */
class ParallelFetcher {

  /** Seconds below which starting another request is pointless. */
  private const MIN_START_BUDGET = 0.05;

  /**
   * @param int $concurrency
   *   Maximum requests in flight at once.
   * @param float $timeLimit
   *   Seconds for the whole batch.
   * @param float $requestTimeout
   *   Seconds for a single request.
   */
  public function __construct(
    private string $baseUrl,
    private string $username,
    private string $apiKey,
    private int $concurrency = 8,
    private float $timeLimit = 20.0,
    private float $requestTimeout = 15.0,
  ) {}

  /**
   * @param array<string, string> $paths
   *   Request paths including the query string, keyed by caller-chosen key.
   *
   * @return array<string, array{status: ?int, body: ?string, error: ?string}>
   *   One entry per key, in the same order. On a transport failure, status
   *   and body are NULL and error says why.
   */
  public function get(array $paths): array {
    $start = microtime(TRUE);
    $remaining = fn(): float => $this->timeLimit - (microtime(TRUE) - $start);

    $queue = array_keys($paths);
    $results = array_fill_keys($queue, NULL);
    $active = [];
    $multi = curl_multi_init();

    do {
      // Start requests while there is room and time.
      while ($queue && count($active) < $this->concurrency && $remaining() > self::MIN_START_BUDGET) {
        $key = array_shift($queue);
        $ch = curl_init($this->baseUrl . $paths[$key]);
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => TRUE,
          CURLOPT_CONNECTTIMEOUT_MS => 5000,
          // A request can never outlive the batch.
          CURLOPT_TIMEOUT_MS => max(1, (int) (min($this->requestTimeout, $remaining()) * 1000)),
          CURLOPT_USERPWD => "$this->username:$this->apiKey",
          CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($multi, $ch);
        $active[spl_object_id($ch)] = $key;
      }

      curl_multi_exec($multi, $running);

      while ($done = curl_multi_info_read($multi)) {
        $ch = $done['handle'];
        $key = $active[spl_object_id($ch)];
        unset($active[spl_object_id($ch)]);

        $results[$key] = $done['result'] === CURLE_OK
          ? ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string) curl_multi_getcontent($ch), 'error' => NULL]
          : ['status' => NULL, 'body' => NULL, 'error' => 'cURL error: ' . curl_error($ch)];
        curl_multi_remove_handle($multi, $ch);
      }

      if ($active && curl_multi_select($multi, 0.1) === -1) {
        usleep(1000);
      }
    } while ($active || ($queue && $remaining() > self::MIN_START_BUDGET));

    curl_multi_close($multi);

    // Whatever never started ran out of time.
    foreach ($queue as $key) {
      $results[$key] = ['status' => NULL, 'body' => NULL, 'error' => 'Not checked: time limit reached.'];
    }
    return $results;
  }

}
