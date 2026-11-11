<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;

/**
 * The `webhook-id` header: the message identifier, the same on every attempt of one delivery, which is what
 * lets a receiver deduplicate retries.
 *
 * The specification gives no syntax, only that the id must not contain a full stop, since the full stop
 * delimits the signed content. This class also refuses what has no place in a header or in a storage key:
 * an empty value, whitespace, control and non-ASCII characters, and more than `MAX_LENGTH` bytes.
 */
final readonly class WebhookId
{
    public const int MAX_LENGTH = 255;

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if ('' === $value) {
            throw InvalidWebhookValue::because('A webhook id cannot be empty.');
        }

        if (\strlen($value) > self::MAX_LENGTH) {
            throw InvalidWebhookValue::because(\sprintf('A webhook id is at most %d bytes; this one is %d.', self::MAX_LENGTH, \strlen($value)));
        }

        if (1 !== preg_match('/^[\x21-\x2D\x2F-\x7E]+$/D', $value)) {
            throw InvalidWebhookValue::because('A webhook id is printable ASCII without spaces or full stops.');
        }

        return new self($value);
    }

    /**
     * A new id, for a new delivery: `msg_` and 24 URL-safe base64 characters, 144 random bits.
     */
    public static function generate(): self
    {
        return new self('msg_'.sodium_bin2base64(random_bytes(18), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
