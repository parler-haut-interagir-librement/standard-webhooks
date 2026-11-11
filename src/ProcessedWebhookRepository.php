<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use DateTimeImmutable;

/**
 * The `webhook-id`s a receiver has already processed, so that a retried delivery is acknowledged without
 * being processed twice.
 *
 * **Claim the id in the same transaction as the processing, and answer `2xx` only after it commits.** An
 * id recorded in a transaction of its own, before processing, turns a crash into a lost event; an
 * acknowledgement sent before processing turns it into one the sender will never retry.
 *
 * **The claim is atomic.** Two attempts of the same delivery can arrive together: a sender retries after a
 * timeout while the first attempt is still being processed. A separate "has it been seen?" read lets both
 * through, so the port offers none. `claim()` is an insert-if-absent on a unique index on the id: the second
 * attempt waits on the first transaction's row, and gets `false` once it commits — or claims the id itself if
 * the first one rolled back.
 *
 * The adapter and its retention window belong to the application. The window must outlast both the
 * timestamp tolerance and the sender's whole retry schedule, since every attempt carries the same id: a
 * shorter window processes a retried delivery twice.
 */
interface ProcessedWebhookRepository
{
    /**
     * Records the id as processed, atomically, within the caller's processing transaction.
     *
     * @return bool `true` if this attempt claimed the id and must process the delivery; `false` if the id is
     *              already processed, and the delivery is acknowledged without being processed again
     */
    public function claim(WebhookId $id, DateTimeImmutable $processedAt): bool;
}
