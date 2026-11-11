<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Base64;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Base64::class)]
final class Base64Test extends TestCase
{
    public function testBothCodecsEncodeTheSameAlphabetWithPadding(): void
    {
        self::assertSame('aGk=', Base64::encodePublic('hi'));
        self::assertSame('aGk=', Base64::encodeSecret('hi'));
    }

    public function testBothCodecsRoundTrip(): void
    {
        $bytes = random_bytes(32);

        self::assertSame($bytes, Base64::decodePublic(Base64::encodePublic($bytes)));
        self::assertSame($bytes, Base64::decodeSecret(Base64::encodeSecret($bytes)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'missing padding' => ['aGk'];
        yield 'character outside the alphabet' => ['aG!='];
        yield 'url-safe alphabet' => ['-_8='];
        yield 'non-canonical trailing bits' => ['aGl='];
        yield 'whitespace' => ['aG k='];
    }

    /**
     * The public decoder is as strict as the constant-time one: an input either refuses, it refuses for both.
     */
    #[DataProvider('unreadable')]
    public function testBothDecodersRefuseTheSameInput(string $encoded): void
    {
        self::assertNull(Base64::decodePublic($encoded));
        self::assertNull(Base64::decodeSecret($encoded));
    }
}
