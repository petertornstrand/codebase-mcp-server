<?php

namespace petertornstrand\Tests;

/**
 * Runs the fake Codebase API on a local port and records the requests it gets.
 */
class FakeCodebase {

  public string $url;

  private $process;

  private string $log;

  public function __construct() {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, FALSE), PHP_URL_PORT);
    fclose($socket);

    $this->url = "http://127.0.0.1:$port";
    $this->log = tempnam(sys_get_temp_dir(), 'fake-codebase');

    $this->process = proc_open(
      [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/fake-codebase.php'],
      [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
      $pipes,
      NULL,
      ['FAKE_LOG' => $this->log, 'PATH' => getenv('PATH')],
    );

    for ($i = 0; $i < 100; $i++) {
      if (@fsockopen('127.0.0.1', $port)) {
        return;
      }
      usleep(50000);
    }
    throw new \RuntimeException('Fake Codebase server did not start.');
  }

  public function __destruct() {
    proc_terminate($this->process);
    proc_close($this->process);
    @unlink($this->log);
  }

  public function reset(): void {
    file_put_contents($this->log, '');
  }

  /**
   * @return array[] The requests received since the last reset.
   */
  public function requests(): array {
    return array_map(fn($l) => json_decode($l, TRUE), array_filter(explode("\n", (string) file_get_contents($this->log))));
  }

}
