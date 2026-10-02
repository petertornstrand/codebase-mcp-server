<?php

namespace petertornstrand\Tests;

/**
 * Runs the barrier server, which reports how many requests a client sent at
 * once. See fixtures/barrier-server.php.
 */
class BarrierServer {

  public string $url;

  private $process;

  private string $result;

  /**
   * @param int $waitFor
   *   Requests to collect before answering.
   * @param float $hold
   *   Seconds to wait for more before answering what has arrived.
   * @param int $total
   *   Requests to answer before stopping.
   */
  public function __construct(int $waitFor, float $hold, int $total) {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, FALSE), PHP_URL_PORT);
    fclose($socket);

    $this->url = "http://127.0.0.1:$port";
    $this->result = tempnam(sys_get_temp_dir(), 'barrier');
    $this->process = proc_open(
      [PHP_BINARY, __DIR__ . '/fixtures/barrier-server.php', (string) $port, (string) $waitFor, (string) $hold, (string) $total, $this->result],
      [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
      $pipes,
    );

    for ($i = 0; $i < 100; $i++) {
      if (@fsockopen('127.0.0.1', $port)) {
        // That probe connection is not a request; the server ignores it.
        return;
      }
      usleep(50000);
    }
    throw new \RuntimeException('Barrier server did not start.');
  }

  /**
   * Waits for the server to finish and returns the peak number of requests
   * it held at the same time.
   */
  public function peak(): int {
    proc_close($this->process);
    $peak = (int) file_get_contents($this->result);
    @unlink($this->result);
    return $peak;
  }

}
