<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use DateTimeImmutable;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Exception\RejectionReason;
use Phil\StandardWebhooks\Exception\WebhookException;
use Phil\StandardWebhooks\Exception\WebhookRejected;

/**
 * Verifies a received delivery: the timestamp, then the signature under the cached keys, then — **once** —
 * under freshly reloaded keys before refusing, so that a sender's key rotation is picked up without a
 * redeploy. When an audience is configured, the envelope must name it.
 *
 * Deduplication and processing come after, and belong to the caller (see `ProcessedWebhookRepository`).
 */
final readonly class WebhookReceiver
{
    public function __construct(
        private VerificationKeyProvider $keys,
        private Tolerance $tolerance,
        private ?string $audience = null,
        private Verifier $verifier = new Verifier(),
    ) {
        if ('' === $audience) {
            throw InvalidWebhookValue::because('An expected webhook audience cannot be empty; pass null to accept any.');
        }
    }

    /**
     * @throws WebhookException
     */
    public function receive(WebhookHeaders $headers, string $rawBody, DateTimeImmutable $now): VerifiedWebhook
    {
        try {
            $verified = $this->verifier->verify($headers, $rawBody, $this->keys->keys(), $this->tolerance, $now);
        } catch (WebhookRejected $webhookRejected) {
            // A stale timestamp does not get fresher with new keys: only a signature failure reloads.
            if (RejectionReason::NoValidSignature !== $webhookRejected->reason) {
                throw $webhookRejected;
            }

            $verified = $this->verifier->verify($headers, $rawBody, $this->keys->refresh(), $this->tolerance, $now);
        }

        if (null !== $this->audience) {
            $verified->envelope()->assertAddressedTo($this->audience);
        }

        return $verified;
    }
}
