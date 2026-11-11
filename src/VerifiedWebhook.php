<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * A delivery whose signature and timestamp verified. Only this, never the raw request, is handed to the
 * code that processes the event.
 */
final readonly class VerifiedWebhook
{
    public function __construct(
        public WebhookId $id,
        public WebhookTimestamp $timestamp,
        public string $rawBody,
    ) {
    }

    public function envelope(): Envelope
    {
        return Envelope::fromJson($this->rawBody);
    }
}
