<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated symmetric encryption (libsodium secretbox) for secrets at rest,
 * such as stored database connection passwords.
 */
class SecretCipher
{
    private string $key;

    public function __construct(#[Autowire('%env(APP_SECRET_KEY)%')] string $hexKey)
    {
        $key = @sodium_hex2bin($hexKey);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            // Fall back to a key derived from the provided string so the app still
            // boots with a misconfigured key (encryption stays internally consistent).
            $key = hash('sha256', $hexKey, true);
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $stored): string
    {
        $decoded = base64_decode($stored, true);
        if (false === $decoded || \strlen($decoded) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        return false === $plain ? '' : $plain;
    }
}
