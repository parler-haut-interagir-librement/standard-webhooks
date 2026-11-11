<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;

/**
 * How far `webhook-timestamp` may be from the receiver's clock, in seconds, in either direction.
 *
 * The specification names no number, and neither does the library: the value is the application's. It
 * should sit above the clock drift the receiver tolerates and below the window for which processed
 * `webhook-id`s are kept, or a captured delivery replays once its id is forgotten.
 */
final readonly class Tolerance
{
    private function __construct(
        public int $seconds,
    ) {
    }

    public static function ofSeconds(int $seconds): self
    {
        if ($seconds <= 0) {
            throw InvalidWebhookValue::because(\sprintf('A timestamp tolerance is a positive number of seconds; got %d.', $seconds));
        }

        return new self($seconds);
    }
}
