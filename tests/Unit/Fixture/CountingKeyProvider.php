<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit\Fixture;

use Override;
use Phil\StandardWebhooks\VerificationKey;
use Phil\StandardWebhooks\VerificationKeyProvider;

/**
 * A key provider that counts its reloads, so a test can prove the receiver reloads at most once.
 */
final class CountingKeyProvider implements VerificationKeyProvider
{
    public int $refreshes = 0;

    /**
     * @param list<VerificationKey> $cached
     * @param list<VerificationKey> $reloaded
     */
    public function __construct(
        private readonly array $cached,
        private readonly array $reloaded,
    ) {
    }

    #[Override]
    public function keys(): array
    {
        return $this->cached;
    }

    #[Override]
    public function refresh(): array
    {
        ++$this->refreshes;

        return $this->reloaded;
    }
}
