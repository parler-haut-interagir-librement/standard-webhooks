<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use SensitiveParameter;

/**
 * Keys known in advance — configured secrets, public keys written down —: nothing to reload, so a refresh gives
 * them back.
 */
final readonly class FixedKeys implements VerificationKeyProvider
{
    /** @var list<VerificationKey> */
    private array $keys;

    public function __construct(VerificationKey ...$keys)
    {
        $this->keys = array_values($keys);
    }

    /**
     * The keys are read one by one, never handed to another function whole, so that no stack trace shows them.
     *
     * @param list<string> $encoded `whpk_` and `whsec_` keys
     */
    public static function fromStrings(#[SensitiveParameter] array $encoded): self
    {
        $keys = [];

        foreach ($encoded as $key) {
            $keys[] = Keys::verification($key);
        }

        return new self(...$keys);
    }

    public function keys(): array
    {
        return $this->keys;
    }

    public function refresh(): array
    {
        return $this->keys;
    }
}
