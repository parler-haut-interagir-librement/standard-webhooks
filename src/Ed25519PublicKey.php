<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Override;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use SodiumException;

/**
 * A `v1a` public key, written `whpk_` followed by the 32 bytes of the Ed25519 public key in base64.
 *
 * It verifies and cannot sign, so a receiver that holds it holds nothing that forges a delivery.
 */
final readonly class Ed25519PublicKey implements VerificationKey
{
    public const string PREFIX = 'whpk_';

    /**
     * @param non-empty-string $bytes
     */
    private function __construct(
        public string $bytes,
    ) {
    }

    public static function fromString(string $encoded): self
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw InvalidWebhookValue::because(\sprintf('A webhook public key starts with "%s".', self::PREFIX));
        }

        $bytes = Base64::decodePublic(substr($encoded, \strlen(self::PREFIX)));

        if (null === $bytes) {
            throw InvalidWebhookValue::because('A webhook public key is base64 after its prefix.');
        }

        return self::fromBytes($bytes);
    }

    public static function fromBytes(string $bytes): self
    {
        if (\SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($bytes)) {
            throw InvalidWebhookValue::because(\sprintf('A webhook public key is %d bytes; this one is %d.', \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, \strlen($bytes)));
        }

        return new self($bytes);
    }

    public function toString(): string
    {
        return self::PREFIX.Base64::encodePublic($this->bytes);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }

    #[Override]
    public function scheme(): Scheme
    {
        return Scheme::V1a;
    }

    #[Override]
    public function verifies(string $signedContent, Signature $signature): bool
    {
        try {
            return Scheme::V1a === $signature->scheme
                && sodium_crypto_sign_verify_detached($signature->bytes, $signedContent, $this->bytes);
        } catch (SodiumException) {
            return false;
        }
    }
}
