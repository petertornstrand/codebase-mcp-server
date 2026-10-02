<?php

namespace petertornstrand;

/**
 * Authenticated encryption for secrets stored at rest (XChaCha20-Poly1305).
 */
class Crypto {

  private string $key;

  /**
   * @param string $base64Key
   *   A base64 encoded 32 byte key. Generate with:
   *   php -r 'echo base64_encode(random_bytes(32)), "\n";'
   */
  public function __construct(string $base64Key) {
    $key = base64_decode($base64Key, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
      throw new \InvalidArgumentException('encryption_key must be a base64 encoded 32 byte key.');
    }
    $this->key = $key;
  }

  /**
   * Encrypts a value, binding it to a context (such as the owner's email) so
   * ciphertext cannot be moved between rows.
   */
  public function encrypt(string $plaintext, string $context): string {
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key);
    return base64_encode($nonce . $cipher);
  }

  /**
   * @throws \RuntimeException If the value is corrupt or the context differs.
   */
  public function decrypt(string $encoded, string $context): string {
    $raw = base64_decode($encoded, true);
    $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    if ($raw === false || strlen($raw) <= $nonceLength) {
      throw new \RuntimeException('Unable to decrypt value.');
    }
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
      substr($raw, $nonceLength), $context, substr($raw, 0, $nonceLength), $this->key
    );
    if ($plain === false) {
      throw new \RuntimeException('Unable to decrypt value.');
    }
    return $plain;
  }

}
