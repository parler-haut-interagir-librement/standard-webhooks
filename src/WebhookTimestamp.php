<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use DateTimeImmutable;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;

/**
 * The `webhook-timestamp` header: integer seconds since the Unix epoch, as the sender's clock saw the
 * attempt.
 *
 * Read strictly: decimal digits only, no sign, no leading zero, no fraction, no surrounding whitespace.
 * The header is part of the signed content, so a lenient parse would accept a string the sender never
 * signed in that form.
 */
final readonly class WebhookTimestamp
{
    private function __construct(
        public int $seconds,
    ) {
    }

    public static function fromHeader(string $header): self
    {
        // Twelve digits reach past the year 30000 and stay far from integer overflow.
        if (1 !== preg_match('/^(0|[1-9]\d{0,11})$/D', $header)) {
            throw InvalidWebhookValue::because(\sprintf('%s is not a webhook timestamp in Unix seconds.', InvalidWebhookValue::shown($header)));
        }

        return new self((int) $header);
    }

    public static function fromDateTime(DateTimeImmutable $at): self
    {
        $seconds = $at->getTimestamp();

        if ($seconds < 0) {
            throw InvalidWebhookValue::because('A webhook timestamp cannot precede the Unix epoch.');
        }

        return new self($seconds);
    }

    /**
     * The distance to the receiver's clock, in seconds, whichever side it falls on. A timestamp from the
     * future is as suspect as one from the past.
     */
    public function skewFrom(DateTimeImmutable $now): int
    {
        return abs($now->getTimestamp() - $this->seconds);
    }

    public function isWithin(Tolerance $tolerance, DateTimeImmutable $now): bool
    {
        return $this->skewFrom($now) <= $tolerance->seconds;
    }

    public function toHeader(): string
    {
        return (string) $this->seconds;
    }
}
