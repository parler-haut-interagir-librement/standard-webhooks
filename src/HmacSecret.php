<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Override;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use SensitiveParameter;

/**
 * A `v1` secret: HMAC-SHA256, shared between one sender and one receiver, written `whsec_` followed by the
 * secret in base64.
 *
 * Between 24 and 64 random bytes, as the specification requires. Use `v1` only where the receiver cannot be
 * asked to fetch a public key; where it can, `v1a` leaves nothing secret at the receiver. Keep one secret
 * per destination, so that a leak at one receiver forges nothing at another.
 */
final readonly class HmacSecret implements SigningKey, VerificationKey
{
    public const string PREFIX = 'whsec_';

    public const int MIN_BYTES = 24;

    public const int MAX_BYTES = 64;

    private function __construct(
        #[SensitiveParameter]
        private string $bytes,
    ) {
    }

    public static function generate(): self
    {
        return new self(random_bytes(32));
    }

    public static function fromString(#[SensitiveParameter] string $encoded): self
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw InvalidWebhookValue::because(\sprintf('A webhook secret starts with "%s".', self::PREFIX));
        }

        $bytes = Base64::decodeSecret(substr($encoded, \strlen(self::PREFIX)));

        if (null === $bytes) {
            throw InvalidWebhookValue::because('A webhook secret is base64 after its prefix.');
        }

        $length = \strlen($bytes);

        if ($length < self::MIN_BYTES || $length > self::MAX_BYTES) {
            throw InvalidWebhookValue::because(\sprintf('A webhook secret is %d to %d bytes; this one is %d.', self::MIN_BYTES, self::MAX_BYTES, $length));
        }

        return new self($bytes);
    }

    public function toString(): string
    {
        return self::PREFIX.Base64::encodeSecret($this->bytes);
    }

    #[Override]
    public function scheme(): Scheme
    {
        return Scheme::V1;
    }

    #[Override]
    public function sign(string $signedContent): Signature
    {
        return Signature::of(Scheme::V1, hash_hmac('sha256', $signedContent, $this->bytes, true));
    }

    /**
     * Constant-time comparison: one that stops at the first differing byte lets a forger measure, byte by
     * byte, how close a guess is.
     */
    #[Override]
    public function verifies(string $signedContent, Signature $signature): bool
    {
        return Scheme::V1 === $signature->scheme
            && hash_equals($this->sign($signedContent)->bytes, $signature->bytes);
    }

    /**
     * Whether any `v1` signature of a list verifies: the HMAC is computed once for the whole list, each signature
     * still compared in constant time.
     */
    public function verifiesAny(string $signedContent, SignatureList $signatures): bool
    {
        $expected = $this->sign($signedContent)->bytes;

        return array_any($signatures->signatures, static fn (Signature $signature): bool => Scheme::V1 === $signature->scheme && hash_equals($expected, $signature->bytes));
    }

    /**
     * Never print the secret through a dump or a log.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['bytes' => '[redacted]'];
    }
}
