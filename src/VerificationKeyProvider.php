<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * Where a receiver's trusted keys come from: a published key document, a configured secret, or both.
 *
 * The adapter belongs to the application, with its cache lifetime and its source. `refresh()` is called
 * at most once per delivery, when no cached key verifies; the adapter bounds how often it actually
 * reloads, so that a stream of forged deliveries cannot turn into a stream of fetches.
 */
interface VerificationKeyProvider
{
    /**
     * @return list<VerificationKey>
     */
    public function keys(): array;

    /**
     * Reloads the keys from their source, or returns the cached ones if the adapter's reload budget is
     * spent.
     *
     * @return list<VerificationKey>
     */
    public function refresh(): array;
}
