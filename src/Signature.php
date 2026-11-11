<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;

/**
 * One entry of `webhook-signature`: `{version},{base64 signature}`.
 */
final readonly class Signature
{
    /**
     * @param non-empty-string $bytes
     */
    private function __construct(
        public Scheme $scheme,
        public string $bytes,
    ) {
    }

    public static function of(Scheme $scheme, string $bytes): self
    {
        // The empty check is implied by the length, and states it to the type checker.
        if ('' === $bytes || $scheme->signatureLength() !== \strlen($bytes)) {
            throw InvalidWebhookValue::because(\sprintf('A %s signature is %d bytes; this one is %d.', $scheme->value, $scheme->signatureLength(), \strlen($bytes)));
        }

        return new self($scheme, $bytes);
    }

    /**
     * Reads one entry, or returns `null` for an entry that cannot verify: an unknown version, a missing
     * comma, a payload that is not strict base64, or a signature of the wrong length. Such an entry is
     * ignored rather than fatal, so a sender may add a scheme the receiver does not know yet.
     */
    public static function tryFromHeaderEntry(string $entry): ?self
    {
        $parts = explode(',', $entry);

        if (2 !== \count($parts)) {
            return null;
        }

        $scheme = Scheme::tryFrom($parts[0]);
        $bytes = Base64::decodePublic($parts[1]);

        if (null === $scheme || null === $bytes || '' === $bytes || $scheme->signatureLength() !== \strlen($bytes)) {
            return null;
        }

        return new self($scheme, $bytes);
    }

    public function toHeaderEntry(): string
    {
        return $this->scheme->value.','.Base64::encodePublic($this->bytes);
    }
}
