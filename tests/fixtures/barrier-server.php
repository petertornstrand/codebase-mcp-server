<?php

// A barrier HTTP server, to measure how many requests a client sends at once.
//
// Usage: php barrier-server.php <port> <wait-for> <hold-seconds> <total> <result-file>
//
// Holds each request until <wait-for> are waiting, or <hold-seconds> have
// passed since the oldest arrived, then answers them all with 200 []. Writes
// the most requests ever held at once to <result-file>, and exits after
// answering <total> requests.

[, $port, $waitFor, $hold, $total, $resultFile] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
  fwrite(STDERR, "listen failed: $errstr\n");
  exit(1);
}
stream_set_blocking($server, FALSE);

$clients = [];   // id => stream, still sending its request
$buffers = [];
$waiting = [];   // id => stream, request complete, answer held
$oldest = NULL;
$peak = 0;
$answered = 0;
$giveUp = microtime(TRUE) + 30;

while ($answered < $total && microtime(TRUE) < $giveUp) {
  $read = array_merge([$server], $clients);
  $write = $except = [];
  if (stream_select($read, $write, $except, 0, 50000)) {
    foreach ($read as $stream) {
      if ($stream === $server) {
        while ($client = @stream_socket_accept($server, 0)) {
          stream_set_blocking($client, FALSE);
          $clients[(int) $client] = $client;
          $buffers[(int) $client] = '';
        }
        continue;
      }
      $id = (int) $stream;
      $buffers[$id] .= (string) fread($stream, 8192);
      if (str_contains($buffers[$id], "\r\n\r\n")) {
        $waiting[$id] = $stream;
        unset($clients[$id]);
        $oldest ??= microtime(TRUE);
      }
      elseif (feof($stream)) {
        unset($clients[$id], $buffers[$id]);
        fclose($stream);
      }
    }
  }

  $peak = max($peak, count($waiting));
  if ($waiting && (count($waiting) >= $waitFor || microtime(TRUE) - $oldest >= $hold)) {
    foreach ($waiting as $stream) {
      fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n[]");
      fclose($stream);
      $answered++;
    }
    $waiting = [];
    $oldest = NULL;
  }
}

file_put_contents($resultFile, (string) $peak);
