<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * Signs one attempt of a delivery.
 *
 * Several keys yield several signatures in one header, which is how a rotation overlaps. **Every attempt of
 * a delivery reuses its `webhook-id`**, so the receiver's deduplication absorbs retries; only the timestamp
 * and the signatures are recomputed.
 */
final readonly class Signer
{
    public function sign(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody, SigningKey $key, SigningKey ...$moreKeys): WebhookHeaders
    {
        $content = SignedContent::of($id, $timestamp, $rawBody);
        $signatures = array_map(static fn (SigningKey $signingKey): Signature => $signingKey->sign($content), [$key, ...$moreKeys]);

        return new WebhookHeaders($id, $timestamp, SignatureList::of(...$signatures));
    }
}
