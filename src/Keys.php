<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use SensitiveParameter;

/**
 * A key read by its prefix, as a configuration writes it: `whsk_` and `whsec_` sign, `whpk_` and `whsec_` verify.
 */
final class Keys
{
    public static function signing(#[SensitiveParameter] string $encoded): SigningKey
    {
        return match (true) {
            str_starts_with($encoded, Ed25519SecretKey::PREFIX) => Ed25519SecretKey::fromString($encoded),
            str_starts_with($encoded, HmacSecret::PREFIX) => HmacSecret::fromString($encoded),
            default => throw InvalidWebhookValue::because(\sprintf('A signing key starts with "%s" or "%s".', Ed25519SecretKey::PREFIX, HmacSecret::PREFIX)),
        };
    }

    public static function verification(#[SensitiveParameter] string $encoded): VerificationKey
    {
        return match (true) {
            str_starts_with($encoded, Ed25519PublicKey::PREFIX) => Ed25519PublicKey::fromString($encoded),
            str_starts_with($encoded, HmacSecret::PREFIX) => HmacSecret::fromString($encoded),
            default => throw InvalidWebhookValue::because(\sprintf('A verification key starts with "%s" or "%s".', Ed25519PublicKey::PREFIX, HmacSecret::PREFIX)),
        };
    }
}
