<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use DateTimeImmutable;
use Phil\StandardWebhooks\Exception\WebhookRejected;

/**
 * Verifies one received delivery against a set of trusted keys.
 *
 * **The timestamp is checked first**: it costs nothing, and the signature check is the work an attacker
 * makes the receiver do for free. The signature header carries no key id, so a delivery is accepted when
 * any of its signatures verifies under any trusted key of the same scheme; a signature of a scheme no
 * trusted key has is ignored, never accepted.
 */
final readonly class Verifier
{
    /**
     * @param iterable<VerificationKey> $keys
     *
     * @throws WebhookRejected
     */
    public function verify(WebhookHeaders $headers, string $rawBody, iterable $keys, Tolerance $tolerance, DateTimeImmutable $now): VerifiedWebhook
    {
        if (!$headers->timestamp->isWithin($tolerance, $now)) {
            throw WebhookRejected::timestampOutsideTolerance($headers->timestamp->skewFrom($now), $tolerance->seconds);
        }

        if (!$this->signatureVerifies($headers, $rawBody, $keys)) {
            throw WebhookRejected::noValidSignature();
        }

        return new VerifiedWebhook($headers->id, $headers->timestamp, $rawBody);
    }

    /**
     * @param iterable<VerificationKey> $keys
     */
    public function signatureVerifies(WebhookHeaders $headers, string $rawBody, iterable $keys): bool
    {
        $content = SignedContent::of($headers->id, $headers->timestamp, $rawBody);

        foreach ($keys as $key) {
            // An HMAC depends on the key and the content only: computed once per key, not once per signature.
            if ($key instanceof HmacSecret) {
                if ($key->verifiesAny($content, $headers->signatures)) {
                    return true;
                }

                continue;
            }

            foreach ($headers->signatures->signatures as $signature) {
                if ($key->scheme() === $signature->scheme && $key->verifies($content, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
