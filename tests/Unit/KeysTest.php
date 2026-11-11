<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Ed25519PublicKey;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\FixedKeys;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\Keys;
use Phil\StandardWebhooks\WebhookId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;

#[CoversClass(Keys::class)]
#[CoversClass(FixedKeys::class)]
#[CoversClass(WebhookId::class)]
final class KeysTest extends TestCase
{
    public function testAKeyIsReadByItsPrefix(): void
    {
        $secret = Ed25519SecretKey::generate();
        $hmac = HmacSecret::generate();

        self::assertInstanceOf(Ed25519SecretKey::class, Keys::signing($secret->toString()));
        self::assertSame($hmac->toString(), $this->hmac(Keys::signing($hmac->toString()))->toString());
        self::assertTrue($secret->publicKey()->equals($this->publicKey(Keys::verification($secret->publicKey()->toString()))));
        self::assertSame($hmac->toString(), $this->hmac(Keys::verification($hmac->toString()))->toString());
    }

    public function testAnUnknownPrefixIsRefused(): void
    {
        foreach ([[Keys::signing(...), 'whpk_abc', 'A signing key starts with "whsk_" or "whsec_".'], [Keys::verification(...), 'whsk_abc', 'A verification key starts with "whpk_" or "whsec_".']] as [$read, $encoded, $message]) {
            try {
                $read($encoded);
                self::fail($encoded.' was read.');
            } catch (InvalidWebhookValue $invalidWebhookValue) {
                self::assertSame($message, $invalidWebhookValue->getMessage());
            }
        }
    }

    public function testFixedKeysGiveThemselvesBackOnRefresh(): void
    {
        $hmac = HmacSecret::generate();
        $public = Ed25519SecretKey::generate()->publicKey();
        $keys = FixedKeys::fromStrings([$hmac->toString(), $public->toString()]);

        self::assertCount(2, $keys->keys());
        self::assertSame([0, 1], array_keys($keys->refresh()));
        self::assertEquals($keys->keys(), $keys->refresh());
        self::assertSame([$hmac], new FixedKeys(...['a' => $hmac])->keys());
        self::assertSame([], new FixedKeys()->keys());
    }

    /**
     * The shared secrets among the keys stay out of a stack trace, as they do for every other reader of a key.
     */
    public function testFixedKeysHideTheirStringsFromAStackTrace(): void
    {
        $secret = HmacSecret::generate()->toString();

        try {
            FixedKeys::fromStrings([$secret, 'whsk_abc']);
            self::fail('A signing key was read as a verification key.');
        } catch (InvalidWebhookValue $invalidWebhookValue) {
            $frame = array_find($invalidWebhookValue->getTrace(), static fn (array $frame): bool => FixedKeys::class === ($frame['class'] ?? null) && 'fromStrings' === $frame['function']);

            self::assertIsArray($frame);
            self::assertInstanceOf(SensitiveParameterValue::class, $frame['args'][0] ?? null);
            $trace = $invalidWebhookValue->getTrace();
            $below = \array_slice($trace, 0, array_find_key($trace, static fn (array $frame): bool => self::class === ($frame['class'] ?? null)));

            self::assertNotSame([], $below);
            self::assertStringNotContainsString($secret, print_r(array_column($below, 'args'), true));
        }
    }

    public function testANewIdIsRandom(): void
    {
        $id = WebhookId::generate()->value;

        self::assertMatchesRegularExpression('/^msg_[A-Za-z0-9_-]{24}$/D', $id);
        self::assertNotSame($id, WebhookId::generate()->value);
        self::assertSame($id, WebhookId::fromString($id)->value);
    }

    private function hmac(object $key): HmacSecret
    {
        self::assertInstanceOf(HmacSecret::class, $key);

        return $key;
    }

    private function publicKey(object $key): Ed25519PublicKey
    {
        self::assertInstanceOf(Ed25519PublicKey::class, $key);

        return $key;
    }
}
