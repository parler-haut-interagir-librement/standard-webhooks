<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use DateTimeImmutable;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Exception\RejectionReason;
use Phil\StandardWebhooks\Exception\WebhookRejected;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\Tests\Unit\Fixture\CountingKeyProvider;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\VerificationKey;
use Phil\StandardWebhooks\WebhookHeaders;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookReceiver;
use Phil\StandardWebhooks\WebhookTimestamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookReceiver::class)]
final class WebhookReceiverTest extends TestCase
{
    private const string BODY = '{"type":"invoice.paid","data":{"id":"abc"},"audience":"receiver-1"}';

    public function testACachedKeyVerifiesWithoutAReload(): void
    {
        $key = Ed25519SecretKey::generate();
        $provider = $this->provider([$key->publicKey()], []);

        $verified = new WebhookReceiver($provider, Tolerance::ofSeconds(300))->receive($this->signed($key), self::BODY, $this->now());

        self::assertSame('msg_1', $verified->id->value);
        self::assertSame(0, $provider->refreshes);
    }

    /**
     * The sender rotated its key after the receiver cached the old list: one reload, then acceptance.
     */
    public function testAnUnknownKeyTriggersExactlyOneReload(): void
    {
        $rotated = Ed25519SecretKey::generate();
        $provider = $this->provider([Ed25519SecretKey::generate()->publicKey()], [$rotated->publicKey()]);

        new WebhookReceiver($provider, Tolerance::ofSeconds(300))->receive($this->signed($rotated), self::BODY, $this->now());

        self::assertSame(1, $provider->refreshes);
    }

    public function testAForgedDeliveryIsRejectedAfterOneReload(): void
    {
        $provider = $this->provider([Ed25519SecretKey::generate()->publicKey()], [Ed25519SecretKey::generate()->publicKey()]);

        try {
            new WebhookReceiver($provider, Tolerance::ofSeconds(300))->receive($this->signed(Ed25519SecretKey::generate()), self::BODY, $this->now());
            self::fail('The delivery was accepted.');
        } catch (WebhookRejected $webhookRejected) {
            self::assertSame(RejectionReason::NoValidSignature, $webhookRejected->reason);
        }

        self::assertSame(1, $provider->refreshes);
    }

    public function testAStaleDeliveryNeverTriggersAReload(): void
    {
        $key = Ed25519SecretKey::generate();
        $provider = $this->provider([], [$key->publicKey()]);

        try {
            new WebhookReceiver($provider, Tolerance::ofSeconds(300))->receive($this->signed($key), self::BODY, $this->now()->modify('+1 hour'));
            self::fail('The delivery was accepted.');
        } catch (WebhookRejected $webhookRejected) {
            self::assertSame(RejectionReason::TimestampOutsideTolerance, $webhookRejected->reason);
        }

        self::assertSame(0, $provider->refreshes);
    }

    public function testAnEnvelopeAddressedToThisReceiverIsAccepted(): void
    {
        $key = Ed25519SecretKey::generate();

        $verified = new WebhookReceiver($this->provider([$key->publicKey()], []), Tolerance::ofSeconds(300), 'receiver-1')
            ->receive($this->signed($key), self::BODY, $this->now());

        self::assertSame('receiver-1', $verified->envelope()->audience);
    }

    /**
     * One key signs for every receiver, so a genuine delivery for one receiver verifies at another: only
     * the audience under the signature tells them apart.
     */
    public function testAGenuineDeliveryForAnotherReceiverIsRejected(): void
    {
        $key = Ed25519SecretKey::generate();

        try {
            new WebhookReceiver($this->provider([$key->publicKey()], []), Tolerance::ofSeconds(300), 'receiver-2')
                ->receive($this->signed($key), self::BODY, $this->now());
            self::fail('The delivery was accepted.');
        } catch (WebhookRejected $webhookRejected) {
            self::assertSame(RejectionReason::AudienceMismatch, $webhookRejected->reason);
        }
    }

    public function testAnEmptyExpectedAudienceIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        new WebhookReceiver($this->provider([], []), Tolerance::ofSeconds(300), '');
    }

    private function signed(Ed25519SecretKey $key): WebhookHeaders
    {
        $sent = new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($this->now()), self::BODY, $key)->toArray();

        return WebhookHeaders::fromStrings($sent['webhook-id'], $sent['webhook-timestamp'], $sent['webhook-signature']);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1793448000');
    }

    /**
     * @param list<VerificationKey> $cached
     * @param list<VerificationKey> $reloaded
     */
    private function provider(array $cached, array $reloaded): CountingKeyProvider
    {
        return new CountingKeyProvider($cached, $reloaded);
    }
}
