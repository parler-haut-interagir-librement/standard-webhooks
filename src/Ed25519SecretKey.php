<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Override;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use SensitiveParameter;

/**
 * A `v1a` secret key, written `whsk_` followed by the key in base64.
 *
 * The specification does not say which bytes are encoded, so both common forms are read: the 32-byte seed,
 * and the 64-byte libsodium secret key (seed followed by public key), whose second half must be the public
 * key the seed derives. The key is always written back in the 64-byte form.
 */
final readonly class Ed25519SecretKey implements SigningKey
{
    public const string PREFIX = 'whsk_';

    /**
     * @param non-empty-string $secretKey
     */
    private function __construct(
        #[SensitiveParameter]
        private string $secretKey,
    ) {
    }

    public static function generate(): self
    {
        return new self(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    }

    public static function fromString(#[SensitiveParameter] string $encoded): self
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw InvalidWebhookValue::because(\sprintf('A webhook secret key starts with "%s".', self::PREFIX));
        }

        $bytes = Base64::decodeSecret(substr($encoded, \strlen(self::PREFIX)));

        if (null === $bytes) {
            throw InvalidWebhookValue::because('A webhook secret key is base64 after its prefix.');
        }

        return self::fromBytes($bytes);
    }

    public static function fromBytes(#[SensitiveParameter] string $bytes): self
    {
        $length = \strlen($bytes);

        if (\SODIUM_CRYPTO_SIGN_SEEDBYTES !== $length && \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== $length) {
            throw InvalidWebhookValue::because(\sprintf('A webhook secret key is a %d-byte seed or a %d-byte secret key; this one is %d bytes.', \SODIUM_CRYPTO_SIGN_SEEDBYTES, \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, $length));
        }

        $seed = substr($bytes, 0, \SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));

        if (\SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === $length && !hash_equals($secretKey, $bytes)) {
            throw InvalidWebhookValue::because('The public half of this webhook secret key does not match its seed.');
        }

        return new self($secretKey);
    }

    public function toString(): string
    {
        return self::PREFIX.Base64::encodeSecret($this->secretKey);
    }

    public function publicKey(): Ed25519PublicKey
    {
        return Ed25519PublicKey::fromBytes(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }

    #[Override]
    public function scheme(): Scheme
    {
        return Scheme::V1a;
    }

    #[Override]
    public function sign(string $signedContent): Signature
    {
        return Signature::of(Scheme::V1a, sodium_crypto_sign_detached($signedContent, $this->secretKey));
    }

    /**
     * Never print the key through a dump or a log.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['secretKey' => '[redacted]'];
    }
}
