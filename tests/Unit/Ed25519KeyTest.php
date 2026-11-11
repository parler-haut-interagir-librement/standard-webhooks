<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Base64;
use Phil\StandardWebhooks\Ed25519PublicKey;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Scheme;
use Phil\StandardWebhooks\Signature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ed25519PublicKey::class)]
#[CoversClass(Ed25519SecretKey::class)]
final class Ed25519KeyTest extends TestCase
{
    /**
     * RFC 8032, section 7.1, test 1: the empty message under a fixed seed.
     */
    public function testTheRfc8032VectorSigns(): void
    {
        $secret = Ed25519SecretKey::fromBytes((string) hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60'));

        self::assertSame('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a', bin2hex($secret->publicKey()->bytes));
        self::assertSame(
            'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b',
            bin2hex($secret->sign('')->bytes),
        );
    }

    public function testThePublicKeyVerifiesTheSecretKeysSignatureAndNothingElse(): void
    {
        $secret = Ed25519SecretKey::generate();
        $public = $secret->publicKey();
        $signature = $secret->sign('content');

        self::assertSame(Scheme::V1a, $signature->scheme);
        self::assertTrue($public->verifies('content', $signature));
        self::assertFalse($public->verifies('content!', $signature));
        self::assertFalse(Ed25519SecretKey::generate()->publicKey()->verifies('content', $signature));
        self::assertFalse($public->verifies('content', Signature::of(Scheme::V1, str_repeat("\x01", 32))));
    }

    public function testTheKeysRoundTripThroughTheirPrefixedForm(): void
    {
        $secret = Ed25519SecretKey::generate();

        self::assertStringStartsWith('whsk_', $secret->toString());
        self::assertStringStartsWith('whpk_', $secret->publicKey()->toString());
        self::assertSame($secret->toString(), Ed25519SecretKey::fromString($secret->toString())->toString());
        self::assertTrue($secret->publicKey()->equals(Ed25519PublicKey::fromString($secret->publicKey()->toString())));
    }

    public function testASeedAndItsFullSecretKeyAreTheSameKey(): void
    {
        $seed = str_repeat("\x07", 32);
        $fromSeed = Ed25519SecretKey::fromBytes($seed);

        $full = Base64::decodeSecret(substr($fromSeed->toString(), 5)) ?? '';

        self::assertSame(64, \strlen($full));
        self::assertSame($fromSeed->toString(), Ed25519SecretKey::fromBytes($full)->toString());
    }

    public function testAFullSecretKeyWhosePublicHalfDoesNotMatchItsSeedIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('The public half of this webhook secret key does not match its seed.');

        Ed25519SecretKey::fromBytes(str_repeat("\x07", 32).str_repeat("\x00", 32));
    }

    public function testASecretKeyOfAnotherLengthIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook secret key is a 32-byte seed or a 64-byte secret key; this one is 33 bytes.');

        Ed25519SecretKey::fromBytes(str_repeat("\x07", 33));
    }

    public function testASecretKeyWithoutItsPrefixIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        Ed25519SecretKey::fromString('whpk_'.Base64::encodePublic(str_repeat("\x07", 32)));
    }

    public function testAPublicKeyWithoutItsPrefixIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook public key starts with "whpk_".');

        Ed25519PublicKey::fromString(Base64::encodePublic(str_repeat("\x07", 32)));
    }

    public function testAPublicKeyThatIsNotBase64IsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        Ed25519PublicKey::fromString('whpk_not*base64');
    }

    public function testAPublicKeyOfAnotherLengthIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook public key is 32 bytes; this one is 31.');

        Ed25519PublicKey::fromBytes(str_repeat("\x07", 31));
    }

    public function testADumpDoesNotPrintTheSecretKey(): void
    {
        self::assertSame(['secretKey' => '[redacted]'], Ed25519SecretKey::generate()->__debugInfo());
    }
}
