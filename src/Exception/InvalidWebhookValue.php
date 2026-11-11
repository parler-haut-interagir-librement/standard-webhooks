<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Exception;

use InvalidArgumentException;

/**
 * A header, a key or an envelope refused its own construction: the input is malformed, not merely
 * unverified. A receiver answers `400`.
 *
 * The message may reach a log line. A value read from an unverified request is shown through
 * {@see self::shown()}: cut to its first {@see self::SHOWN_LENGTH} bytes and with every control, quote,
 * backslash or non-ASCII byte escaped, so that a sender controls neither the length nor the layout of the line.
 */
final class InvalidWebhookValue extends InvalidArgumentException implements WebhookException
{
    /** How many bytes of an unverified value a message shows. */
    public const int SHOWN_LENGTH = 32;

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /**
     * An unverified value, quoted for a message: at most {@see self::SHOWN_LENGTH} bytes, `...` after a cut,
     * and the bytes outside printable ASCII, the quote and the backslash escaped in C style.
     */
    public static function shown(string $value): string
    {
        $cut = \strlen($value) > self::SHOWN_LENGTH;

        return '"'.addcslashes($cut ? substr($value, 0, self::SHOWN_LENGTH) : $value, "\0..\37\"\\\177..\377").'"'.($cut ? '...' : '');
    }
}
