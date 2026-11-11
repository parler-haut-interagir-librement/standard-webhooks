<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Base64;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\Scheme;
use Phil\StandardWebhooks\Signature;
use Phil\StandardWebhooks\SignatureList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HmacSecret::class)]
final class HmacSecretTest extends TestCase
{
    /**
     * The published `v1` example of the reference libraries: a secret, a message, and the signature every
     * implementation must produce for it.
     */
    public function testTheReferenceVectorSigns(): void
    {
        $secret = HmacSecret::fromString('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');

        $signature = $secret->sign('msg_p5jXN8AQM9LWM0D4loKWxJek.1614265330.{"test": 2432232314}');

        self::assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', $signature->toHeaderEntry());
    }

    public function testAGeneratedSecretIsThirtyTwoBytes(): void
    {
        $encoded = HmacSecret::generate()->toString();

        self::assertStringStartsWith(HmacSecret::PREFIX, $encoded);
        self::assertSame(32, \strlen((string) Base64::decodeSecret(substr($encoded, \strlen(HmacSecret::PREFIX)))));
    }

    public function testItVerifiesItsOwnSignatureAndNothingElse(): void
    {
        $secret = HmacSecret::generate();
        $signature = $secret->sign('content');

        self::assertTrue($secret->verifies('content', $signature));
        self::assertFalse($secret->verifies('content!', $signature));
        self::assertFalse(HmacSecret::generate()->verifies('content', $signature));
        self::assertFalse($secret->verifies('content', Signature::of(Scheme::V1a, str_repeat("\x01", 64))));
    }

    public function testItVerifiesAnyOfAListOfSignatures(): void
    {
        $secret = HmacSecret::generate();
        $signature = $secret->sign('content');
        $forged = Signature::of(Scheme::V1, str_repeat("\x01", 32));

        self::assertTrue($secret->verifiesAny('content', SignatureList::of($forged, $signature)));
        self::assertTrue($secret->verifiesAny('content', SignatureList::of($signature, $forged)));
        self::assertFalse($secret->verifiesAny('content', SignatureList::of($forged, HmacSecret::generate()->sign('content'))));
        self::assertFalse($secret->verifiesAny('content!', SignatureList::of($signature)));
        self::assertFalse($secret->verifiesAny('content', SignatureList::fromHeader('')), 'An empty list verifies nothing.');
        self::assertFalse($secret->verifiesAny('content', SignatureList::of(Signature::of(Scheme::V1a, $signature->bytes.$signature->bytes))));
    }

    public function testTheSecretRoundTrips(): void
    {
        $secret = HmacSecret::generate();

        self::assertSame($secret->toString(), HmacSecret::fromString($secret->toString())->toString());
        self::assertStringStartsWith('whsec_', $secret->toString());
    }

    public function testASecretWithoutItsPrefixIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook secret starts with "whsec_".');

        HmacSecret::fromString(Base64::encodePublic(str_repeat("\x01", 32)));
    }

    public function testASecretThatIsNotBase64IsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        HmacSecret::fromString('whsec_not*base64');
    }

    public function testTheLengthBoundsAreInclusive(): void
    {
        self::assertInstanceOf(HmacSecret::class, HmacSecret::fromString('whsec_'.Base64::encodePublic(str_repeat("\x01", 24))));
        self::assertInstanceOf(HmacSecret::class, HmacSecret::fromString('whsec_'.Base64::encodePublic(str_repeat("\x01", 64))));
    }

    public function testASecretShorterThan24BytesIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook secret is 24 to 64 bytes; this one is 23.');

        HmacSecret::fromString('whsec_'.Base64::encodePublic(str_repeat("\x01", 23)));
    }

    public function testASecretLongerThan64BytesIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        HmacSecret::fromString('whsec_'.Base64::encodePublic(str_repeat("\x01", 65)));
    }

    public function testADumpDoesNotPrintTheSecret(): void
    {
        $secret = HmacSecret::fromString('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');

        self::assertSame(['bytes' => '[redacted]'], $secret->__debugInfo());
    }
}
