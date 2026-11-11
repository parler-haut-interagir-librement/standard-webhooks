<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * A key a receiver trusts. It verifies only signatures of its own scheme; any other is `false`.
 */
interface VerificationKey
{
    public function scheme(): Scheme;

    public function verifies(string $signedContent, Signature $signature): bool;
}
