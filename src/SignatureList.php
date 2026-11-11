<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;

/**
 * The `webhook-signature` header: a space-delimited list of signatures, so that two keys overlap during a
 * rotation and the receiver accepts the delivery if any one of them verifies.
 *
 * **The number of entries is capped.** Every entry is a verification the receiver performs before it
 * knows whether the sender is genuine, so an unbounded list is work an attacker makes it do for free.
 */
final readonly class SignatureList
{
    public const int MAX_ENTRIES = 8;

    /**
     * @param list<Signature> $signatures
     */
    private function __construct(
        public array $signatures,
    ) {
    }

    public static function of(Signature ...$signatures): self
    {
        if ([] === $signatures) {
            throw InvalidWebhookValue::because('A webhook signature header carries at least one signature.');
        }

        if (\count($signatures) > self::MAX_ENTRIES) {
            throw InvalidWebhookValue::because(\sprintf('A webhook signature header carries at most %d signatures; this one has %d.', self::MAX_ENTRIES, \count($signatures)));
        }

        return new self(array_values($signatures));
    }

    /**
     * Unreadable entries are dropped (see `Signature::tryFromHeaderEntry()`), so the list may be empty: a
     * header with nothing the receiver can verify is a delivery that fails verification, not a malformed one.
     */
    public static function fromHeader(string $header): self
    {
        $entries = array_filter(explode(' ', $header), static fn (string $entry): bool => '' !== $entry);

        if (\count($entries) > self::MAX_ENTRIES) {
            throw InvalidWebhookValue::because(\sprintf('A webhook signature header carries at most %d signatures; this one has %d.', self::MAX_ENTRIES, \count($entries)));
        }

        $signatures = [];

        foreach ($entries as $entry) {
            $signature = Signature::tryFromHeaderEntry($entry);

            if ($signature instanceof Signature) {
                $signatures[] = $signature;
            }
        }

        return new self($signatures);
    }

    public function toHeader(): string
    {
        return implode(' ', array_map(static fn (Signature $signature): string => $signature->toHeaderEntry(), $this->signatures));
    }
}
