<?php

$buildDir = getenv('BUILD_DIR') ?: __DIR__ . '/build';

$pharFile = $buildDir . '/codebase-mcp-server.phar';

if (ini_get('phar.readonly')) {
  fwrite(STDERR, "phar.readonly is enabled. Run with: php -d phar.readonly=0 build-phar.php\n");
  exit(1);
}

if (!is_dir($buildDir) && !mkdir($buildDir, 0775, true) && !is_dir($buildDir)) {
  fwrite(STDERR, "Failed to create build directory.\n");
  exit(1);
}

if (file_exists($pharFile) && !unlink($pharFile)) {
  fwrite(STDERR, "Failed to remove existing phar file.\n");
  exit(1);
}

$phar = new Phar($pharFile);
$phar->startBuffering();

$files = [
  'src/CodebaseMCPServer.php',
  'codebase-mcp-server.php',
];

foreach ($files as $file) {
  $fullPath = __DIR__ . '/' . $file;
  if (!file_exists($fullPath)) {
    fwrite(STDERR, sprintf("Missing source file: %s\n", $file));
    exit(1);
  }
  $phar->addFile($fullPath, $file);
}

$stub = <<<'PHP'
#!/usr/bin/env php
<?php
Phar::mapPhar('codebase-mcp-server.phar');
require 'phar://codebase-mcp-server.phar/codebase-mcp-server.php';
__HALT_COMPILER();
PHP;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($pharFile, 0755);

fwrite(STDOUT, "Built: " . $pharFile . PHP_EOL);