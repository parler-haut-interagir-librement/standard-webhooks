<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use SodiumException;

/**
 * Standard, padded base64 — the alphabet Standard Webhooks uses for signatures and keys.
 *
 * **Two codecs, chosen by what passes through them.** A secret key goes through libsodium's constant-time
 * codec (`encodeSecret()`, `decodeSecret()`), so the time an encoding takes says nothing about the bytes. A
 * signature or a public key is published anyway — in the `webhook-signature` header, in the receiver's
 * configuration — and goes through PHP's native codec (`encodePublic()`, `decodePublic()`), which is faster on
 * the path every delivery takes and hides nothing an observer does not already see. Both decoders are equally
 * strict: padding is required and any character outside the alphabet makes the input unreadable.
 */
final class Base64
{
    private function __construct()
    {
    }

    public static function encodeSecret(string $bytes): string
    {
        return sodium_bin2base64($bytes, \SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public static function decodeSecret(string $encoded): ?string
    {
        try {
            return sodium_base642bin($encoded, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            return null;
        }
    }

    public static function encodePublic(string $bytes): string
    {
        // @phpstan-ignore disallowed.function (a signature or a public key is not a secret)
        return base64_encode($bytes);
    }

    /**
     * Strict like `decodeSecret()`: PHP's strict mode rejects characters outside the alphabet, and the
     * re-encoding check rejects missing padding and non-canonical trailing bits, which strict mode accepts.
     */
    public static function decodePublic(string $encoded): ?string
    {
        // @phpstan-ignore disallowed.function (a signature or a public key is not a secret)
        $bytes = base64_decode($encoded, true);

        if (false === $bytes || self::encodePublic($bytes) !== $encoded) {
            return null;
        }

        return $bytes;
    }
}
