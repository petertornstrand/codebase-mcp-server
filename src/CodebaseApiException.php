<?php

namespace petertornstrand;

/**
 * A failed Codebase API call, carrying the HTTP status.
 */
class CodebaseApiException extends \Exception {

  public function __construct(string $message, public readonly int $status = 0) {
    parent::__construct($message);
  }

}
