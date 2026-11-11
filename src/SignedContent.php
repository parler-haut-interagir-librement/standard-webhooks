<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * The bytes both schemes sign: `{webhook-id}.{webhook-timestamp}.{body}`.
 *
 * **The body is the raw body as received, byte for byte**, verified before any decoding. A body that is
 * decoded and re-encoded before verification is a different string as soon as a key order, an escape or a
 * whitespace differs, and the signature fails for a reason nobody can see.
 *
 * The full stop is unambiguous only because `WebhookId` refuses one and a timestamp is digits.
 */
final class SignedContent
{
    private function __construct()
    {
    }

    public static function of(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody): string
    {
        return $id->value.'.'.$timestamp->seconds.'.'.$rawBody;
    }
}
