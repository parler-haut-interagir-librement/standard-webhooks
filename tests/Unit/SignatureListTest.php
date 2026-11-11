<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Base64;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Scheme;
use Phil\StandardWebhooks\Signature;
use Phil\StandardWebhooks\SignatureList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignatureList::class)]
#[CoversClass(Signature::class)]
final class SignatureListTest extends TestCase
{
    public function testBothSchemesAreReadFromOneHeader(): void
    {
        $v1 = Base64::encodePublic(str_repeat("\x01", 32));
        $v1a = Base64::encodePublic(str_repeat("\x02", 64));

        $list = SignatureList::fromHeader('v1,'.$v1.' v1a,'.$v1a);

        self::assertCount(2, $list->signatures);
        self::assertSame(Scheme::V1, $list->signatures[0]->scheme);
        self::assertSame(Scheme::V1a, $list->signatures[1]->scheme);
        self::assertSame('v1,'.$v1.' v1a,'.$v1a, $list->toHeader());
    }

    /**
     * An unknown version is ignored, never accepted, and does not make the header malformed: a sender may
     * add a scheme before every receiver knows it.
     */
    public function testUnreadableEntriesAreDropped(): void
    {
        $valid = 'v1,'.Base64::encodePublic(str_repeat("\x01", 32));

        $list = SignatureList::fromHeader(implode(' ', [
            'v2,'.Base64::encodePublic(str_repeat("\x01", 32)),
            'v1'.Base64::encodePublic(str_repeat("\x01", 32)),
            'v1,not*base64',
            'v1,'.Base64::encodePublic(str_repeat("\x01", 31)),
            'v1a,'.Base64::encodePublic(str_repeat("\x01", 32)),
            $valid,
        ]));

        self::assertSame($valid, $list->toHeader());
    }

    public function testRepeatedSpacesDoNotCountAsEntries(): void
    {
        $valid = 'v1,'.Base64::encodePublic(str_repeat("\x01", 32));

        self::assertCount(1, SignatureList::fromHeader('  '.$valid.'   ')->signatures);
    }

    public function testAHeaderOfEightEntriesIsRead(): void
    {
        $valid = 'v1,'.Base64::encodePublic(str_repeat("\x01", 32));

        self::assertCount(8, SignatureList::fromHeader(implode('  ', array_fill(0, 8, $valid)).' ')->signatures);
    }

    public function testAListOfEightSignaturesIsBuilt(): void
    {
        self::assertCount(8, SignatureList::of(...array_fill(0, 8, Signature::of(Scheme::V1, str_repeat("\x01", 32))))->signatures);
    }

    /**
     * Signatures spread from a keyed array still form a list.
     */
    public function testABuiltListIsAList(): void
    {
        $signature = Signature::of(Scheme::V1, str_repeat("\x01", 32));

        self::assertSame([$signature], SignatureList::of(...['first' => $signature])->signatures);
    }

    public function testASignatureOfTheRightLengthIsKept(): void
    {
        $signature = Signature::of(Scheme::V1, str_repeat("\x02", 32));

        self::assertSame(Scheme::V1, $signature->scheme);
        self::assertSame(str_repeat("\x02", 32), $signature->bytes);
    }

    public function testAnEntryWithASecondCommaIsUnreadable(): void
    {
        self::assertNull(Signature::tryFromHeaderEntry('v1,'.Base64::encodePublic(str_repeat("\x01", 32)).',x'));
    }

    public function testAHeaderWithNothingReadableIsAnEmptyList(): void
    {
        self::assertSame([], SignatureList::fromHeader('v9,abc')->signatures);
    }

    public function testAHeaderWithTooManyEntriesIsRefusedBeforeAnyIsRead(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook signature header carries at most 8 signatures; this one has 9.');

        SignatureList::fromHeader(implode(' ', array_fill(0, 9, 'v1,x')));
    }

    public function testABuiltListCarriesAtLeastOneSignature(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        SignatureList::of();
    }

    public function testABuiltListRespectsTheCap(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        SignatureList::of(...array_fill(0, 9, Signature::of(Scheme::V1, str_repeat("\x01", 32))));
    }

    public function testASignatureOfTheWrongLengthIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A v1a signature is 64 bytes; this one is 32.');

        Signature::of(Scheme::V1a, str_repeat("\x01", 32));
    }
}
