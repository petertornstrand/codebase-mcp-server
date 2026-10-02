<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use petertornstrand\Crypto;

class CryptoTest extends TestCase {

  private function crypto(?string $key = NULL): Crypto {
    return new Crypto($key ?? base64_encode(str_repeat('k', 32)));
  }

  public function testRoundTrip(): void {
    $crypto = $this->crypto();
    foreach (['secret', '', 'åäö ✓ 日本語', str_repeat('x', 10000), "line\nbreak\0nul"] as $value) {
      $this->assertSame($value, $crypto->decrypt($crypto->encrypt($value, 'a@b.se'), 'a@b.se'));
    }
  }

  public function testCiphertextDoesNotContainThePlaintextAndIsRandomised(): void {
    $crypto = $this->crypto();
    $first = $crypto->encrypt('super-secret-key', 'a@b.se');
    $this->assertStringNotContainsString('super-secret-key', $first);
    $this->assertStringNotContainsString('super-secret-key', base64_decode($first));
    $this->assertNotSame($first, $crypto->encrypt('super-secret-key', 'a@b.se'));
  }

  public function testCiphertextIsBoundToItsContext(): void {
    $crypto = $this->crypto();
    $encrypted = $crypto->encrypt('secret', 'alice@happiness.se');
    $this->expectException(\RuntimeException::class);
    $crypto->decrypt($encrypted, 'bob@happiness.se');
  }

  public function testTamperedCiphertextIsRejected(): void {
    $crypto = $this->crypto();
    $raw = base64_decode($crypto->encrypt('secret', 'a@b.se'));
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
    $this->expectException(\RuntimeException::class);
    $crypto->decrypt(base64_encode($raw), 'a@b.se');
  }

  public function testAnotherKeyCannotDecrypt(): void {
    $encrypted = $this->crypto()->encrypt('secret', 'a@b.se');
    $this->expectException(\RuntimeException::class);
    $this->crypto(base64_encode(str_repeat('z', 32)))->decrypt($encrypted, 'a@b.se');
  }

  #[DataProvider('garbage')]
  public function testGarbageInputIsRejected(string $input): void {
    $this->expectException(\RuntimeException::class);
    $this->crypto()->decrypt($input, 'a@b.se');
  }

  public static function garbage(): array {
    return [[''], ['not base64!!'], [base64_encode('short')], [base64_encode(str_repeat('a', 24))]];
  }

  #[DataProvider('badKeys')]
  public function testRejectsInvalidKeys(string $key): void {
    $this->expectException(\InvalidArgumentException::class);
    new Crypto($key);
  }

  public static function badKeys(): array {
    return [[''], ['<base64 32 byte key>'], [base64_encode('too short')], [base64_encode(str_repeat('a', 33))], ['!!!']];
  }

}
