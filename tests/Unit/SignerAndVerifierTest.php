<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use DateTimeImmutable;
use Phil\StandardWebhooks\Base64;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\Exception\RejectionReason;
use Phil\StandardWebhooks\Exception\WebhookRejected;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\SignedContent;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\Verifier;
use Phil\StandardWebhooks\WebhookHeaders;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookTimestamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Signer::class)]
#[CoversClass(Verifier::class)]
#[CoversClass(SignedContent::class)]
final class SignerAndVerifierTest extends TestCase
{
    private const string BODY = '{"type":"invoice.paid","data":{"id":"abc"}}';

    public function testTheSignedContentIsIdTimestampBody(): void
    {
        self::assertSame(
            'msg_1.1614265330.{"a":1}',
            SignedContent::of(WebhookId::fromString('msg_1'), WebhookTimestamp::fromHeader('1614265330'), '{"a":1}'),
        );
    }

    public function testAV1aDeliveryVerifiesUnderThePublicKey(): void
    {
        $key = Ed25519SecretKey::generate();
        $headers = $this->signed($key);

        $verified = new Verifier()->verify($this->received($headers), self::BODY, [$key->publicKey()], Tolerance::ofSeconds(300), $this->now());

        self::assertSame('msg_1', $verified->id->value);
        self::assertSame(self::BODY, $verified->rawBody);
    }

    public function testAV1DeliveryVerifiesUnderTheSharedSecret(): void
    {
        $secret = HmacSecret::generate();

        $verified = new Verifier()->verify($this->received($this->signed($secret)), self::BODY, [$secret], Tolerance::ofSeconds(300), $this->now());

        self::assertSame(self::BODY, $verified->rawBody);
    }

    /**
     * Two overlapping keys during a rotation: the header carries both signatures, and a receiver that
     * trusts only either one of them accepts the delivery.
     */
    public function testDuringARotationEitherKeyVerifies(): void
    {
        $old = Ed25519SecretKey::generate();
        $new = Ed25519SecretKey::generate();
        $headers = $this->received(new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($this->now()), self::BODY, $new, $old));

        self::assertCount(2, $headers->signatures->signatures);
        self::assertTrue(new Verifier()->signatureVerifies($headers, self::BODY, [$old->publicKey()]));
        self::assertTrue(new Verifier()->signatureVerifies($headers, self::BODY, [$new->publicKey()]));
        self::assertFalse(new Verifier()->signatureVerifies($headers, self::BODY, [Ed25519SecretKey::generate()->publicKey()]));
    }

    /**
     * Each HMAC key is tried against the whole header in turn: the second trusted secret, the one that signed, still
     * verifies, and a header signed by both an Ed25519 key and a secret verifies under either.
     */
    public function testEveryHmacKeyIsTriedAgainstEverySignature(): void
    {
        $secret = HmacSecret::generate();
        $key = Ed25519SecretKey::generate();
        $headers = $this->received(new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($this->now()), self::BODY, $key, $secret));

        self::assertTrue(new Verifier()->signatureVerifies($headers, self::BODY, [HmacSecret::generate(), $secret]));
        self::assertTrue(new Verifier()->signatureVerifies($headers, self::BODY, [HmacSecret::generate(), $key->publicKey()]));
        self::assertFalse(new Verifier()->signatureVerifies($headers, self::BODY, [HmacSecret::generate(), Ed25519SecretKey::generate()->publicKey()]));
    }

    /**
     * The raw body, byte for byte: a re-encoded body with the same meaning is another message.
     */
    public function testABodyChangedByOneByteDoesNotVerify(): void
    {
        $key = Ed25519SecretKey::generate();
        $headers = $this->received($this->signed($key));

        self::assertFalse(new Verifier()->signatureVerifies($headers, self::BODY.' ', [$key->publicKey()]));
        self::assertFalse(new Verifier()->signatureVerifies($headers, '{"type": "invoice.paid","data":{"id":"abc"}}', [$key->publicKey()]));
    }

    public function testAnotherIdOrTimestampDoesNotVerify(): void
    {
        $key = Ed25519SecretKey::generate();
        $signature = $this->signed($key)->toArray()['webhook-signature'];
        $verifier = new Verifier();

        self::assertFalse($verifier->signatureVerifies(WebhookHeaders::fromStrings('msg_2', (string) $this->now()->getTimestamp(), $signature), self::BODY, [$key->publicKey()]));
        self::assertFalse($verifier->signatureVerifies(WebhookHeaders::fromStrings('msg_1', (string) ($this->now()->getTimestamp() + 1), $signature), self::BODY, [$key->publicKey()]));
    }

    /**
     * A `v1` signature computed with the public key's bytes as an HMAC secret must not pass as `v1a`: a
     * key verifies only its own scheme.
     */
    public function testASignatureOfAnotherSchemeIsNeverAccepted(): void
    {
        $key = Ed25519SecretKey::generate();
        $secret = HmacSecret::fromString('whsec_'.Base64::encodePublic($key->publicKey()->bytes));
        $headers = $this->received($this->signed($secret));

        self::assertFalse(new Verifier()->signatureVerifies($headers, self::BODY, [$key->publicKey()]));
    }

    public function testNoTrustedKeyMeansNoValidSignature(): void
    {
        $headers = $this->received($this->signed(Ed25519SecretKey::generate()));

        try {
            new Verifier()->verify($headers, self::BODY, [], Tolerance::ofSeconds(300), $this->now());
            self::fail('The delivery was accepted.');
        } catch (WebhookRejected $webhookRejected) {
            self::assertSame(RejectionReason::NoValidSignature, $webhookRejected->reason);
        }
    }

    /**
     * The timestamp is checked before the signature, so a stale delivery costs no signature check, and a
     * genuine signature does not rescue it: that is the replay the tolerance exists to stop.
     */
    public function testAGenuineButStaleDeliveryIsRejectedForItsTimestamp(): void
    {
        $key = Ed25519SecretKey::generate();
        $headers = $this->received($this->signed($key));

        try {
            new Verifier()->verify($headers, self::BODY, [$key->publicKey()], Tolerance::ofSeconds(300), $this->now()->modify('+301 seconds'));
            self::fail('The delivery was accepted.');
        } catch (WebhookRejected $webhookRejected) {
            self::assertSame(RejectionReason::TimestampOutsideTolerance, $webhookRejected->reason);
            self::assertSame('The webhook timestamp is 301 seconds from the receiver clock; the tolerance is 300.', $webhookRejected->getMessage());
        }
    }

    private function signed(HmacSecret|Ed25519SecretKey $key): WebhookHeaders
    {
        return new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($this->now()), self::BODY, $key);
    }

    /**
     * What the receiver sees: the headers as strings, parsed again.
     */
    private function received(WebhookHeaders $sent): WebhookHeaders
    {
        $headers = $sent->toArray();

        return WebhookHeaders::fromStrings($headers['webhook-id'], $headers['webhook-timestamp'], $headers['webhook-signature']);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1793448000');
    }
}
