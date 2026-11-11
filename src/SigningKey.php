<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

/**
 * A key a sender signs with.
 */
interface SigningKey
{
    public function scheme(): Scheme;

    public function sign(string $signedContent): Signature;
}
