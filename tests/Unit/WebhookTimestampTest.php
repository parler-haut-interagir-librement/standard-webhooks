<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use DateTimeImmutable;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\WebhookTimestamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookTimestamp::class)]
final class WebhookTimestampTest extends TestCase
{
    public function testAHeaderIsReadAsUnixSeconds(): void
    {
        $timestamp = WebhookTimestamp::fromHeader('1614265330');

        self::assertSame(1614265330, $timestamp->seconds);
        self::assertSame('1614265330', $timestamp->toHeader());
    }

    public function testZeroIsAccepted(): void
    {
        self::assertSame(0, WebhookTimestamp::fromHeader('0')->seconds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'signed' => ['+1614265330'];
        yield 'a leading zero' => ['01614265330'];
        yield 'a fraction' => ['1614265330.5'];
        yield 'surrounding whitespace' => [' 1614265330'];
        yield 'a trailing line break' => ["1614265330\n"];
        yield 'milliseconds past the bound' => ['1614265330000'];
        yield 'an ISO 8601 date' => ['2021-02-25T15:02:10Z'];
    }

    #[DataProvider('refusedHeaders')]
    public function testAHeaderThatIsNotStrictUnixSecondsIsRefused(string $header): void
    {
        $this->expectException(InvalidWebhookValue::class);

        WebhookTimestamp::fromHeader($header);
    }

    public function testTheRefusedHeaderIsShownCutAndEscaped(): void
    {
        foreach ([
            '2021-02-25T15:02:10Z' => '"2021-02-25T15:02:10Z" is not a webhook timestamp in Unix seconds.',
            "1614265330\n" => '"1614265330\\n" is not a webhook timestamp in Unix seconds.',
            "\"\\\x1B[31m\u{e9}" => '"\\"\\\\\\033[31m\\303\\251" is not a webhook timestamp in Unix seconds.',
            str_repeat('9', 40) => '"'.str_repeat('9', 32).'"... is not a webhook timestamp in Unix seconds.',
        ] as $header => $message) {
            try {
                WebhookTimestamp::fromHeader($header);
                self::fail($message);
            } catch (InvalidWebhookValue $invalidWebhookValue) {
                self::assertSame($message, $invalidWebhookValue->getMessage());
            }
        }

        self::assertSame(32, InvalidWebhookValue::SHOWN_LENGTH);
        self::assertSame('"'.str_repeat('a', 32).'"', InvalidWebhookValue::shown(str_repeat('a', 32)));
    }

    public function testATimestampIsBuiltFromADate(): void
    {
        self::assertSame(1614265330, WebhookTimestamp::fromDateTime(new DateTimeImmutable('@1614265330'))->seconds);
    }

    public function testTheEpochItselfIsADate(): void
    {
        self::assertSame(0, WebhookTimestamp::fromDateTime(new DateTimeImmutable('@0'))->seconds);
    }

    public function testADateBeforeTheEpochIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook timestamp cannot precede the Unix epoch.');

        WebhookTimestamp::fromDateTime(new DateTimeImmutable('@-1'));
    }

    public function testTheToleranceBoundIsInclusiveOnBothSides(): void
    {
        $timestamp = WebhookTimestamp::fromHeader('1000');
        $tolerance = Tolerance::ofSeconds(300);

        self::assertTrue($timestamp->isWithin($tolerance, new DateTimeImmutable('@1300')));
        self::assertTrue($timestamp->isWithin($tolerance, new DateTimeImmutable('@700')));
        self::assertFalse($timestamp->isWithin($tolerance, new DateTimeImmutable('@1301')));
        self::assertFalse($timestamp->isWithin($tolerance, new DateTimeImmutable('@699')));
    }

    public function testTheSkewIsADistanceWhicheverSideTheClockFalls(): void
    {
        $timestamp = WebhookTimestamp::fromHeader('1000');

        self::assertSame(40, $timestamp->skewFrom(new DateTimeImmutable('@1040')));
        self::assertSame(40, $timestamp->skewFrom(new DateTimeImmutable('@960')));
    }
}
