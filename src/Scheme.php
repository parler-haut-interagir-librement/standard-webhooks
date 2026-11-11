<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * The two signature schemes of Standard Webhooks 1.0.0, by their version label in `webhook-signature`.
 *
 * A version the receiver does not know is ignored, never accepted: `tryFrom()` returns `null` for it and
 * the entry drops out of the list.
 */
enum Scheme: string
{
    /** HMAC-SHA256 under a secret shared with the receiver. */
    case V1 = 'v1';

    /** Ed25519 under a key pair: the sender signs, the receiver verifies with the public key. */
    case V1a = 'v1a';

    /**
     * The length of a raw signature. An entry of another length cannot verify, so it is dropped at parsing
     * rather than handed to the primitive.
     */
    public function signatureLength(): int
    {
        return match ($this) {
            self::V1 => 32,
            self::V1a => \SODIUM_CRYPTO_SIGN_BYTES,
        };
    }
}
