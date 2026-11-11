<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Benchmark;

use DateTimeImmutable;
use Generator;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\SignatureList;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\SigningKey;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\VerificationKey;
use Phil\StandardWebhooks\Verifier;
use Phil\StandardWebhooks\WebhookHeaders;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookTimestamp;

/**
 * Every delivery is checked here before its sender is known, so the forged case is work an attacker gets for free:
 * up to eight signatures times every trusted key, each an Ed25519 or HMAC check over the whole body.
 */
final class VerifierBench
{
    private readonly Verifier $verifier;

    private readonly DateTimeImmutable $now;

    private readonly Tolerance $tolerance;

    private readonly string $body;

    /**
     * @var array<string, list<VerificationKey>>
     */
    private array $trusted = [];

    /**
     * @var array<string, WebhookHeaders>
     */
    private array $genuine = [];

    /**
     * @var array<string, WebhookHeaders>
     */
    private array $forged = [];

    public function __construct()
    {
        $this->verifier = new Verifier();
        $this->now = new DateTimeImmutable('@1793448000');
        $this->tolerance = Tolerance::ofSeconds(300);
        $this->body = json_encode(['type' => 'invoice.paid', 'data' => ['lines' => array_fill(0, 12, ['sku' => 'SKU-000123', 'quantity' => 2, 'amount' => '19.99'])]], \JSON_THROW_ON_ERROR);

        $id = WebhookId::fromString('msg_2mF1xQe7Jb4kL9pZ');
        $timestamp = WebhookTimestamp::fromDateTime($this->now);

        foreach (['hmac', 'ed25519'] as $scheme) {
            foreach ([1, 3, 8] as $count) {
                $newest = self::signingKey($scheme);
                $signing = [...array_map(static fn (): SigningKey => self::signingKey($scheme), array_fill(0, $count - 1, null)), $newest];
                $strangers = array_map(static fn (): SigningKey => self::signingKey($scheme), range(1, SignatureList::MAX_ENTRIES));
                $set = $scheme.'/'.$count;

                $this->trusted[$set] = array_map(static fn (SigningKey $key): VerificationKey => $key instanceof Ed25519SecretKey ? $key->publicKey() : self::hmac($key), $signing);
                // A rotation: the sender signs with the newest key only, which the receiver trusts last.
                $this->genuine[$set] = $this->received(new Signer()->sign($id, $timestamp, $this->body, $newest));
                $this->forged[$set] = $this->received(new Signer()->sign($id, $timestamp, $this->body, ...$strangers));
            }
        }
    }

    /**
     * @ParamProviders("keySets")
     *
     * @param array{set: string} $params
     */
    public function benchGenuineSignedByLastKey(array $params): void
    {
        $this->verifier->verify($this->genuine[$params['set']], $this->body, $this->trusted[$params['set']], $this->tolerance, $this->now);
    }

    /**
     * @ParamProviders("keySets")
     *
     * @param array{set: string} $params
     */
    public function benchForgedEightSignatures(array $params): void
    {
        $this->verifier->signatureVerifies($this->forged[$params['set']], $this->body, $this->trusted[$params['set']]);
    }

    /**
     * @return Generator<string, array{set: string}>
     */
    public function keySets(): Generator
    {
        foreach (['hmac', 'ed25519'] as $scheme) {
            foreach ([1, 3, 8] as $count) {
                yield \sprintf('%s, %d keys', $scheme, $count) => ['set' => $scheme.'/'.$count];
            }
        }
    }

    private static function signingKey(string $scheme): SigningKey
    {
        return 'hmac' === $scheme ? HmacSecret::generate() : Ed25519SecretKey::generate();
    }

    private static function hmac(SigningKey $key): VerificationKey
    {
        return $key instanceof HmacSecret ? $key : Ed25519SecretKey::generate()->publicKey();
    }

    /**
     * What the receiver sees: the headers as strings, parsed again.
     */
    private function received(WebhookHeaders $sent): WebhookHeaders
    {
        $headers = $sent->toArray();

        return WebhookHeaders::fromStrings($headers[WebhookHeaders::ID], $headers[WebhookHeaders::TIMESTAMP], $headers[WebhookHeaders::SIGNATURE]);
    }
}
